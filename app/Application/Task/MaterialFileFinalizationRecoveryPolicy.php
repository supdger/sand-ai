<?php

declare(strict_types=1);

namespace app\Application\Task;

/** Routes only fully sealed material work to local finalization. */
final class MaterialFileFinalizationRecoveryPolicy
{
    /**
     * @param array<string,mixed>|null $run
     * @param list<array<string,mixed>> $files
     * @param array<string,mixed>|null $review
     */
    public static function needed(int $taskId, ?array $run, array $files, ?array $review = null): bool
    {
        if ($run === null || (int) ($run['task_id'] ?? 0) !== $taskId
            || ($run['workflow_code'] ?? null) !== 'material_analysis'
            || ($run['material_execution_mode'] ?? null) !== 'single_file_v1'
            || $files === []) {
            return false;
        }
        $seen = [];
        foreach ($files as $file) {
            $id = (int) ($file['id'] ?? 0);
            if ($id < 1 || isset($seen[$id]) || ($file['analysis_state'] ?? null) !== TaskState::SUCCEEDED) {
                return false;
            }
            $seen[$id] = true;
        }
        if (count($files) === 1) {
            return $review === null;
        }
        return $review !== null
            && ($review['state'] ?? null) === 'succeeded'
            && is_int($review['attempt_count'] ?? null) && $review['attempt_count'] > 0
            && is_int($review['last_invocation_id'] ?? null) && $review['last_invocation_id'] > 0
            && ($review['result_attempt_no'] ?? null) === $review['attempt_count']
            && ($review['result_invocation_id'] ?? null) === $review['last_invocation_id'];
    }
}
