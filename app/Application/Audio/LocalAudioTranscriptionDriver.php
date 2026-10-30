<?php

declare(strict_types=1);

namespace app\Application\Audio;

interface LocalAudioTranscriptionDriver
{
    /** @param array{file_ref:string,language:string,speaker_diarization:bool,glossary:list<string>,data_egress_policy:string} $request @return array<string,mixed> */
    public function transcribeLocal(ControlledAudioInput $input, array $request): array;
}
