<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use app\Infrastructure\Persistence\Model\WorkflowRunFile;

/** Final sealing uses the whole durable node set, not a synthetic all-source model request. */
final class MaterialChunkCoverage
{
    public function assert(WorkflowRun $run, TaskRun $task, WorkflowRunFile $file, array $plan,
        array $source, array $manifest, int $invocationId): int
    {
        (new MaterialChunkPlan())->assertCoverage($plan, $source);
        $checkpoints = new MaterialChunkCheckpoint();
        $journal = $checkpoints->read((int) $task->id, (int) $file->id);
        if ($journal === null || ($journal['plan']['plan_sha256'] ?? null) !== $plan['plan_sha256']
            || ($journal['execution_snapshot_sha256'] ?? null) !== (string) $run->execution_snapshot_sha256
            || ($journal['parse_id'] ?? null) !== (int) $file->parse_id
            || ($journal['source_sha256'] ?? null) !== (string) $file->sha256) self::invalid();
        $settled = (new MaterialChunkDispatchEvidence())->settled($run, $task);
        foreach ($plan['nodes'] as $node) {
            $saved = $journal['nodes'][$node['id']] ?? null;
            if (!is_array($saved) || ($saved['state'] ?? null) !== 'succeeded') self::invalid();
            $checkpoints->manifest($saved);
            if ($node['kind'] === 'leaf') {
                (new MaterialChunkRunner())->assertProviderNode($run, $task, $file, $node['id'], $saved, false);
                continue;
            }
            $reviews = $saved['review_plan'] ?? null;
            if (!is_array($reviews) || $reviews === []) self::invalid();
            foreach ($reviews as $id => $input) {
                $review = $journal['nodes'][$id] ?? null;
                if (!is_array($review) || ($review['input'] ?? null) !== $input || ($review['state'] ?? null) !== 'succeeded') self::invalid();
                (new MaterialChunkRunner())->assertProviderNode($run, $task, $file, $id, $review, (bool) ($review['legacy_identity'] ?? false));
                $checkpoints->manifest($review);
            }
        }
        $root = $journal['nodes'][$plan['root_node']];
        $sealed = $checkpoints->manifest($root);
        if (($root['invocation_id'] ?? null) !== $invocationId
            || MaterialAnalysisFilePayloadDigest::version2($sealed['payload']) !== MaterialAnalysisFilePayloadDigest::version2($manifest['payload'])
            || $sealed['field_sources'] !== $manifest['field_sources']) self::invalid();
        $review = $journal['nodes'][$root['final_review_node']] ?? null;
        if (!is_array($review) || ($review['legacy_identity'] ?? null) !== true || ($review['invocation_id'] ?? null) !== $invocationId) self::invalid();
        return (int) $review['attempt_no'];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Material file coverage does not match its complete durable plan');
    }
}
