<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Application\Audio\ControlledAudioInput;

/** Creates an adapter whose multipart callback owns this one controlled input only. */
interface ExternalAudioAdapterFactory
{
    public function create(ControlledAudioInput $input): ExternalAudioAdapter;
}
