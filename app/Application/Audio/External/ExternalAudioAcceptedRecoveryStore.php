<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Accepted receipt/query persistence boundary; it never creates another Provider submission. */
interface ExternalAudioAcceptedRecoveryStore
{
    /** @return array<string,mixed>|null */
    public function accepted(int $dispatchId, ExternalAudioDispatchClaim $claim): ?array;
    /** @param array<string,mixed> $receipt */
    public function persistResultRecovery(int $dispatchId, ExternalAudioDispatchClaim $claim, array $receipt): void;
}
