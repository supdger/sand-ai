<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Application\Trial\AdminTrialIamGateway;
use app\Application\Usage\UsageEventQuery;
use app\Application\Usage\UsageSummaryAssociationSql;
use app\Application\Usage\ResourceUsageAssociationSql;
use plugin\SandIam\app\model\WorkloadClient;

/** Administrator list sourced from immutable, attributed usage events. */
final class UsageLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input, mixed $adminInfo): array
    {
        $policy = new UsageEventListPolicy();
        $filters = $policy->normalize($input);
        // The trial marker resolves against SandIAM managed bindings here in
        // the plugin layer; the core usage query never reads SandIAM tables.
        $trialFilter = $filters['trial'] ?? null;
        unset($filters['trial']);
        $managedClientIds = $this->managedTrialClientIds();
        $scope = (new UsageAdminScopeLogic())->resolve($adminInfo, $filters);
        $query = (new UsageEventQuery())->filtered($filters, $scope)->order('usage_event.create_time', 'desc')
            ->order('usage_event.id', 'desc')
            ->field('usage_event.id,usage_event.invocation_id,usage_event.tool_run_id,usage_event.organization_id,usage_event.application_id,usage_event.environment_id,usage_event.workload_client_id,usage_event.provider_id,usage_event.model_id,usage_event.skill_id,usage_event.skill_version_id,usage_event.service_action,usage_event.source_kind,usage_event.capability_code,usage_event.metering_unit,usage_event.metering_quantity,usage_event.input_tokens,usage_event.output_tokens,usage_event.total_tokens,usage_event.usage_state,usage_event.price_revision_id,usage_event.metered_amount,usage_event.currency,usage_event.pricing_state,usage_event.create_time')
            ->fieldRaw('CASE WHEN ' . UsageSummaryAssociationSql::workflow() . ' THEN workflow_run.id ELSE NULL END AS proved_workflow_run_id')
            ->fieldRaw('(' . \app\Application\Usage\CompositionUsageAssociationSql::runId() . ') AS proved_composition_run_id')
            ->fieldRaw('(' . \app\Application\Usage\CompositionUsageAssociationSql::nodeKey() . ') AS proved_composition_node_key')
            ->fieldRaw('(' . ResourceUsageAssociationSql::agentRunId() . ') AS proved_agent_run_id')
            ->fieldRaw('(' . UsageSummaryAssociationSql::knowledgeBase() . ') AS proved_knowledge_base_id');
        foreach (ResourceUsageAssociationSql::projections() as $field => $expression) {
            $query->fieldRaw('(' . $expression . ') AS proved_' . $field);
        }
        if ($trialFilter === 'only') {
            // An empty managed set can never match; keep the query valid.
            $query->whereIn('usage_event.workload_client_id', $managedClientIds === [] ? [0] : $managedClientIds);
        } elseif ($trialFilter === 'exclude' && $managedClientIds !== []) {
            $query->whereNotIn('usage_event.workload_client_id', $managedClientIds);
        }
        $result = $query->paginate(['page' => $filters['page'], 'list_rows' => $filters['limit']])->toArray();
        $result['data'] = array_map(
            static function (array $row) use ($policy, $managedClientIds): array {
                $trial = in_array((int) ($row['workload_client_id'] ?? 0), $managedClientIds, true);
                unset($row['workload_client_id']);
                $presented = $policy->present($row);
                $presented['trial'] = $trial;
                return $presented;
            },
            $result['data'],
        );
        return $result;
    }

    /**
     * Ids of SandIAM workload clients bound for administrator trial
     * (`code='managed'`, audience `sand-ai`). Empty when the SandIAM plugin is
     * absent, which simply means no usage row can be a trial call.
     *
     * @return list<int>
     */
    private function managedTrialClientIds(): array
    {
        if (!class_exists(WorkloadClient::class)) {
            return [];
        }
        $ids = WorkloadClient::where('code', AdminTrialIamGateway::MANAGED_CLIENT_CODE)
            ->where('audience', AdminTrialIamGateway::AUDIENCE)
            ->where('status', 1)
            ->column('id');
        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }
}
