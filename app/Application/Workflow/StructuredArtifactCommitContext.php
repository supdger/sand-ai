<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/** Explicit, non-sensitive execution facts used by Artifact persistence and audit. */
final class StructuredArtifactCommitContext
{
    private function __construct(
        public readonly bool $recovery,
        public readonly bool $providerCalled,
    ) {
    }

    public static function normalMaterial(): self
    {
        return new self(false, true);
    }

    public static function providerFree(): self
    {
        return new self(false, false);
    }

    public static function receiptRecovery(): self
    {
        return new self(true, false);
    }
}
