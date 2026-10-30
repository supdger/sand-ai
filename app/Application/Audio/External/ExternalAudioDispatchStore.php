<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Durable persistence port. An adapter cannot send until claimDispatch returns the exact frozen record. */
interface ExternalAudioDispatchStore
{
    /** @return array<string,mixed> */
    public function prepare(ExternalAudioDispatchRequest $request, int $environmentId, int $taskRunId): array;
    /** @return array<string,mixed>|null null means lost lease/fence or non-dispatchable state */
    public function claimDispatch(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array;
    /**
     * Atomically persists the verified acceptance receipt and dispatching -> accepted CAS.
     * A failure rolls the whole transaction back, deliberately leaving the sent dispatch
     * in dispatching for a receipt-only operator recovery rather than inventing ambiguity.
     * @param array<string,mixed> $receipt
     */
    public function persistAccepted(int $dispatchId, ExternalAudioDispatchClaim $claim, string $providerJobId, array $receipt): void;
    /**
     * Atomically appends the canonical result receipt and moves a synchronous
     * Provider completion to recovery_pending. The recovery port is the sole
     * writer of TaskRun and Audio Usage.
     * @param array<string,mixed> $receipt
     */
    public function persistCompleted(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void;
    /** @param array<string,mixed> $terminal */
    public function terminalize(int $dispatchId, ExternalAudioDispatchClaim $claim, string $state, array $terminal): void;
}
