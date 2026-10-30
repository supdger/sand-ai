<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialFileOutputFailureEvidence;

/** Keeps completed file attempts from blocking the parent while fencing unresolved provider calls. */
final class MaterialFileDispatchFence
{
    /**
     * @param list<array{id:int,invocation_id:int,parent_workflow_run_id:int,parent_workflow_run_file_id:?int,parent_task_run_id:int,execution_snapshot_sha256:string,workflow_node_code?:string,attempt_no:int,state:string}> $dispatches Newest first.
     * @param array<int,array{invocation_id:int,attempt_no:int,invocation_attempt_no?:int,analysis_state:string,file_attempt_no:int,last_invocation_id:?int}> $results By workflow_run_file_id.
     * @param array<string,mixed>|null $review
     * @param array<int,array{analysis_state:string,file_attempt_no:int,last_invocation_id:?int,failure_summary?:?string,chunk_dispatch_fence?:bool}> $fileStates All fixed files.
     * @param array<int,array<string,mixed>> $outputFailures By immutable dispatch id.
     * @param array<int,true> $chunkSettledDispatchIds Independently proven terminal child dispatches.
     * @return null|array{id:int,invocation_id:int,parent_workflow_run_id:int,parent_workflow_run_file_id:?int,attempt_no:int,state:string}
     */
    public static function unresolved(int $workflowRunId, array $dispatches, array $results, ?array $review = null, array $fileStates = [], array $outputFailures = [], array $chunkSettledDispatchIds = []): ?array
    {
        if ($workflowRunId < 1) {
            self::invalid();
        }
        if ($review !== null) {
            $sealed = ($review['state'] ?? null) === 'succeeded';
            $hasResult = is_int($review['result_invocation_id'] ?? null)
                && $review['result_invocation_id'] > 0;
            if ($sealed !== $hasResult) {
                self::invalid();
            }
        }
        $matched = [];
        $matchedReview = false;
        foreach ($dispatches as $dispatch) {
            $fileId = $dispatch['parent_workflow_run_file_id'] ?? null;
            if ($fileId === null && ($dispatch['workflow_node_code'] ?? null) === 'cross_file_review') {
                if (($dispatch['parent_workflow_run_id'] ?? null) !== $workflowRunId
                    || !in_array($dispatch['state'] ?? null, DispatchRetryPolicy::providerVisibleStates(), true)) {
                    self::invalid();
                }
                if (($review['result_invocation_id'] ?? null) !== $dispatch['invocation_id']
                    && ($chunkSettledDispatchIds[$dispatch['id']] ?? null) === true) {
                    if (!in_array($dispatch['state'], ['succeeded', 'failed'], true)) {
                        self::invalid();
                    }
                    continue;
                }
                if (($review['chunk_dispatch_fence'] ?? false) === true
                    && ($review['result_invocation_id'] ?? null) !== $dispatch['invocation_id']) {
                    return $dispatch;
                }
                if ($dispatch['state'] === 'failed'
                    && ($review['result_invocation_id'] ?? null) === null
                    && ($dispatch['attempt_no'] ?? 0) > 0
                    && $dispatch['attempt_no'] <= (int) ($review['attempt_count'] ?? 0)) {
                    // A terminal provider rejection has a known outcome. A new
                    // review attempt may run without repeating sealed files.
                    continue;
                }
                if ($review === null || ($review['state'] ?? null) !== 'succeeded') {
                    return $dispatch;
                }
                if (($review['result_invocation_id'] ?? null) === $dispatch['invocation_id']) {
                    if ($matchedReview || $dispatch['state'] !== 'succeeded'
                        || ($review['attempt_count'] ?? null) !== ($review['result_attempt_no'] ?? null)
                        || ($review['result_invocation_attempt_no'] ?? $review['result_attempt_no'] ?? null) !== $dispatch['attempt_no']
                        || $dispatch['attempt_no'] < 1
                        || $dispatch['attempt_no'] > (int) ($review['result_attempt_no'] ?? 0)
                        || ($review['last_invocation_id'] ?? null) !== $dispatch['invocation_id']) {
                        self::invalid();
                    }
                    $matchedReview = true;
                    continue;
                }
                if ($dispatch['state'] !== 'failed'
                    || $dispatch['attempt_no'] >= (int) ($review['result_attempt_no'] ?? 0)) {
                    self::invalid();
                }
                continue;
            }
            if (($dispatch['parent_workflow_run_id'] ?? null) !== $workflowRunId
                || !is_int($fileId) || $fileId < 1
                || !in_array($dispatch['state'] ?? null, DispatchRetryPolicy::providerVisibleStates(), true)) {
                self::invalid();
            }
            $result = $results[$fileId] ?? null;
            $file = $fileStates[$fileId] ?? null;
            if ($result !== null
                && (($result['analysis_state'] ?? null) !== 'succeeded'
                    || ($result['last_invocation_id'] ?? null) !== ($result['invocation_id'] ?? null)
                    || ($result['file_attempt_no'] ?? null) !== ($result['attempt_no'] ?? null)
                    || !is_int($result['attempt_no'] ?? null) || $result['attempt_no'] < 1
                    || !is_int($result['invocation_attempt_no'] ?? $result['attempt_no'])
                    || ($result['invocation_attempt_no'] ?? $result['attempt_no']) < 1
                    || ($result['invocation_attempt_no'] ?? $result['attempt_no']) > $result['attempt_no'])) {
                self::invalid();
            }
            if (($result['invocation_id'] ?? null) !== $dispatch['invocation_id']
                && ($chunkSettledDispatchIds[$dispatch['id']] ?? null) === true) {
                if (!in_array($dispatch['state'], ['succeeded', 'failed'], true)) {
                    self::invalid();
                }
                continue;
            }
            if (($file['chunk_dispatch_fence'] ?? false) === true
                && ($result['invocation_id'] ?? null) !== $dispatch['invocation_id']) {
                return $dispatch;
            }
            if (str_starts_with((string) ($dispatch['workflow_node_code'] ?? ''), 'material_leaf_')
                || str_starts_with((string) ($dispatch['workflow_node_code'] ?? ''), 'material_merge_')) {
                // A child rejection is terminal only with its original journal
                // and dispatch evidence, never from the file attempt alone.
                return $dispatch;
            }
            $failure = $outputFailures[$dispatch['id']] ?? null;
            $documentedOutputFailure = $dispatch['state'] === 'succeeded'
                && is_array($file)
                && MaterialFileOutputFailureEvidence::matches(
                    $failure, $workflowRunId, $fileId,
                    (int) ($dispatch['parent_task_run_id'] ?? 0),
                    $dispatch['attempt_no'], $dispatch['invocation_id'], $dispatch['id'],
                    (string) ($dispatch['execution_snapshot_sha256'] ?? ''),
                );
            if ($result === null) {
                if ($documentedOutputFailure
                    && ($file['last_invocation_id'] ?? null) === null
                    && $dispatch['attempt_no'] > 0
                    && $dispatch['attempt_no'] < (int) ($file['file_attempt_no'] ?? 0)
                    && in_array($file['analysis_state'] ?? null, ['pending', 'running', 'retrying', 'failed'], true)) {
                    // A previously rejected response may be superseded only
                    // by an actual later file attempt.
                    continue;
                }
                if ($documentedOutputFailure
                    && ($file['analysis_state'] ?? null) === 'failed'
                    && ($file['last_invocation_id'] ?? null) === null
                    && ($file['file_attempt_no'] ?? null) === $dispatch['attempt_no']
                    && ($file['failure_summary'] ?? null) === ($failure['error_code'] ?? null)) {
                    continue;
                }
                if ($dispatch['state'] === 'failed' && is_array($file)
                    && in_array($file['analysis_state'] ?? null, ['pending', 'running', 'retrying', 'failed'], true)
                    && ($file['last_invocation_id'] ?? null) === null
                    && $dispatch['attempt_no'] > 0
                    && $dispatch['attempt_no'] <= (int) ($file['file_attempt_no'] ?? 0)) {
                    // A terminal rejection has no retained result to recover.
                    // Later attempts still use their own request identity.
                    continue;
                }
                return $dispatch;
            }
            if ($dispatch['invocation_id'] === $result['invocation_id']) {
                if ($dispatch['state'] !== 'succeeded'
                    || $dispatch['attempt_no'] !== ($result['invocation_attempt_no'] ?? $result['attempt_no'])) {
                    self::invalid();
                }
                $matched[$fileId] = true;
                continue;
            }
            if ($documentedOutputFailure
                && $dispatch['attempt_no'] < $result['attempt_no']) {
                continue;
            }
            if ($dispatch['state'] !== 'failed' || $dispatch['attempt_no'] >= $result['attempt_no']) {
                self::invalid();
            }
        }
        if (count($matched) !== count($results)) {
            self::invalid();
        }
        if ($review !== null && ($review['state'] ?? null) === 'succeeded'
            && ($review['result_invocation_id'] ?? null) !== null && !$matchedReview) {
            self::invalid();
        }
        return null;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A material file dispatch has inconsistent result evidence');
    }
}
