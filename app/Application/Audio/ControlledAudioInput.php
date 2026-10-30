<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** A loader-owned temporary file. Storage locations never leave the local adapter boundary. */
final readonly class ControlledAudioInput
{
    public function __construct(public string $temporaryPath, public VerifiedPrivateAudioReference $reference, public ?string $verifiedDurationSeconds = null)
    {
        if ($temporaryPath === '' || !str_starts_with($temporaryPath, '/')) {
            throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The controlled audio input is invalid');
        }
    }
}
