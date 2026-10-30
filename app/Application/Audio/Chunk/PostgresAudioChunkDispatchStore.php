<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Application\Audio\AudioBudgetGuard;
use app\Application\Audio\External\ExternalAudioDispatchClaim;
use app\Application\Audio\External\ExternalAudioDispatchRequest;
use app\Application\Audio\External\ExternalAudioDispatchStore;
use app\Application\Audio\External\PostgresExternalAudioDispatchStore;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/** Uses the established encrypted receipt/dispatch/budget domain; replaces only single-file claim binding. */
final readonly class PostgresAudioChunkDispatchStore implements ExternalAudioDispatchStore
{
    public function __construct(private int $environmentId, private TaskExecutionLease $lease, private int $chunkId,
        private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger(),
        private PostgresExternalAudioDispatchStore $receipts = new PostgresExternalAudioDispatchStore()) {}

    public function prepare(ExternalAudioDispatchRequest $request, int $environmentId, int $taskRunId): array
    {
        if ($environmentId !== $this->environmentId || $taskRunId !== $this->lease->taskId) PostgresAudioChunkExecutionLedger::lost();
        return $this->ledger->prepare($environmentId, $this->lease, $this->chunkId, $request);
    }

    public function claimDispatch(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array
    {
        $this->assertClaim($claim);
        return Db::connect('pgsql')->transaction(function () use ($dispatchId, $claim): ?array {
            $probe = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('environment_id', $this->environmentId)->where('task_run_id', $claim->taskRunId)
                ->where('audio_chunk_id', $this->chunkId)->where('state', 'prepared')->find();
            if (!is_array($probe)) return null;
            $request = PostgresAudioChunkExecutionLedger::request($probe);
            [$manifest, $chunk] = $this->ledger->binding($this->environmentId, $claim->taskRunId, $this->chunkId);
            $attribution = UsageAttribution::fromCurrentPersisted($request->usageAttribution, $this->environmentId,
                'capability', 'sand_ai.audio.transcription');
            (new AudioBudgetGuard())->reserve($dispatchId, $claim->taskRunId, null, $attribution, $request->modelId,
                AudioCallStartPricingSnapshot::fromPersistence($request->priceSnapshot), AudioChunkDispatchBinding::seconds($chunk),
                $request->fingerprint());
            $input = $this->ledger->guard($this->environmentId, $this->lease);
            AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
            $dispatch = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('audio_chunk_id', $this->chunkId)->where('state', 'prepared')->whereNull('lease_owner')->lock(true)->find();
            if (!is_array($dispatch) || (new \app\Application\Usage\AudioSeconds((string) $dispatch['budget_audio_seconds']))->value
                !== AudioChunkDispatchBinding::seconds($chunk)->value) PostgresAudioChunkExecutionLedger::lost();
            if (Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('state', 'prepared')->whereNull('lease_owner')->update(['state' => 'dispatching',
                    'lease_owner' => $claim->owner, 'lease_token' => $claim->token, 'lease_fence' => $claim->fence,
                    'update_time' => gmdate('Y-m-d H:i:s')]) !== 1) PostgresAudioChunkExecutionLedger::lost();
            $this->ledger->guard($this->environmentId, $this->lease);
            return Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)->find();
        });
    }

    public function persistAccepted(int $dispatchId, ExternalAudioDispatchClaim $claim, string $providerJobId, array $receipt): void
    {
        $this->mutate($dispatchId, $claim, fn (): mixed => $this->receipts->persistAccepted($dispatchId, $claim, $providerJobId, $receipt));
    }

    public function persistCompleted(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void
    {
        $this->mutate($dispatchId, $claim, fn (): mixed => $this->receipts->persistCompleted($dispatchId, $claim, $receipt));
    }

    public function terminalize(int $dispatchId, ExternalAudioDispatchClaim $claim, string $state, array $terminal): void
    {
        $this->mutate($dispatchId, $claim, fn (): mixed => $this->receipts->terminalize($dispatchId, $claim, $state, $terminal));
    }

    private function mutate(int $dispatchId, ExternalAudioDispatchClaim $claim, \Closure $operation): void
    {
        $this->assertClaim($claim);
        Db::connect('pgsql')->transaction(function () use ($dispatchId, $claim, $operation): void {
            (new AudioBudgetGuard())->lock($dispatchId, $claim->taskRunId, null);
            $this->ledger->guard($this->environmentId, $this->lease);
            $row = Db::connect('pgsql')->table('sand_ai_external_audio_dispatch')->where('id', $dispatchId)
                ->where('environment_id', $this->environmentId)->where('task_run_id', $claim->taskRunId)
                ->where('audio_chunk_id', $this->chunkId)->lock(true)->find();
            if (!is_array($row)) PostgresAudioChunkExecutionLedger::lost();
            $operation();
            $this->ledger->guard($this->environmentId, $this->lease);
        });
    }

    private function assertClaim(ExternalAudioDispatchClaim $claim): void
    {
        if ($claim->taskRunId !== $this->lease->taskId || $claim->owner !== $this->lease->owner
            || $claim->token !== $this->lease->token || $claim->fence !== $this->lease->fence) PostgresAudioChunkExecutionLedger::lost();
    }
}
