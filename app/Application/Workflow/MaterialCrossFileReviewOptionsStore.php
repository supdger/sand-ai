<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Application\Gateway\InternalModelInvocationContext;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowCrossFileReview;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\facade\Db;

/** Freezes the actual adapter request options before a review can be sent. */
final class MaterialCrossFileReviewOptionsStore
{
    public function freeze(InternalModelInvocationContext $context, int $tokens, string $parameter): void
    {
        if (!$context->crossFileReview
            || $tokens < 1 || $tokens > ChatCompletionOptionsPolicy::MAX_OUTPUT_TOKENS
            || !in_array($parameter, ['max_tokens', 'max_completion_tokens'], true)) {
            self::invalid();
        }
        Db::connect('pgsql')->transaction(function () use ($context, $tokens, $parameter): void {
            $task = TaskRun::where('id', $context->taskRunId)->lock(true)->find();
            $run = WorkflowRun::where('id', $context->workflowRunId)->lock(true)->find();
            $review = WorkflowCrossFileReview::where('workflow_run_id', $context->workflowRunId)
                ->lock(true)->find();
            if ($task === null || $run === null || $review === null
                || (int) $run->task_id !== (int) $task->id
                || (string) $task->state !== TaskState::RUNNING
                || (string) $task->lease_owner !== $context->lease->owner
                || !hash_equals($context->lease->token, (string) $task->lease_token)
                || (int) $task->lease_fence !== $context->lease->fence
                || !hash_equals((string) $run->execution_snapshot_sha256, $context->executionSnapshotSha256)
                || (string) $review->state !== 'running'
                || (int) $review->attempt_count !== $context->attemptNo
                || $review->last_invocation_id !== null) {
                self::invalid();
            }
            if ($review->max_output_tokens !== null || $review->output_token_parameter !== null) {
                if ((int) $review->max_output_tokens !== $tokens
                    || (string) $review->output_token_parameter !== $parameter) {
                    self::invalid();
                }
                return;
            }
            if (WorkflowCrossFileReview::where('workflow_run_id', $context->workflowRunId)
                ->where('state', 'running')->where('attempt_count', $context->attemptNo)
                ->whereNull('max_output_tokens')->whereNull('output_token_parameter')
                ->update(['max_output_tokens' => $tokens, 'output_token_parameter' => $parameter]) !== 1) {
                self::invalid();
            }
        });
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Cross-file review request options changed before dispatch');
    }
}
