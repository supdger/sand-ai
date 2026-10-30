<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Keeps terminal replay semantics explicit and independent of persistence. */
final class OutcomeReceiptReplayPolicy
{
    public static function shouldReplayContent(bool $receiptEnabled, string $invocationState): bool
    {
        return $receiptEnabled && $invocationState === 'succeeded';
    }
}
