<?php

declare(strict_types=1);

namespace app\Application\Audio;

interface PrivateAudioFileLoader
{
    /** @template T @param callable(ControlledAudioInput):T $operation @return T */
    public function withInput(int $environmentId, VerifiedPrivateAudioReference $reference, callable $operation): mixed;
}
