<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\task;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\domain\gateway\ChatGateway;
use plugin\SandAi\app\model\AiFile;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\SourceBlock;
use plugin\SandAi\app\model\TaskRun;
use plugin\SandAi\app\model\TaskStep;
use think\facade\Db;

final class AiTaskGateway
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(IdentityContext $context, array $input, string $requestId): array
    {
        $input = AiTaskPolicy::normalizeInput($input);
        $this->sourceBlocks($context->environmentId, $input['source_block_ids']);

        return Db::connect('pgsql')->transaction(function () use ($context, $input, $requestId): array {
            $existing = TaskRun::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->environment_id !== $context->environmentId || (string) $existing->task_type !== 'ai_completion') {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                }
                return ['idempotent_replay' => true, 'task' => (new TaskGateway())->summary($existing)];
            }
            $task = TaskRun::create([
                'environment_id' => $context->environmentId,
                'task_type' => 'ai_completion',
                'resource_type' => 'ai_task',
                'resource_id' => 0,
                'request_id' => $requestId,
                'state' => TaskState::QUEUED,
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('plugin.sand-ai.task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => $input,
                'status' => 1,
            ]);
            $task->save(['resource_id' => (int) $task->id]);
            TaskStep::create([
                'task_id' => (int) $task->id,
                'step_code' => 'invoke_model',
                'sequence_no' => 1,
                'state' => TaskState::QUEUED,
                'attempt_count' => 0,
                'context' => ['model' => $input['model'], 'source_block_count' => count($input['source_block_ids'])],
                'status' => 1,
            ]);
            $this->audit($context, 'ai_task.queued', (int) $task->id, 'Queued SandAI AI completion task', [
                'model' => $input['model'], 'source_block_count' => count($input['source_block_ids']), 'message_count' => count($input['messages']),
            ]);
            return ['idempotent_replay' => false, 'task' => (new TaskGateway())->summary($task)];
        });
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The AI task payload is invalid');
        }
        $input = AiTaskPolicy::normalizeInput($input);
        $sources = $this->sourceBlocks((int) $task->environment_id, $input['source_block_ids']);
        $messages = $input['messages'];
        if ($sources !== []) {
            $sourceText = array_map(static fn (array $source): string => sprintf('[Source block %d, file %d] %s', $source['id'], $source['file_id'], $source['content']), $sources);
            $messages[] = ['role' => 'system', 'content' => "Use the following controlled source blocks when relevant. Cite their block ids in your answer.\n" . implode("\n\n", $sourceText)];
        }
        // Worker invocation is attributable to its originating workload only in
        // task input/audit metadata; no credential is retained or revalidated here.
        $response = (new ChatGateway())->complete(
            (int) $task->environment_id,
            $input['model'],
            $messages,
            sprintf('task_%d_attempt_%d', (int) $task->id, max(1, (int) $task->attempt_count)),
            'system_worker',
        );
        if (!is_string($response['content'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider did not return task output', true);
        }

        return [
            'output' => ['content' => $response['content']],
            'invocation' => $response['invocation'] ?? null,
            'source_references' => array_map(static fn (array $source): array => [
                'source_block_id' => $source['id'], 'file_id' => $source['file_id'],
                'locator_type' => $source['locator_type'], 'locator' => $source['locator'],
            ], $sources),
        ];
    }

    /** @param list<int> $sourceBlockIds @return list<array{id: int, file_id: int, locator_type: string, locator: array, content: string}> */
    private function sourceBlocks(int $environmentId, array $sourceBlockIds): array
    {
        $sources = [];
        foreach ($sourceBlockIds as $sourceBlockId) {
            $block = SourceBlock::where('id', $sourceBlockId)->where('status', 1)->find();
            if ($block === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Source block not found');
            }
            $file = AiFile::where('id', (int) $block->file_id)->where('environment_id', $environmentId)->where('status', 1)->find();
            if ($file === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Source block is not available to this environment');
            }
            $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
            $sources[] = [
                'id' => (int) $block->id, 'file_id' => (int) $block->file_id,
                'locator_type' => (string) $block->locator_type,
                'locator' => is_array($locator) ? $locator : [], 'content' => (string) $block->content,
            ];
        }
        return $sources;
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
