<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeQueryEmbeddingGateway;
use app\Application\Usage\UsageGateway;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\UsageEvent;
use think\facade\Db;

/** Task-local read model for a billed retrieval child, including failed answers. */
final class AgentEmbeddingCostProjection
{
    /** @return array<string,mixed> */
    public function present(TaskRun $task): array
    {
        $legacy = AgentGateway::embeddingChildKey((int) $task->id);
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || ($input['_agent_neuron'] ?? false) !== true) return $this->presentChild($task, $legacy);
        $rows = TaskStep::where('task_id', (int) $task->id)->whereIn('step_code', ['agent_retrieval_1', 'agent_retrieval_2'])->order('sequence_no')->select();
        if (count($rows) > 2) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent retrieval coverage is invalid');
        $binding = $input['_agent_definition'] ?? null;
        $publicationId = is_array($binding) ? ($binding['knowledge_publication_id'] ?? null) : null;
        $children = [];
        foreach ($rows as $row) {
            $context = is_object($row->context) ? (array) $row->context : $row->context;
            $hash = is_array($context) ? ($context['query_sha256'] ?? null) : null;
            if (!is_int($publicationId) || $publicationId < 1 || !is_string($input['query'] ?? null)
                || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent retrieval trace is invalid');
            }
            $childKey = $legacy . (hash_equals(hash('sha256', $input['query']), $hash) ? '' : ':' . substr($hash, 0, 32));
            $requestId = KnowledgeQueryEmbeddingGateway::childRequestId((int) $task->environment_id, $publicationId, $childKey);
            if (($context['child_request_id'] ?? null) !== $requestId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent retrieval trace changed');
            }
            $child = $this->presentChild($task, $childKey);
            $children[] = ['request_id' => $requestId, 'invocation' => $child['embedding_invocation'],
                'usage_cost' => $child['embedding_usage_cost']];
        }
        return $this->presentChild($task, $legacy) + ['embedding_invocations' => $children];
    }

    /** @return array{embedding_invocation:?array,embedding_usage_cost:?array} */
    private function presentChild(TaskRun $task, string $childKey): array
    {
        $empty = ['embedding_invocation' => null, 'embedding_usage_cost' => null];
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $binding = is_array($input) ? ($input['_agent_definition'] ?? null) : null;
        $publicationId = is_array($binding) ? ($binding['knowledge_publication_id'] ?? null) : null;
        if ((string) $task->task_type !== 'agent_run' || !is_int($publicationId) || $publicationId < 1) {
            return $empty;
        }
        $requestId = KnowledgeQueryEmbeddingGateway::childRequestId(
            (int) $task->environment_id, $publicationId,
            $childKey,
        );
        $invocation = Invocation::where('request_id', $requestId)->find();
        if ($invocation === null) return $empty;
        $this->assertChild($task, $binding, $invocation);
        $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($dispatch === null && (string) $invocation->state === 'failed') {
            $rejection = Db::connect('pgsql')->query(
                "SELECT
                    (SELECT count(*) FROM sand_ai_budget_event
                     WHERE invocation_id=? AND event_type='reject' AND reason=?) AS rejects,
                    (SELECT count(*) FROM sand_ai_budget_reservation WHERE invocation_id=?) AS reservations,
                    (SELECT count(*) FROM sand_ai_usage_event WHERE invocation_id=?) AS usage_events,
                    (SELECT count(*) FROM sand_ai_invocation_outcome_receipt WHERE invocation_id=?) AS receipts",
                [(int) $invocation->id, $invocation->error_code, (int) $invocation->id,
                    (int) $invocation->id, (int) $invocation->id],
            );
            if (count($rejection) !== 1 || (int) $rejection[0]['rejects'] !== 1
                || (int) $rejection[0]['reservations'] !== 0
                || (int) $rejection[0]['usage_events'] !== 0
                || (int) $rejection[0]['receipts'] !== 0) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent retrieval rejection evidence is invalid');
            }
            return [
                'embedding_invocation' => [
                    'state' => 'not_dispatched',
                    'error_code' => is_string($invocation->error_code) ? $invocation->error_code : null,
                ],
                'embedding_usage_cost' => null,
            ];
        }
        if ($dispatch === null || (string) $dispatch->execution_scope !== 'embedding'
            || (string) $dispatch->source_kind !== 'knowledge_query'
            || (string) $dispatch->service_action !== 'sand_ai.knowledge.query'
            || (string) $dispatch->capability_code !== 'embedding'
            || (int) $dispatch->environment_id !== (int) $task->environment_id) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent retrieval dispatch changed');
        }
        $summary = [
            'state' => (string) $dispatch->state,
            'error_code' => is_string($dispatch->error_code) ? $dispatch->error_code : null,
        ];
        if (UsageEvent::where('invocation_id', (int) $invocation->id)->find() === null) {
            return ['embedding_invocation' => $summary, 'embedding_usage_cost' => null];
        }
        $cost = (new UsageGateway())->invocationCost(
            (int) $task->environment_id, $requestId,
            function (Invocation $read) use ($task, $binding, $invocation): void {
                if ((int) $read->id !== (int) $invocation->id) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent retrieval invocation changed');
                }
                $this->assertChild($task, $binding, $read);
            },
        );
        return [
            'embedding_invocation' => $summary,
            'embedding_usage_cost' => [
                'usage_state' => $cost['usage']['state'],
                'input_tokens' => $cost['tokens']['input_tokens'],
                'output_tokens' => $cost['tokens']['output_tokens'],
                'total_tokens' => $cost['tokens']['total_tokens'],
                'pricing_state' => $cost['price']['pricing_state'],
                'metered_amount' => $cost['price']['metered_amount'],
                'currency' => $cost['price']['currency'],
                'price_revision_id' => $cost['price']['revision_id'],
            ],
        ];
    }

    /** @param array<string,mixed> $binding */
    private function assertChild(TaskRun $task, array $binding, Invocation $invocation): void
    {
        if ((int) $invocation->environment_id !== (int) $task->environment_id
            || (int) $invocation->organization_id !== (int) $task->organization_id
            || (int) $invocation->application_id !== (int) $task->application_id
            || (int) $invocation->workload_client_id !== (int) $task->workload_client_id
            || (int) $invocation->knowledge_publication_id !== (int) $binding['knowledge_publication_id']
            || (int) $invocation->knowledge_base_version_id !== (int) ($binding['knowledge_version_id'] ?? 0)
            || !hash_equals(
                (string) $invocation->knowledge_source_scope_sha256,
                (string) ($binding['knowledge_scope_sha256'] ?? ''),
            )) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent retrieval attribution changed');
        }
    }
}
