<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use think\facade\Db;

/** Historical costs remain readable without recovering or dispatching children. */
final class CompositionCostGateway
{
    public function read(IdentityContext $context, int $runId, \Closure $revalidate): array
    {
        $scope = [$context->environmentId, $context->organizationId, $context->applicationId, $context->workloadClientId];
        $run = $this->owned($scope, $runId);
        $nodes = $this->nodes($runId);
        $revalidate();
        $organizationId = $this->id($context->organizationId);
        $applicationId = $this->id($context->applicationId);
        $filters = (new UsageSummaryPolicy())->normalize(['environment_id' => $context->environmentId]);
        // The owned run and immutable child association bound this cohort.
        // Parent database wall-clock timestamps cannot bound UTC usage events.
        $filters['from_time'] = null;
        $filters['to_time'] = null;
        $filters['composition_run_id'] = $runId;
        $result = (new UsageSummaryGateway())->compositionBreakdown($filters, [[
            'id' => $context->environmentId, 'organization_id' => $organizationId, 'application_id' => $applicationId,
        ]]);
        $fresh = $this->owned($scope, $runId);
        $freshNodes = $this->nodes($runId);
        // A concurrently advanced child cannot turn an earlier subtotal into
        // a complete result. Keep the response bound to one unchanged view.
        if ($run !== $fresh || $nodes !== $freshNodes) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '运行正在更新，请只读刷新原运行费用');
        }
        $public = [];
        $complete = $run['state'] === 'succeeded' && !$result['nodes']['truncated'] && !$result['overall']['truncated'];
        foreach ($nodes as $node) {
            $key = $runId . ':' . $node['node_code'];
            $rows = array_values(array_filter($result['nodes']['rows'], static fn (array $row): bool => $row['value'] === $key));
            // Absence of usage alone proves nothing. Only a sealed execution
            // witness can complete a keyword node without inventing an event.
            $notBillable = $rows === [] && !$result['nodes']['truncated']
                && CompositionKeywordCostProof::verified($run, $node);
            $nodeComplete = $node['state'] === 'succeeded' && ($rows !== [] || $notBillable) && !$result['nodes']['truncated'];
            $complete = $complete && $nodeComplete;
            $public[] = ['node_code' => (string) $node['node_code'], 'state' => (string) $node['state'],
                'coverage' => $nodeComplete ? 'complete' : 'pending', 'rows' => $rows,
                'charge' => $notBillable
                    ? ['state' => 'not_billable', 'basis' => 'server_keyword_execution', 'metered_amount' => '0', 'currency' => null]
                    : ['state' => $rows === [] ? 'pending' : 'usage_events']];
        }
        $revalidate();
        return ['run_id' => $runId, 'basis' => 'immutable_child_usage_events', 'parent_charge' => false,
            'coverage' => $complete ? 'complete' : 'pending', 'overall' => $result['overall'], 'nodes' => $public];
    }

    private function owned(array $scope, int $id): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id,state,create_time,update_time,snapshot_sha256,version_id,environment_id,organization_id,application_id,workload_client_id FROM sand_ai_composition_run
             WHERE id=? AND environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?',
            [$id, ...$scope],
        );
        if (count($rows) !== 1) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '原工作流费用不存在或不属于当前应用身份');
        return $rows[0];
    }

    private function nodes(int $runId): array
    {
        return Db::connect('pgsql')->query(
            'SELECT id,node_code,state,update_time,request_key,child,input_sha256,output_sha256 FROM sand_ai_composition_node WHERE run_id=? ORDER BY id', [$runId],
        );
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '当前身份的费用归属无法确认');
        return (int) $id;
    }
}
