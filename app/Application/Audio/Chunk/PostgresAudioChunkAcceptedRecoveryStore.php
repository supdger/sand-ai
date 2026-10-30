<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\External\ExternalAudioAcceptedRecoveryStore;
use app\Application\Audio\External\ExternalAudioDispatchClaim;
use app\Application\Audio\External\PostgresExternalAudioAcceptedRecoveryStore;
use app\Application\Task\TaskExecutionLease;
use think\facade\Db;

/** Chunk-aware live IAM/lease guard around the established query-only receipt store. */
final readonly class PostgresAudioChunkAcceptedRecoveryStore implements ExternalAudioAcceptedRecoveryStore
{
    public function __construct(private int $environmentId, private TaskExecutionLease $lease,
        private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger(),
        private PostgresExternalAudioAcceptedRecoveryStore $receipts = new PostgresExternalAudioAcceptedRecoveryStore()) {}

    public function accepted(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array
    {
        return $this->guarded($dispatchId, $claim, function () use ($dispatchId, $claim): ?array {
            $row = $this->receipts->accepted($dispatchId, $claim);
            if ($row !== null) {
                // Recover the established order before the query service compares its request fingerprint.
                $row['frozen_request'] = PostgresAudioChunkExecutionLedger::request($row)->frozen();
            }
            return $row;
        });
    }

    public function persistResultRecovery(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void
    {
        $this->guarded($dispatchId, $claim, fn (): mixed => $this->receipts->persistResultRecovery($dispatchId, $claim, $receipt));
    }

    private function guarded(int $id, ExternalAudioDispatchClaim $claim, \Closure $operation): mixed
    {
        if ($claim->taskRunId !== $this->lease->taskId || $claim->owner !== $this->lease->owner
            || $claim->token !== $this->lease->token || $claim->fence !== $this->lease->fence) PostgresAudioChunkExecutionLedger::lost();
        return Db::connect('pgsql')->transaction(function () use ($id, $operation): mixed {
            (new AudioBudgetGuard())->lock($id, $this->lease->taskId, null);
            $input = $this->ledger->guard($this->environmentId, $this->lease);
            $row = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $id)
                ->where('environment_id', $this->environmentId)->where('task_run_id', $this->lease->taskId)->lock(true)->find();
            if (!is_array($row) || $row['audio_chunk_id'] === null) PostgresAudioChunkExecutionLedger::lost();
            [$manifest, $chunk] = $this->ledger->binding($this->environmentId, $this->lease->taskId, (int) $row['audio_chunk_id']);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, PostgresAudioChunkExecutionLedger::request($row));
            $value = $operation();
            $this->ledger->guard($this->environmentId, $this->lease);
            return $value;
        });
    }
}
