<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;
use think\facade\Db;

/** Private file-node journal on the existing task step, protected by its lease. */
final class MaterialChunkCheckpoint
{
    public const VERSION = 'material_chunks@1';

    public function freeze(TaskExecutionLease $lease, WorkflowRun $run, WorkflowRunFile $file, array $plan): array
    {
        return $this->mutate($lease, $run, $file, static function (?array $old) use ($run, $file, $plan): array {
            if ($old !== null) {
                if (($old['version'] ?? null) !== self::VERSION
                    || ($old['plan']['plan_sha256'] ?? null) !== ($plan['plan_sha256'] ?? null)
                    || ($old['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
                    || ($old['parse_id'] ?? null) !== (int) $file->parse_id
                    || ($old['source_sha256'] ?? null) !== (string) $file->sha256) self::invalid();
                return $old;
            }
            $nodes = [];
            foreach ($plan['nodes'] as $node) {
                $nodes[$node['id']] = ['state' => 'pending', 'attempt_no' => null, 'invocation_id' => null];
            }
            return ['version' => self::VERSION, 'plan' => $plan,
                'execution_snapshot_sha256' => (string) $run->execution_snapshot_sha256,
                'parse_id' => (int) $file->parse_id, 'source_sha256' => (string) $file->sha256,
                'nodes' => $nodes];
        });
    }

    /** @return array<string,mixed>|null */
    public function read(int $taskId, int $runFileId): ?array
    {
        $step = TaskStep::where('task_id', $taskId)->where('sequence_no', 1)->find();
        if ($step === null) self::invalid();
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        $journal = is_array($context) ? ($context['material_chunks']['file_' . $runFileId] ?? null) : null;
        return is_array($journal) ? $journal : null;
    }

    public function record(TaskExecutionLease $lease, WorkflowRun $run, WorkflowRunFile $file,
        string $planSha256, string $nodeId, array $patch): array
    {
        return $this->mutate($lease, $run, $file, static function (?array $journal) use ($planSha256, $nodeId, $patch): array {
            if ($journal === null || ($journal['plan']['plan_sha256'] ?? null) !== $planSha256
                || !isset($journal['nodes'][$nodeId])) self::invalid();
            $old = $journal['nodes'][$nodeId];
            if (($old['state'] ?? null) === 'succeeded') {
                if (($patch['state'] ?? null) !== 'succeeded'
                    || ($patch['output_sha256'] ?? null) !== ($old['output_sha256'] ?? null)
                    || ($patch['invocation_id'] ?? null) !== ($old['invocation_id'] ?? null)) self::invalid();
                return $journal;
            }
            $history = $old['attempts'] ?? [];
            if (is_int($old['attempt_no'] ?? null)) $history[(string) $old['attempt_no']] = array_diff_key($old, ['attempts' => true]);
            $journal['nodes'][$nodeId] = $patch + $old;
            $journal['nodes'][$nodeId]['attempts'] = $history;
            return $journal;
        });
    }

    /** Freeze the actual bounded review inputs before any corresponding dispatch. */
    public function freezeReviews(TaskExecutionLease $lease, WorkflowRun $run, WorkflowRunFile $file,
        string $mergeId, array $batches): array
    {
        $spec = [];
        foreach ($batches as $index => $batch) {
            $id = $mergeId . '_review_' . sprintf('%06d', $index + 1);
            $prompt = $batch['prompt'];
            $spec[$id] = ['prompt_sha256' => hash('sha256', json_encode($prompt->messages,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'source_block_ids' => $prompt->sourceBlockIds];
        }
        if ($spec === []) self::invalid();
        return $this->mutate($lease, $run, $file, static function (?array $journal) use ($mergeId, $spec): array {
            if ($journal === null || !isset($journal['nodes'][$mergeId])) self::invalid();
            $old = $journal['nodes'][$mergeId]['review_plan'] ?? null;
            if ($old !== null && $old !== $spec) self::invalid();
            $journal['nodes'][$mergeId]['review_plan'] = $spec;
            foreach ($spec as $id => $input) {
                if (!isset($journal['nodes'][$id])) $journal['nodes'][$id] = [
                    'state' => 'pending', 'attempt_no' => null, 'invocation_id' => null, 'input' => $input];
                elseif (($journal['nodes'][$id]['input'] ?? null) !== $input) self::invalid();
            }
            return $journal;
        });
    }

    public function knowsNode(int $taskId, int $runFileId, string $nodeId): bool
    {
        $journal = $this->read($taskId, $runFileId);
        return $journal !== null && ($journal['version'] ?? null) === self::VERSION
            && isset($journal['nodes'][$nodeId]);
    }

    /** Terminal evidence must still be checked by the runner before this hint is used. */
    public function failedNodes(int $taskId, int $runFileId, int $attemptNo): array
    {
        $journal = $this->read($taskId, $runFileId);
        return $journal === null ? [] : array_filter($journal['nodes'], static fn (array $node): bool =>
            ($node['state'] ?? null) === 'failed' && ($node['attempt_no'] ?? null) === $attemptNo);
    }

    /** Decoding a checkpoint never invents a new provider attempt. */
    public function manifest(array $node): array
    {
        $json = $node['output_json'] ?? null;
        if (($node['state'] ?? null) !== 'succeeded' || !is_string($json)
            || !is_string($node['output_sha256'] ?? null)
            || !hash_equals($node['output_sha256'], hash('sha256', $json))) self::invalid();
        try { $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::invalid(); }
        if (!is_array($manifest) || !is_array($manifest['payload'] ?? null)
            || !is_array($manifest['field_sources'] ?? null)) self::invalid();
        $payload = $manifest['payload'];
        $manifest['payload'] = MaterialAnalysisFilePayloadDigest::verified($payload,
            (string) ($node['payload_sha256'] ?? ''), MaterialAnalysisFilePayloadDigest::VERSION, null);
        return $manifest;
    }

    /** Safe counts only; no plan, text, request, locator or provider response. */
    public function progress(array $journal, string $fileState): array
    {
        $plan = $journal['plan'] ?? null;
        $nodes = $journal['nodes'] ?? null;
        if (($journal['version'] ?? null) !== self::VERSION || !is_array($plan) || !is_array($nodes)) self::invalid();
        $leaf = $merge = $failed = $unknown = 0;
        foreach ($plan['nodes'] as $node) {
            $state = $nodes[$node['id']]['state'] ?? 'pending';
            if ($state === 'succeeded') {
                if ($node['kind'] === 'leaf') ++$leaf; else ++$merge;
            } elseif ($state === 'failed') ++$failed;
            elseif ($state === 'recovery_pending' || ($state === 'running' && $fileState === 'recovery_pending')) ++$unknown;
        }
        foreach ($nodes as $id => $node) {
            if (!str_contains((string) $id, '_review_')) continue;
            if (($node['state'] ?? null) === 'failed') ++$failed;
            elseif (($node['state'] ?? null) === 'recovery_pending'
                || (($node['state'] ?? null) === 'running' && $fileState === 'recovery_pending')) ++$unknown;
        }
        $complete = $leaf === $plan['leaf_count'] && $merge === $plan['merge_count'] && $fileState === 'succeeded';
        $state = $fileState === 'canceled' ? 'canceled'
            : ($unknown > 0 || $fileState === 'recovery_pending' ? 'recovery_pending' : ($complete ? 'succeeded' : ($fileState === 'failed' ? 'failed' : 'running')));
        return ['version' => 'material_chunk_progress@1', 'state' => $state,
            'source_block_count' => $plan['source_block_count'], 'source_character_count' => $plan['source_character_count'],
            'leaf_count' => $plan['leaf_count'], 'merge_count' => $plan['merge_count'],
            'completed_leaf_count' => $leaf, 'completed_merge_count' => $merge,
            'failed_node_count' => $failed, 'unknown_node_count' => $unknown,
            'coverage_state' => $complete ? 'complete' : (in_array($fileState, ['failed', 'canceled'], true) ? 'incomplete' : 'pending')];
    }

    private function mutate(TaskExecutionLease $lease, WorkflowRun $run, WorkflowRunFile $file, \Closure $change): array
    {
        return Db::connect('pgsql')->transaction(function () use ($lease, $run, $file, $change): array {
            $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->lock(true)->find();
            $currentRun = WorkflowRun::where('id', (int) $run->id)->lock(true)->find();
            $currentFile = WorkflowRunFile::where('id', (int) $file->id)->where('workflow_run_id', (int) $run->id)->lock(true)->find();
            if ($task === null || $step === null || $currentRun === null || $currentFile === null
                || (int) $currentRun->task_id !== $lease->taskId || $currentRun->content_purged_time !== null
                || !hash_equals((string) $currentRun->execution_snapshot_sha256, (string) $run->execution_snapshot_sha256)
                || !in_array((string) $task->state, ['running', 'recovery_pending'], true) || $task->canceled_at !== null
                || $task->lease_expire_time === null || (string) $task->lease_expire_time <= date('Y-m-d H:i:s')
                || (string) $task->lease_owner !== $lease->owner || !hash_equals((string) $task->lease_token, $lease->token)
                || (int) $task->lease_fence !== $lease->fence
                || (string) $step->state !== ((string) $task->state === 'recovery_pending' ? 'failed' : 'running')
                || (string) $currentFile->analysis_state !== ((string) $task->state === 'recovery_pending' ? 'recovery_pending' : 'running')
                || (int) $currentFile->attempt_count !== (int) $file->attempt_count
                || (int) $currentFile->status !== 1 || $currentFile->delete_time !== null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Material chunk checkpoint no longer owns its file attempt');
            }
            $context = is_object($step->context) ? (array) $step->context : $step->context;
            if (!is_array($context)) $context = [];
            $key = 'file_' . (int) $file->id;
            $old = $context['material_chunks'][$key] ?? null;
            if ($old !== null && !is_array($old)) self::invalid();
            $next = $change($old);
            $context['material_chunks'][$key] = $next;
            $step->save(['context' => $context]);
            return $next;
        });
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Material chunk checkpoint changed');
    }
}
