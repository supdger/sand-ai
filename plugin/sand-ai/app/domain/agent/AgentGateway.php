<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\agent;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\domain\gateway\ChatGateway;
use plugin\SandAi\app\domain\retrieval\RetrievalGateway;
use plugin\SandAi\app\domain\task\TaskGateway;
use plugin\SandAi\app\domain\task\TaskState;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\TaskRun;
use plugin\SandAi\app\model\TaskStep;
use think\facade\Db;

/** Read-only tool agent; it cannot initiate business approval or mutation. */
final class AgentGateway
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(IdentityContext $context, array $input, string $requestId): array
    {
        $input = AgentPolicy::normalizeInput($input);
        $context->requireAction('sand_ai.agent.run');
        foreach ($input['tools'] as $tool) {
            if ($tool === 'retrieval.search') {
                $context->requireAction('sand_ai.retrieval.search');
            }
        }
        return Db::connect('pgsql')->transaction(function () use ($context, $input, $requestId): array {
            $existing = TaskRun::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->environment_id !== $context->environmentId || (string) $existing->task_type !== 'agent_run') {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                }
                return ['idempotent_replay' => true, 'task' => (new TaskGateway())->summary($existing)];
            }
            $task = TaskRun::create([
                'environment_id' => $context->environmentId,
                'task_type' => 'agent_run', 'resource_type' => 'agent_run', 'resource_id' => 0,
                'request_id' => $requestId, 'state' => TaskState::QUEUED, 'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('plugin.sand-ai.task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'), 'input' => $input, 'status' => 1,
            ]);
            $task->save(['resource_id' => (int) $task->id]);
            TaskStep::create([
                'task_id' => (int) $task->id, 'step_code' => 'agent_run', 'sequence_no' => 1,
                'state' => TaskState::QUEUED, 'attempt_count' => 0,
                'context' => ['tool_count' => count($input['tools']), 'review_required' => $input['review_required']], 'status' => 1,
            ]);
            $this->audit($context, 'agent.queued', (int) $task->id, 'Queued SandAI agent task', ['tools' => $input['tools'], 'review_required' => $input['review_required']]);
            return ['idempotent_replay' => false, 'task' => (new TaskGateway())->summary($task)];
        });
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The agent task payload is invalid');
        }
        $input = AgentPolicy::normalizeInput($input);
        if (!in_array('retrieval.search', $input['tools'], true)) {
            throw new ApiProblem('SAND_AI_AGENT_TOOL_FORBIDDEN', 'The agent requires the retrieval.search tool');
        }
        $context = new IdentityContext('system', 'system', (int) $task->environment_id, 'system_worker', 'internal', ['sand_ai.retrieval.search']);
        $retrieval = (new RetrievalGateway())->search($context, ['query' => $input['query'], 'limit' => 8]);
        $sources = $retrieval['matches'];
        $sourceContext = array_map(static fn (array $source): string => sprintf('[Source block %d, file %d] %s', $source['source_block_id'], $source['file_id'], $source['excerpt']), $sources);
        $messages = [
            ['role' => 'system', 'content' => $input['instructions'] . "\nUse only supplied source excerpts for factual claims and cite source block ids."],
            ['role' => 'user', 'content' => $input['query']],
        ];
        if ($sourceContext !== []) {
            $messages[] = ['role' => 'system', 'content' => implode("\n\n", $sourceContext)];
        }
        $response = (new ChatGateway())->complete((int) $task->environment_id, $input['model'], $messages, sprintf('agent_%d_attempt_%d', (int) $task->id, max(1, (int) $task->attempt_count)), 'system_worker');
        if (!is_string($response['content'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider did not return agent output', true);
        }
        return [
            'output' => ['content' => $response['content']], 'invocation' => $response['invocation'] ?? null,
            'tool_calls' => [['tool' => 'retrieval.search', 'source_block_ids' => array_values(array_map(static fn (array $source): int => (int) $source['source_block_id'], $sources))]],
            'source_references' => array_map(static fn (array $source): array => [
                'source_block_id' => $source['source_block_id'], 'file_id' => $source['file_id'],
                'locator_type' => $source['locator_type'], 'locator' => $source['locator'],
            ], $sources),
            'review_required' => $input['review_required'],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(IdentityContext $context, string $action, int $taskId, string $summary, array $metadata): void
    {
        AuditLog::create([
            'actor_type' => 'workload_client', 'actor_ref' => $context->workloadClientId,
            'action' => $action, 'resource_type' => 'task', 'resource_id' => $taskId,
            'summary' => $summary, 'context' => $metadata,
        ]);
    }
}
