<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Task\AudioTaskResultPresenter;

/**
 * Transport-free durable dispatch coordinator. It deliberately has no default adapter:
 * production wiring may only inject a future code-registered, published external driver.
 */
final class ExternalAudioDispatchGateway
{
    public function __construct(private readonly ExternalAudioDispatchStore $store, private readonly ExternalAudioReceiptCipher $cipher, private readonly ?ExternalAudioResultRecoveryPort $recovery = null) {}

    /** @return array<string,mixed> */
    public function prepare(ExternalAudioDispatchRequest $request, int $environmentId, int $taskRunId): array
    {
        if ($environmentId < 1 || $taskRunId < 1 || ($request->usageAttribution['environment_id'] ?? null) !== $environmentId) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'External audio dispatch scope is invalid');
        return $this->store->prepare($request, $environmentId, $taskRunId);
    }

    /** @return array<string,mixed> */
    public function dispatch(int $dispatchId, ExternalAudioDispatchClaim $claim, ExternalAudioDispatchRequest $request, ExternalAudioAdapter $adapter): array
    {
        try { $this->cipher->assertReady(); } catch (\Throwable) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_NOT_DISPATCHED', 'External audio receipt recovery is not configured'); }
        try { $adapter->preflight($request); } catch (ApiProblem $problem) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_NOT_DISPATCHED', 'External audio dispatch was rejected before Provider visibility'); } catch (\Throwable) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_NOT_DISPATCHED', 'External audio dispatch preflight failed'); }
        $record = $this->store->claimDispatch($dispatchId, $claim);
        if ($record === null || !ExternalAudioDispatchStatePolicy::maySend((string) ($record['state'] ?? '')) || !hash_equals((string) ($record['request_fingerprint'] ?? ''), $request->fingerprint()) || (int) ($record['task_run_id'] ?? 0) !== $claim->taskRunId || (string) ($record['lease_owner'] ?? '') !== $claim->owner || (string) ($record['lease_token'] ?? '') !== $claim->token || (int) ($record['lease_fence'] ?? 0) !== $claim->fence) {
            throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST', 'External audio dispatch cannot acquire send permission');
        }
        try { $outcome = $adapter->dispatch($request, $request->providerIdempotencyKey()); } catch (\Throwable) { $this->terminalizeAmbiguous($dispatchId, $claim); }
        if (!$outcome instanceof ExternalAudioDispatchOutcome) $this->terminalizeAmbiguous($dispatchId, $claim);
        if ($outcome->state === 'ambiguous') $this->terminalizeAmbiguous($dispatchId, $claim);
        if ($outcome->state === 'not_dispatched') {
            try { $this->store->terminalize($dispatchId, $claim, ExternalAudioDispatchStatePolicy::NOT_DISPATCHED, ['usage_state' => 'none', 'pricing_state' => 'none', 'error_code' => 'SAND_AI_EXTERNAL_AUDIO_NOT_DISPATCHED']); }
            catch (\Throwable) { throw new ApiProblem('SAND_AI_DISPATCH_PERSISTENCE_FAILED', 'External audio pre-send rejection cannot be persisted', true); }
            throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_NOT_DISPATCHED', 'External audio dispatch was rejected before Provider visibility');
        }
        if ($outcome->state === 'completed') return $this->persistCompleted($dispatchId, $claim, $record, $request, $outcome);
        try { $acceptedReceipt = $this->acceptedReceipt((string) $outcome->providerJobId, (string) $outcome->acceptedReceipt); }
        catch (\Throwable) { $this->terminalizeAmbiguous($dispatchId, $claim); }
        try {
            $receipt = $this->cipher->encrypt('accepted', $acceptedReceipt, $this->receiptContext($record, $request));
            $this->store->persistAccepted($dispatchId, $claim, (string) $outcome->providerJobId, $receipt);
        } catch (\Throwable) {
            // Provider acceptance is known but persistence failed. The dispatch remains visible/stale and must never resend.
            throw new ApiProblem('SAND_AI_DISPATCH_PERSISTENCE_FAILED', 'External audio acceptance cannot be persisted', true);
        }
        return ['state' => ExternalAudioDispatchStatePolicy::ACCEPTED, 'provider_job_id' => $outcome->providerJobId];
    }

    /** @return array<string,mixed> */
    private function persistCompleted(int $dispatchId, ExternalAudioDispatchClaim $claim, array $record, ExternalAudioDispatchRequest $request, ExternalAudioDispatchOutcome $outcome): array
    {
        try {
            $result = (new AudioTaskResultPresenter())->present($outcome->canonicalResult, $request->driverCode, $request->driverVersion);
            ExternalAudioRecoveryPolicy::assertCanonicalResult($result, $request->driverCode, $request->driverVersion);
            $receipt = $this->cipher->encrypt('result', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->receiptContext($record, $request));
            $this->store->persistCompleted($dispatchId, $claim, $receipt);
        } catch (\Throwable) {
            // The Provider already completed. Leave dispatching stale for the no-resend ambiguous recovery policy.
            throw new ApiProblem('SAND_AI_DISPATCH_PERSISTENCE_FAILED', 'External audio completion cannot be persisted', true);
        }
        if ($this->recovery === null) return ['state' => ExternalAudioDispatchStatePolicy::RECOVERY_PENDING];
        return ['state' => ExternalAudioDispatchStatePolicy::SUCCEEDED, 'usage' => $this->recovery->recover($dispatchId, $claim)];
    }

    private function acceptedReceipt(string $providerJobId, string $receipt): string
    {
        try { $decoded = json_decode($receipt, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID', 'External audio adapter acceptance is invalid'); }
        if (!is_array($decoded) || array_keys($decoded) !== ['provider_job_id'] || !is_string($decoded['provider_job_id']) || !hash_equals($providerJobId, $decoded['provider_job_id'])) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RESPONSE_INVALID', 'External audio adapter acceptance is invalid');
        return json_encode(['provider_job_id' => $providerJobId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function terminalizeAmbiguous(int $dispatchId, ExternalAudioDispatchClaim $claim): never
    {
        try {
            $this->store->terminalize($dispatchId, $claim, ExternalAudioDispatchStatePolicy::AMBIGUOUS, ['usage_state' => 'unknown', 'pricing_state' => 'unknown', 'error_code' => 'SAND_AI_DISPATCH_AMBIGUOUS']);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_DISPATCH_PERSISTENCE_FAILED', 'External audio ambiguous dispatch cannot be persisted', true);
        }
        throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'External audio dispatch outcome is ambiguous and requires manual review', true);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function receiptContext(array $record, ExternalAudioDispatchRequest $request): array
    {
        foreach (['id','environment_id','task_run_id'] as $key) if (!is_int($record[$key] ?? null) || $record[$key] < 1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_RECEIPT_INVALID', 'External audio dispatch record is invalid');
        return ['environment_id' => $record['environment_id'], 'task_run_id' => $record['task_run_id'], 'dispatch_id' => $record['id'], 'request_fingerprint' => $request->fingerprint(), 'logical_execution_id' => $request->logicalExecutionId, 'provider_id' => $request->providerId, 'model_id' => $request->modelId, 'deployment_id' => $request->deploymentId, 'profile_id' => $request->profileId, 'profile_revision' => $request->profileRevision, 'route_id' => $request->routeId, 'credential_revision_id' => $request->credentialRevisionId, 'credential_digest' => $request->credentialDigest, 'price_snapshot_hash' => $request->priceSnapshotHash()];
    }
}
