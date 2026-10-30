<?php

declare(strict_types=1);

namespace app\Application\Audio;

/**
 * Provider-agnostic port. Adapters translate provider payloads to the canonical
 * result and must clean their own temporary inputs/results on success or error.
 * Provider JSON and temporary locations never cross this boundary.
 */
interface AudioTranscriptionDriver
{
    public function code(): string;

    public function adapterKind(): string;

    /** @return list<string> */
    public function supportedDataEgressPolicies(): array;

    /**
     * @param array{file_ref:string,language:string,speaker_diarization:bool,glossary:list<string>,data_egress_policy:string} $request
     * @return array<string,mixed>
     */
    public function transcribe(VerifiedPrivateAudioReference $reference, array $request): array;
}
