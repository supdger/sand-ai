<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\ControlledAudioInput;
use app\Application\Audio\External\ExternalAudioAdapterFactory;
use app\Application\Audio\External\ExternalAudioDispatchGateway;
use app\Application\Audio\External\ExternalAudioDispatchRequestFactory;
use app\Application\Audio\External\ExternalAudioReceiptCipher;
use app\Application\Audio\External\PostgresExternalAudioRequestFactory;
use app\Application\Audio\External\PostgresOpenAiCompatibleAudioAdapterFactory;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskGateway;
use think\facade\Db;

/**
 * Real, bounded Worker collaborator: one call advances at most one frozen clip.
 * A scheduler must explicitly keep/renew the parent lease and consume the returned continuation.
 * The opt-in task runner invokes this only after its installed-schema gate passes.
 */
final readonly class AudioChunkExecution
{
    public function __construct(private LocalPrivateAudioChunkObjects $objects, private ExternalAudioReceiptCipher $cipher,
        private ExternalAudioDispatchRequestFactory $requests = new PostgresExternalAudioRequestFactory(),
        private ExternalAudioAdapterFactory $adapters = new PostgresOpenAiCompatibleAudioAdapterFactory(),
        private PostgresAudioChunkExecutionLedger $ledger = new PostgresAudioChunkExecutionLedger()) {}

    /** Progress only. No parent task success or public transcript is inferred from one completed clip. */
    public function executeOne(int $environmentId, TaskExecutionLease $lease): array
    {
        (new TaskGateway())->heartbeat($lease);
        $selected = $this->ledger->next($environmentId, $lease);
        $manifest = $selected['manifest'];
        $chunk = $selected['chunk'];
        if ($chunk === null) return ['state' => 'aggregation_pending'] + $this->ledger->progress($environmentId, $lease->taskId);
        $claim = PostgresAudioChunkExecutionLedger::claim($lease);
        $existing = $selected['dispatch'];
        $recovery = new PostgresAudioChunkResultRecoveryPort($this->cipher, $environmentId, $lease, $this->ledger);
        if (is_array($existing) && $existing['state'] !== 'prepared') {
            $existing = $this->ledger->recoverClaim($environmentId, $lease, (int) $existing['id']);
            if ($existing['state'] === 'recovery_pending') {
                $recovery->recover((int) $existing['id'], $claim);
                return ['state' => 'continuation_pending'] + $this->ledger->progress($environmentId, $lease->taskId);
            }
            if ($existing['state'] === 'accepted') {
                $this->assertPreparedStore($environmentId, $lease);
                $request = PostgresAudioChunkExecutionLedger::request($existing);
                $ref = (string) $chunk['chunk_ref'];
                $outcome = $this->objects->locked($ref, fn (): array => $this->objects->withVerifiedPath(
                    $ref, (int) $chunk['byte_size'], (string) $chunk['content_sha256'],
                    function (string $path) use ($environmentId, $lease, $chunk, $request, $existing, $claim): array {
                        $this->assertPreparedStore($environmentId, $lease);
                        $source = new VerifiedPrivateAudioReference($request->fileRef, 'audio/wav', $request->fileSha256, $request->trustedScope);
                        $query = new \app\Application\Audio\External\ExternalAudioAcceptedRecoveryService(
                            new PostgresAudioChunkAcceptedRecoveryStore($environmentId, $lease), $this->cipher);
                        // This service exposes query only. Registered synchronous adapters return null;
                        // that means recovery remains required, never permission to submit again.
                        return $query->recover((int) $existing['id'], $claim,
                            $this->adapters->create(new ControlledAudioInput($path, $source, AudioChunkDispatchBinding::duration($chunk))));
                    },
                ));
                return ['state' => $outcome['state'] === 'recovery_pending' ? 'continuation_pending' : 'receipt_poll_required']
                    + $this->ledger->progress($environmentId, $lease->taskId);
            }
            return ['state' => 'manual_review_required'] + $this->ledger->progress($environmentId, $lease->taskId);
        }
        $input = Db::connect('pgsql')->transaction(fn (): array => $this->ledger->guard($environmentId, $lease));
        $request = is_array($existing) ? PostgresAudioChunkExecutionLedger::request($existing)
            : AudioChunkDispatchBinding::request($this->requests->create($environmentId, $lease->taskId, $input), $manifest, $chunk);
        AudioChunkDispatchBinding::assert($input, $manifest, $chunk, $request);
        $this->assertPreparedStore($environmentId, $lease);
        $ref = (string) $chunk['chunk_ref'];
        $state = $this->objects->locked($ref, function () use ($environmentId, $lease, $chunk, $ref, $request, $recovery, $claim): string {
            $this->assertPreparedStore($environmentId, $lease);
            return $this->objects->withVerifiedPath($ref, (int) $chunk['byte_size'], (string) $chunk['content_sha256'],
                function (string $path) use ($environmentId, $lease, $chunk, $request, $recovery, $claim): string {
                    $this->assertPreparedStore($environmentId, $lease);
                    $reference = new VerifiedPrivateAudioReference($request->fileRef, 'audio/wav', $request->fileSha256, $request->trustedScope);
                    $controlled = new ControlledAudioInput($path, $reference, AudioChunkDispatchBinding::duration($chunk));
                    $store = new PostgresAudioChunkDispatchStore($environmentId, $lease, (int) $chunk['id'], $this->ledger);
                    $gateway = new ExternalAudioDispatchGateway($store, $this->cipher, $recovery);
                    $record = $gateway->prepare($request, $environmentId, $lease->taskId);
                    $result = $gateway->dispatch((int) $record['id'], $claim, $request, $this->adapters->create($controlled));
                    return (string) $result['state'];
                });
        });
        if (!in_array($state, ['succeeded', 'recovery_pending', 'accepted'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_CHUNK_STATE_INVALID', 'The audio chunk requires recovery', true);
        }
        return ['state' => 'continuation_pending'] + $this->ledger->progress($environmentId, $lease->taskId);
    }

    private function assertPreparedStore(int $environmentId, TaskExecutionLease $lease): void
    {
        $rootFingerprint = $this->objects->rootFingerprint();
        (new PostgresAudioChunkPreparationLedger())->assertStore($this->objects->storeCode, $rootFingerprint);
        $prep = Db::connect('pgsql')->table('sand_ai_audio_chunk_preparation')->where('environment_id', $environmentId)
            ->where('task_run_id', $lease->taskId)->where('state', 'sealed')->find();
        if (!is_array($prep) || $prep['store_code'] !== $this->objects->storeCode
            || !hash_equals((string) $prep['store_root_sha256'], $rootFingerprint)) AudioChunkDispatchBinding::invalid();
    }
}
