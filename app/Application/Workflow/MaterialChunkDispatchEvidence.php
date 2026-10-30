<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use app\Infrastructure\Persistence\Model\WorkflowFileResult;

/** Only exact terminal node evidence may stop fencing a parent task. */
final class MaterialChunkDispatchEvidence
{
    /** @return array<int,true> Immutable dispatch ids, never an inferred provider outcome. */
    public function settled(WorkflowRun $run, TaskRun $task): array
    {
        $settled = [];
        $checkpoints = new MaterialChunkCheckpoint();
        $runner = new MaterialChunkRunner();
        foreach (WorkflowRunFile::where('workflow_run_id', (int) $run->id)->select() as $file) {
            $journal = $checkpoints->read((int) $task->id, (int) $file->id);
            if ($journal === null) continue;
            if (($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
                || ($journal['parse_id'] ?? null) !== (int) $file->parse_id
                || ($journal['source_sha256'] ?? null) !== (string) $file->sha256) self::invalid();
            foreach ($journal['nodes'] as $id => $node) {
                if (!str_starts_with((string) $id, 'leaf_') && !str_contains((string) $id, '_review_')) continue;
                foreach ([...array_values($node['attempts'] ?? []), $node] as $attempt) {
                    $state = $attempt['state'] ?? null;
                    if (!in_array($state, ['succeeded', 'failed'], true) || !is_int($attempt['invocation_id'] ?? null)) continue;
                    if ($state === 'failed' && ($attempt['error_code'] ?? null) === 'SAND_AI_BUDGET_EXCEEDED') {
                        if (!$runner->budgetFailedNode($run, $task, $file, (string) $id, $attempt)) self::invalid();
                        continue;
                    }
                    $invocation = Invocation::where('id', $attempt['invocation_id'])->find();
                    $dispatch = $invocation === null ? null : InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
                    if ($invocation === null || $dispatch === null) self::invalid();
                    $runner->assertIdentity($run, $task, $file, $invocation, $dispatch, (string) $id,
                        (int) $attempt['attempt_no'], (bool) ($attempt['legacy_identity'] ?? false));
                    if ($state === 'succeeded') {
                        if ((string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::invalid();
                        $checkpoints->manifest($attempt);
                    } elseif ($runner->notDispatchedFailure($run, $task, $file, $invocation, $dispatch, (string) $id,
                        (int) $attempt['attempt_no'], (bool) ($attempt['legacy_identity'] ?? false))) {
                        if (($attempt['error_code'] ?? null) !== (string) $invocation->error_code) self::invalid();
                    } elseif (($attempt['error_code'] ?? null) === 'SAND_AI_PROVIDER_REJECTED') {
                        if ((string) $invocation->state !== 'failed' || (string) $dispatch->state !== 'failed'
                            || (string) $invocation->error_code !== 'SAND_AI_PROVIDER_REJECTED') self::invalid();
                    } elseif (in_array($attempt['error_code'] ?? null, ['SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_ARTIFACT_PROVENANCE_INVALID'], true)) {
                        if ((string) $invocation->state !== 'succeeded' || (string) $dispatch->state !== 'succeeded') self::invalid();
                    } else continue;
                    $settled[(int) $dispatch->id] = true;
                }
            }
        }
        return $settled;
    }

    /** Current file sealing generation and original root call generation are separate facts. */
    public function resultAttempt(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, WorkflowFileResult $result): int
    {
        $attempt = $this->rootAttempt($run, $task, $file, (int) $result->invocation_id);
        if ($attempt === null) return (int) $result->attempt_no;
        if ((int) $result->workflow_run_file_id !== (int) $file->id
            || (int) $result->attempt_no !== (int) $file->attempt_count
            || !hash_equals((string) $result->execution_snapshot_sha256, (string) $run->execution_snapshot_sha256)) self::invalid();
        $journal = (new MaterialChunkCheckpoint())->read((int) $task->id, (int) $file->id);
        $root = (new MaterialChunkCheckpoint())->manifest($journal['nodes'][$journal['plan']['root_node']]);
        $payload = MaterialAnalysisFilePayloadDigest::verified($result->payload, (string) $result->payload_sha256,
            $result->payload_digest_version, $result->content_purged_time);
        if (MaterialAnalysisFilePayloadDigest::version2($root['payload']) !== MaterialAnalysisFilePayloadDigest::version2($payload)) self::invalid();
        return $attempt;
    }

    public function rootAttempt(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, int $resultInvocationId): ?int
    {
        $journal = (new MaterialChunkCheckpoint())->read((int) $task->id, (int) $file->id);
        if ($journal === null) return null;
        (new MaterialChunkRunner())->assertFileCheckpoint($run, $task, $file, $journal);
        $root = $journal['nodes'][$journal['plan']['root_node']];
        $review = $journal['nodes'][$root['final_review_node'] ?? ''] ?? null;
        if (($root['invocation_id'] ?? null) !== $resultInvocationId || !is_array($review)
            || ($review['invocation_id'] ?? null) !== $resultInvocationId || ($review['legacy_identity'] ?? null) !== true
            || ($review['attempt_no'] ?? null) !== ($root['attempt_no'] ?? null)
            || !is_int($root['attempt_no'] ?? null) || $root['attempt_no'] < 1
            || $root['attempt_no'] > (int) $file->attempt_count) self::invalid();
        return $root['attempt_no'];
    }

    public function failed(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, ?string $errorCode = null): bool
    {
        $nodes = (new MaterialChunkCheckpoint())->failedNodes((int) $task->id, (int) $file->id, (int) $file->attempt_count);
        $settled = $nodes === [] ? [] : $this->settled($run, $task);
        foreach ($nodes as $id => $node) {
            if (($node['pre_dispatch_error'] ?? null) === true && ($node['invocation_id'] ?? null) === null
                && ($node['error_code'] ?? null) === 'SAND_AI_MATERIAL_ANALYSIS_INPUT_TOO_LARGE'
                && ($errorCode === null || $errorCode === $node['error_code'])) return true;
            if ($errorCode !== null && ($node['error_code'] ?? null) !== $errorCode) continue;
            if (($node['error_code'] ?? null) === 'SAND_AI_BUDGET_EXCEEDED') {
                if (!(new MaterialChunkRunner())->budgetFailedNode($run, $task, $file, (string) $id, $node)) self::invalid();
                return true;
            }
            $dispatch = InvocationDispatch::where('invocation_id', (int) ($node['invocation_id'] ?? 0))->find();
            if ($dispatch !== null && isset($settled[(int) $dispatch->id])) return true;
        }
        return false;
    }

    public function unresolved(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file): bool
    {
        $settled = $this->settled($run, $task);
        foreach (InvocationDispatch::where('parent_workflow_run_id', (int) $run->id)
            ->where('parent_task_run_id', (int) $task->id)->where('parent_workflow_run_file_id', (int) $file->id)
            ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])->select() as $dispatch) {
            if (!isset($settled[(int) $dispatch->id])) return true;
        }
        return false;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Material chunk dispatch evidence changed');
    }
}
