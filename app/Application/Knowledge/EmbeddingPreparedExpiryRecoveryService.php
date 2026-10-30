<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchCoordinator;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use support\Log;
use think\facade\Db;

/** Closes only expired, provably unsent Embedding dispatches under their original ledger identity. */
final class EmbeddingPreparedExpiryRecoveryService
{
    public function run(int $limit = 10): int
    {
        $limit = min(20, max(1, $limit));
        $rows = Db::connect('pgsql')->query(
            "SELECT DISTINCT d.invocation_id
               FROM sand_ai_budget_reservation r
               JOIN sand_ai_invocation i ON i.id=r.invocation_id
               JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
              WHERE d.execution_scope='embedding'
                AND d.state='prepared' AND d.dispatch_start_time IS NULL
                AND i.state='running' AND i.delete_time IS NULL
                AND r.delete_time IS NULL
                AND ((r.state='reserved' AND r.expires_at<=CURRENT_TIMESTAMP)
                  OR (r.state='held' AND r.reason='reservation_expired'))
              ORDER BY d.invocation_id LIMIT " . $limit,
        );
        $recovered = 0;
        foreach ($rows as $row) {
            $invocationId = (int) $row['invocation_id'];
            try {
                if ($this->recoverInvocation($invocationId)) $recovered++;
            } catch (\Throwable $failure) {
                Log::warning('SandAI expired embedding reservation recovery failed', [
                    'invocation_id' => $invocationId,
                    'exception_type' => $failure::class,
                ]);
            }
        }
        return $recovered;
    }

    public function recoverInvocation(int $invocationId): bool
    {
        if ($invocationId < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Embedding recovery identity is invalid');
        }
        $invocation = Invocation::where('id', $invocationId)->find();
        $dispatches = InvocationDispatch::where('invocation_id', $invocationId)->select();
        if ($invocation === null || count($dispatches) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Embedding recovery has no unique original invocation');
        }
        $dispatch = $dispatches[0];
        $sourceKind = (string) $dispatch->source_kind;
        $action = (string) $dispatch->service_action;
        $requestId = (string) $invocation->request_id;
        $query = $sourceKind === 'knowledge_query' && $action === 'sand_ai.knowledge.query'
            && str_starts_with($requestId, 'knowledge-query-embedding:')
            && (int) $invocation->knowledge_publication_id > 0
            && (int) $invocation->knowledge_base_version_id > 0
            && is_string($invocation->knowledge_source_scope_sha256)
            && preg_match('/\A[a-f0-9]{64}\z/D', $invocation->knowledge_source_scope_sha256) === 1
            && (string) $dispatch->lease_owner === 'knowledge-query:' . (int) $invocation->knowledge_publication_id;
        $build = $sourceKind === 'knowledge_index' && $action === 'sand_ai.knowledge.index'
            && str_starts_with($requestId, 'knowledge-embedding:')
            && $invocation->knowledge_publication_id === null
            && $invocation->knowledge_base_version_id === null
            && preg_match('/\Aembedding:[1-9][0-9]*\z/D', (string) $dispatch->lease_owner) === 1;
        if (!$query && !$build) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Embedding recovery is outside its original source');
        }
        $scope = new DispatchExecutionScope(
            'embedding',
            (string) $dispatch->logical_execution_id,
            (string) $dispatch->execution_snapshot_sha256,
            null, null, null,
            (int) $dispatch->attempt_no,
            (string) $dispatch->lease_owner,
            (string) $dispatch->lease_token,
            (int) $dispatch->lease_fence,
        );
        $attribution = new UsageAttribution(
            (int) $invocation->environment_id,
            $invocation->organization_id === null ? null : (int) $invocation->organization_id,
            $invocation->application_id === null ? null : (int) $invocation->application_id,
            $invocation->workload_client_id === null ? null : (int) $invocation->workload_client_id,
            [], $sourceKind, $action, 'embedding',
        );
        return (new DispatchCoordinator())->rejectExpiredPreparedForScope(
            $invocation, $scope, $attribution,
        );
    }
}
