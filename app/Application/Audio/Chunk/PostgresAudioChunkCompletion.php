<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeaseTerminalGuard;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Task\TaskState;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** Finalizes only proven persisted clips; accepts no caller-supplied transcript or fee total. */
final readonly class PostgresAudioChunkCompletion
{
    public function __construct(private PostgresAudioChunkAggregation $aggregation = new PostgresAudioChunkAggregation(),
        private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger()) {}

    public function complete(int $environmentId, TaskExecutionLease $lease): array
    {
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $lease): array {
            $assembly = $this->aggregation->assemble($environmentId, $lease);
            // Every immutable success/usage fact is checked before this final live lease and IAM check.
            $this->ledger->guard($environmentId, $lease);
            $now = date('Y-m-d H:i:s');
            $updated = TaskRun::where('id', $lease->taskId)->where('environment_id', $environmentId)
                ->where('state', TaskState::RUNNING)->where('lease_owner', $lease->owner)
                ->where('lease_token', $lease->token)->where('lease_fence', $lease->fence)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])
                ->update([
                    'state' => TaskState::SUCCEEDED, 'result' => PostgresAudioChunkAggregation::stored($assembly),
                    'completed_at' => $now, 'locked_at' => null, 'locked_by' => null, 'lease_owner' => null,
                    'lease_token' => null, 'lease_expire_time' => null, 'heartbeat_time' => null,
                    'error_code' => null, 'error_summary' => null, 'version' => Db::raw('version + 1'),
                ]);
            $step = TaskStep::where('task_id', $lease->taskId)->where('sequence_no', 1)
                ->where('state', TaskState::RUNNING)->update([
                    'state' => TaskState::SUCCEEDED, 'completed_at' => $now,
                    'error_code' => null, 'error_summary' => null,
                ]);
            TaskLeaseTerminalGuard::assertUpdated($updated, $step, 'audio chunk completion');
            // SQL98 compares task.result byte-for-field with a public projection. Long private
            // aggregation evidence deliberately uses the full validation path until a compatible seal exists.
            return $assembly['transcript'] + ['_terminal_committed' => true];
        });
    }
}
