<?php

declare(strict_types=1);

namespace app\Application\Audio;

/** The host verifies ownership/scope and returns no bucket, key, path, URL or secret. */
interface PrivateAudioReferenceVerifier
{
    public function verify(string $fileRef): VerifiedPrivateAudioReference;
}
