<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;

/** Trusted lease/fence grant; it must come from a durable store and is never user input. */
final readonly class ExternalAudioDispatchClaim
{
    public function __construct(public int $taskRunId, public string $owner, public string $token, public int $fence)
    {
        if ($taskRunId < 1 || preg_match('/\A[a-z][a-z0-9_-]{2,63}\z/', $owner) !== 1 || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 || $fence < 1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_LEASE_LOST', 'External audio dispatch lease is invalid');
    }
}
