<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\External\ExternalAudioDispatchClaim;
use app\Application\Audio\External\ExternalAudioReceiptCipher;
use app\Application\Audio\External\ExternalAudioDispatchRequest;
use app\Application\Audio\External\FrozenAudioTaskInputPolicy;
use app\Application\Audio\External\PostgresExternalAudioResultRecoveryPort;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** One original short-audio result receipt. There is no adapter or Provider send here. */
final class TaskAudioReceiptContinuation
{
    public static function claimSql(): string
    {
        $zone = TaskLeasePolicy::runningTimezone();
        return "state='recovery_pending' AND task_type='audio_transcription'
            AND local_recovery_count<local_recovery_max
            AND (lease_expire_time IS NULL OR lease_expire_time<=(clock_timestamp() AT TIME ZONE '{$zone}'))
            AND EXISTS (SELECT 1 FROM sand_ai_external_audio_dispatch audio_dispatch
                JOIN sand_ai_external_audio_receipt audio_receipt ON audio_receipt.external_audio_dispatch_id=audio_dispatch.id
                WHERE audio_dispatch.task_run_id=sand_ai_task_run.id AND audio_dispatch.environment_id=sand_ai_task_run.environment_id
                AND audio_dispatch.logical_execution_id='audio-task:' || sand_ai_task_run.id::text
                AND audio_dispatch.state IN ('accepted','recovery_pending') AND audio_dispatch.content_purged_time IS NULL
                AND audio_receipt.receipt_kind='result' AND audio_receipt.receipt_version='external_audio_receipt/v1'
                AND audio_receipt.destroy_time IS NULL AND audio_receipt.acknowledged_time IS NULL
                AND audio_receipt.expire_time>(clock_timestamp() AT TIME ZONE 'UTC')
                AND audio_receipt.retain_until>=(clock_timestamp() AT TIME ZONE 'UTC'))";
    }

    public function ready(TaskRun $task): bool
    {
        if ((string) $task->task_type !== 'audio_transcription') return false;
        return $this->dispatch($task) !== null;
    }

    /** An already visible call blocks new sends even if its receipt is not yet available. */
    public function visible(TaskRun $task): bool
    {
        $input = TaskAuthorizationContinuation::input($task);
        if ((string) $task->task_type !== 'audio_transcription'
            || !in_array($input['execution_driver']['code'] ?? null, ['glm_asr_audio', 'openai_compatible_audio'], true)) return false;
        return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')
            ->where('task_run_id', (int) $task->id)->where('environment_id', (int) $task->environment_id)
            ->whereIn('state', ['dispatching', 'accepted', 'recovery_pending', 'ambiguous'])->count() > 0;
    }

    public function recover(TaskRun $expected, TaskExecutionLease $lease): array
    {
        $dispatch = $this->dispatch($expected);
        if ($dispatch === null) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'Original audio result receipt is unavailable');
        return Db::connect('pgsql')->transaction(function () use ($expected, $lease, $dispatch): array {
            (new AudioBudgetGuard())->lock((int) $dispatch['id'], $lease->taskId, null);
            $task = TaskRun::where('id', $lease->taskId)->where('environment_id', (int) $expected->environment_id)
                ->where('state', TaskState::RUNNING)->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->lock(true)->find();
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)->where('state', TaskState::RUNNING)->lock(true)->find();
            if ($task === null || $step === null) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Audio receipt continuation lease changed');
            (new TaskGateway())->heartbeat($lease);
            $input = TaskAuthorizationContinuation::input($task);
            $guard = new AudioTaskAccessGuard();
            $guard->revalidateQueued($input['iam_authorization'], $task);
            $current = $this->dispatch($task, true);
            if ($current === null || (int) $current['id'] !== (int) $dispatch['id']) {
                throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'Original audio receipt changed');
            }
            $frozen = $current['frozen_request'] ?? null;
            if (is_string($frozen)) $frozen = json_decode($frozen, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($frozen)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'Original audio request is unavailable');
            $request = ExternalAudioDispatchRequest::fromFrozen($frozen);
            if (!hash_equals((string) $current['request_fingerprint'], $request->fingerprint())) {
                throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'Original audio request fingerprint changed');
            }
            FrozenAudioTaskInputPolicy::assertMatchesExternal($input, (int) $task->environment_id, $request);
            // Receipt is already retained; only execution-lease fields change.
            // The original request/authorization/fingerprint and receipt are immutable.
            $updated = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $current['id'])
                ->where('task_run_id', $lease->taskId)->where('state', $current['state'])->whereNull('content_purged_time')
                ->update(['lease_owner' => $lease->owner, 'lease_token' => $lease->token, 'lease_fence' => $lease->fence]);
            if ($updated !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Audio result recovery claim changed');
            $guard->revalidateQueued($input['iam_authorization'], $task);
            $usage = (new PostgresExternalAudioResultRecoveryPort(
                new ExternalAudioReceiptCipher((array) config('sand_ai_external_audio_receipt', [])),
            ))->recover((int) $current['id'], new ExternalAudioDispatchClaim($lease->taskId, $lease->owner, $lease->token, $lease->fence));
            // Same current-caller decision after receipt/source/usage locks, next to commit.
            $guard->revalidateQueued($input['iam_authorization'], $task);
            return ['_terminal_committed' => true, 'recovered_from' => 'original_audio_receipt', 'usage' => $usage];
        });
    }

    private function dispatch(TaskRun $task, bool $lock = false): ?array
    {
        $input = TaskAuthorizationContinuation::input($task);
        if ((string) $task->task_type !== 'audio_transcription'
            || !in_array($input['execution_driver']['code'] ?? null, ['glm_asr_audio', 'openai_compatible_audio'], true)) return null;
        $query = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('task_run_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)->where('logical_execution_id', 'audio-task:' . (int) $task->id)
            ->whereIn('state', ['accepted', 'recovery_pending'])->whereNull('content_purged_time');
        $row = $lock ? $query->lock(true)->find() : $query->find();
        if (!is_array($row)) return null;
        $receipt = Db::connect('pgsql')->table('sand_ai_external_audio_receipt')->where('external_audio_dispatch_id', (int) $row['id'])
            ->where('receipt_kind', 'result')->where('receipt_version', 'external_audio_receipt/v1')
            ->whereNull('destroy_time')->whereNull('acknowledged_time')->where('expire_time', '>', gmdate('Y-m-d H:i:s'))
            ->where('retain_until', '>=', gmdate('Y-m-d H:i:s'))->find();
        return is_array($receipt) ? $row : null;
    }
}
