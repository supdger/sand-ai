<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Transaction boundary for receipt-only result recovery and the pre-existing one-event Audio Usage meter. */
interface ExternalAudioResultRecoveryPort
{
    /** @return array<string,mixed> usage summary; canonical result is read only from the encrypted result receipt. */
    public function recover(int $dispatchId, ExternalAudioDispatchClaim $claim): array;
}
