<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Future adapters implement this port; this ticket provides no configured implementation. */
interface ExternalAudioAdapter
{
    /** Pure local configuration/route validation. It must never contact a Provider. */
    public function preflight(ExternalAudioDispatchRequest $request): void;
    /** Typed completed/accepted/ambiguous/not_dispatched outcome; no empty-job pseudo async. */
    public function dispatch(ExternalAudioDispatchRequest $request, string $providerIdempotencyKey): ExternalAudioDispatchOutcome;
    /** @return array{result_receipt:string}|null canonical safe result only; never a raw Provider body */
    public function query(string $providerJobId): ?array;
}
