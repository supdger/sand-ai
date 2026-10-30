<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageGateway;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Reads every node's existing usage and keeps unknown totals unknown. */
final class MeetingSummaryCostProjection
{
    private const PAGE_RECORDS = 32;
    private const DISPATCH_FIELDS = 'id,invocation_id,environment_id,parent_task_run_id,parent_task_step_id,workflow_node_code,execution_scope,state,attempt_no,logical_request_id';

    /**
     * HTTP task reads have no execution lease and omit heartbeat. Only an
     * execution caller with its own valid lease may supply a renewal callback.
     *
     * @param \Closure():void|null $heartbeat
     * @return array{calls:list<array<string,mixed>>,totals:list<array<string,mixed>>}
     */
    public function present(TaskRun $task, ?\Closure $heartbeat = null): array
    {
        $lastHeartbeat = 0;
        $pulse = static function (bool $force = false) use ($heartbeat, &$lastHeartbeat): void {
            if ($heartbeat === null) return;
            $now = hrtime(true);
            if ($force || $now - $lastHeartbeat >= 10000000000) {
                $heartbeat();
                $lastHeartbeat = $now;
            }
        };
        $pulse(true);
        $taskId = (int) $task->id;
        $nodeCount = $this->nodeCount($taskId, $pulse);
        if ($nodeCount === 0 && (string) $task->state === TaskState::SUCCEEDED) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting summary nodes are unavailable');
        }
        $byStep = [];
        $completed = [];
        $nodeHash = hash_init('sha256');
        foreach ($this->nodePages($taskId, $nodeCount, $pulse) as $nodes) {
            foreach ($nodes as $node) {
                $pulse();
                $identity = $this->nodeIdentity($node);
                hash_update($nodeHash, RequestPayloadFingerprint::fingerprint($identity, []));
                $stepId = $identity['task_step_id'];
                $stepState = $identity['step_state'];
                $stepAttempt = $identity['attempt_count'];
                if (isset($byStep[$stepId]) || $identity['step_id'] === null
                    || $identity['task_id'] === null || $identity['step_code'] !== $identity['node_code']
                    || $identity['dispatch_scope'] !== 'ai_task'
                    || ((string) $task->state === TaskState::SUCCEEDED && $stepState !== TaskState::SUCCEEDED)) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting node usage identity is unavailable');
                }
                // Keep a scalar identity directory, never node bodies or ORM
                // models. Complete calls are necessarily retained by the DTO.
                $byStep[$stepId] = $identity['node_code'];
                if ($stepState === TaskState::SUCCEEDED) {
                    $invocationId = $identity['last_invocation_id'];
                    if ($invocationId < 1 || isset($completed[$invocationId]) || $stepAttempt < 1
                        || ($identity['content_purged'] === 0 && $identity['has_result'] === 0)) {
                        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting node usage is incomplete');
                    }
                    $completed[$invocationId] = ['step_id' => $stepId, 'attempt_no' => $stepAttempt];
                }
            }
            unset($nodes, $node, $identity);
        }
        $nodeSha256 = hash_final($nodeHash);
        // Anchor membership between the directory sweeps. xmin hashes in the
        // second sweep detect committed edits, including A -> B -> A changes.
        if ($this->nodeCount($taskId, $pulse) !== $nodeCount) $this->changed();
        $dispatchCount = $this->dispatchCount($taskId, $pulse);
        $calls = [];
        $totals = [];
        $seen = [];
        $dispatchHash = hash_init('sha256');
        $usage = new UsageGateway();
        foreach ($this->dispatchPages($taskId, $dispatchCount, $pulse) as $dispatches) {
            foreach ($dispatches as $dispatch) {
                $pulse();
                hash_update($dispatchHash, $this->dispatchIdentity($dispatch));
                $stepId = (int) $dispatch->parent_task_step_id;
                $invocationId = (int) $dispatch->invocation_id;
                if (!isset($byStep[$stepId]) || (string) $dispatch->workflow_node_code !== $byStep[$stepId]
                    || (int) $dispatch->environment_id !== (int) $task->environment_id
                    || $invocationId < 1 || (int) $dispatch->attempt_no < 1 || isset($seen[$invocationId])) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting dispatch has no node');
                }
                $seen[$invocationId] = true;
                $sealed = $completed[$invocationId] ?? null;
                if ($sealed !== null && ($sealed['step_id'] !== $stepId
                    || $sealed['attempt_no'] !== (int) $dispatch->attempt_no || (string) $dispatch->state !== 'succeeded')) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Completed meeting dispatch changed');
                }
            }
            $costs = $usage->invocationCostsForDispatches(
                (int) $task->environment_id, $dispatches->all(),
                static function (Invocation $invocation, InvocationDispatch $dispatch) use ($task, $byStep): void {
                    $stepId = (int) $dispatch->parent_task_step_id;
                    $requestId = sprintf('task_%d_%s_attempt_%d', (int) $task->id, $byStep[$stepId], (int) $dispatch->attempt_no);
                    if ((string) $invocation->request_id !== $requestId
                        || !hash_equals((string) $dispatch->logical_request_id, hash('sha256', $requestId))
                        || (int) $invocation->id !== (int) $dispatch->invocation_id
                        || (int) $dispatch->parent_task_run_id !== (int) $task->id
                        || (string) $dispatch->execution_scope !== 'ai_task'
                        || (string) $dispatch->workflow_node_code !== $byStep[$stepId]
                        || (int) $invocation->environment_id !== (int) $task->environment_id
                        || (int) $invocation->organization_id !== (int) $task->organization_id
                        || (int) $invocation->application_id !== (int) $task->application_id
                        || (int) $invocation->workload_client_id !== (int) $task->workload_client_id) {
                        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting node usage changed');
                    }
                }, $pulse,
            );
            foreach ($dispatches as $dispatch) {
                $pulse();
                $invocationId = (int) $dispatch->invocation_id;
                $cost = $costs[$invocationId];
                if (isset($completed[$invocationId]) && !in_array($cost['usage']['state'] ?? null, ['measured', 'unmeasured'], true)) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Completed meeting usage event is unavailable');
                }
                $call = [
                    'node_code' => $byStep[(int) $dispatch->parent_task_step_id],
                    'invocation_id' => $invocationId,
                    'input_tokens' => $cost['tokens']['input_tokens'],
                    'output_tokens' => $cost['tokens']['output_tokens'],
                    'total_tokens' => $cost['tokens']['total_tokens'],
                    'price_revision_id' => $cost['price']['revision_id'],
                    'metered_amount' => $cost['price']['metered_amount'],
                    'currency' => $cost['price']['currency'],
                    'pricing_state' => $cost['price']['pricing_state'],
                ];
                $calls[] = $call;
                $key = $call['currency'] ?? '_unknown_';
                if (!isset($totals[$key])) {
                    $totals[$key] = [
                        'currency' => $call['currency'], 'input_tokens' => 0,
                        'output_tokens' => 0, 'total_tokens' => 0, 'metered_amount' => '0.000000',
                    ];
                }
                foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
                    $totals[$key][$field] = $totals[$key][$field] === null || !is_int($call[$field])
                        ? null : $totals[$key][$field] + $call[$field];
                }
                $amount = $call['metered_amount'];
                $totals[$key]['metered_amount'] = $totals[$key]['metered_amount'] === null
                    || !is_string($amount) || $call['currency'] === null
                        ? null : BudgetDecimal::add($totals[$key]['metered_amount'], BudgetDecimal::normalize($amount, false));
            }
            unset($dispatches, $dispatch, $costs, $cost, $call);
        }
        $dispatchSha256 = hash_final($dispatchHash);
        foreach ($completed as $invocationId => $sealed) {
            $pulse();
            if (!isset($seen[$invocationId])) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Completed meeting call is absent from the cost projection');
            }
        }
        if ($this->dispatchCount($taskId, $pulse) !== $dispatchCount) $this->changed();
        $hash = hash_init('sha256');
        foreach ($this->dispatchPages($taskId, $dispatchCount, $pulse, false) as $dispatches) {
            foreach ($dispatches as $dispatch) {
                $pulse();
                hash_update($hash, $this->dispatchIdentity($dispatch));
            }
            unset($dispatches, $dispatch);
        }
        if (!hash_equals($dispatchSha256, hash_final($hash))
            || $this->dispatchCount($taskId, $pulse) !== $dispatchCount) $this->changed();
        $hash = hash_init('sha256');
        foreach ($this->nodePages($taskId, $nodeCount, $pulse) as $nodes) {
            foreach ($nodes as $node) {
                $pulse();
                hash_update($hash, RequestPayloadFingerprint::fingerprint($this->nodeIdentity($node), []));
            }
            unset($nodes, $node);
        }
        if (!hash_equals($nodeSha256, hash_final($hash))
            || $this->nodeCount($taskId, $pulse) !== $nodeCount) $this->changed();
        $pulse(true);
        return ['calls' => $calls, 'totals' => array_values($totals)];
    }

    /** @param \Closure(bool=):void $pulse @return \Generator<int,\think\Collection> */
    private function nodePages(int $taskId, int $expected, \Closure $pulse): \Generator
    {
        $afterId = 0;
        $count = 0;
        do {
            $pulse();
            // Finish each SELECT before a heartbeat can use the pooled connection.
            $page = MeetingSummaryNode::alias('node')
                ->leftJoin('sand_ai_task_step step', 'step.id = node.task_step_id AND step.task_id = node.task_id AND step.delete_time IS NULL')
                ->leftJoin('sand_ai_task_run cost_task', 'cost_task.id = node.task_id AND cost_task.delete_time IS NULL')
                ->field('node.id,node.task_step_id,node.node_code,node.dispatch_scope,node.last_invocation_id')
                ->fieldRaw('step.id AS cost_task_step_id, step.step_code AS cost_step_code, step.state AS cost_step_state, step.attempt_count AS cost_attempt_count, node.xmin::text AS cost_node_revision, step.xmin::text AS cost_step_revision')
                ->fieldRaw('cost_task.id AS cost_task_id, CASE WHEN cost_task.content_purged_time IS NULL THEN 0 ELSE 1 END AS cost_content_purged, CASE WHEN node.result_snapshot IS NULL THEN 0 ELSE 1 END AS cost_has_result')
                ->where('node.task_id', $taskId)->where('node.id', '>', $afterId)
                ->order('node.id')->limit(min(self::PAGE_RECORDS, $expected + 1 - $count))->select();
            $pulse();
            if ($page->isEmpty()) break;
            foreach ($page as $node) {
                $id = (int) $node->id;
                if ($id <= $afterId || ++$count > $expected) $this->changed();
                $afterId = $id;
            }
            yield $page;
            unset($page, $node);
        } while ($count <= $expected);
        if ($count !== $expected) $this->changed();
    }

    /** @param \Closure(bool=):void $pulse @return \Generator<int,\think\Collection> */
    private function dispatchPages(int $taskId, int $expected, \Closure $pulse, bool $withSnapshots = true): \Generator
    {
        $afterId = 0;
        $count = 0;
        do {
            $pulse();
            $fields = self::DISPATCH_FIELDS;
            if ($withSnapshots) $fields .= ',route_snapshot,price_snapshot,deployment_id,capability_code,service_action,source_kind';
            $page = InvocationDispatch::where('parent_task_run_id', $taskId)->where('execution_scope', 'ai_task')
                ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])
                ->field($fields)->fieldRaw('xmin::text AS cost_dispatch_revision')->where('id', '>', $afterId)
                ->order('id')->limit(min(self::PAGE_RECORDS, $expected + 1 - $count))->select();
            $pulse();
            if ($page->isEmpty()) break;
            foreach ($page as $dispatch) {
                $id = (int) $dispatch->id;
                if ($id <= $afterId || ++$count > $expected) $this->changed();
                $afterId = $id;
            }
            yield $page;
            unset($page, $dispatch);
        } while ($count <= $expected);
        if ($count !== $expected) $this->changed();
    }

    /** @return array<string,mixed> */
    private function nodeIdentity(MeetingSummaryNode $node): array
    {
        return [
            'id' => (int) $node->id, 'task_step_id' => (int) $node->task_step_id,
            'node_code' => (string) $node->node_code, 'dispatch_scope' => (string) $node->dispatch_scope,
            'last_invocation_id' => (int) $node->last_invocation_id,
            'step_id' => $node->getAttr('cost_task_step_id'), 'task_id' => $node->getAttr('cost_task_id'),
            'step_code' => (string) $node->getAttr('cost_step_code'), 'step_state' => (string) $node->getAttr('cost_step_state'),
            'attempt_count' => (int) $node->getAttr('cost_attempt_count'),
            'content_purged' => (int) $node->getAttr('cost_content_purged'), 'has_result' => (int) $node->getAttr('cost_has_result'),
            'node_revision' => (string) $node->getAttr('cost_node_revision'), 'step_revision' => (string) $node->getAttr('cost_step_revision'),
        ];
    }

    private function dispatchIdentity(InvocationDispatch $dispatch): string
    {
        $identity = [];
        foreach (explode(',', self::DISPATCH_FIELDS) as $field) $identity[$field] = $dispatch->getAttr($field);
        $identity['revision'] = (string) $dispatch->getAttr('cost_dispatch_revision');
        return RequestPayloadFingerprint::fingerprint($identity, []);
    }

    /** @param \Closure(bool=):void $pulse */
    private function nodeCount(int $taskId, \Closure $pulse): int
    {
        $pulse();
        $count = (int) MeetingSummaryNode::where('task_id', $taskId)->count();
        $pulse();
        return $count;
    }

    /** @param \Closure(bool=):void $pulse */
    private function dispatchCount(int $taskId, \Closure $pulse): int
    {
        $pulse();
        $count = (int) InvocationDispatch::where('parent_task_run_id', $taskId)->where('execution_scope', 'ai_task')
            ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])->count();
        $pulse();
        return $count;
    }

    private function changed(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting cost projection changed while reading');
    }
}
