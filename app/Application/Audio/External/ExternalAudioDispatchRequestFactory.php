<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

/** Builds the complete frozen 121D request from trusted task snapshots, never client input. */
interface ExternalAudioDispatchRequestFactory
{
    /** @param array<string,mixed> $input */
    public function create(int $environmentId, int $taskRunId, array $input): ExternalAudioDispatchRequest;
}
