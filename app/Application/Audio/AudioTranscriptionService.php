<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

/** Stateless application slice: validate -> verify private reference -> driver -> canonical sourced result. */
final class AudioTranscriptionService
{
    public function __construct(
        private readonly PrivateAudioReferenceVerifier $referenceVerifier,
        private readonly AudioTranscriptionDriver $driver,
        private readonly AudioTranscriptionRequestPolicy $requestPolicy = new AudioTranscriptionRequestPolicy(),
        private readonly AudioTranscriptionResultPolicy $resultPolicy = new AudioTranscriptionResultPolicy(),
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function transcribe(array $input): array
    {
        $request = $this->requestPolicy->normalize($input);
        $this->assertDriver($request['data_egress_policy']);
        $reference = $this->referenceVerifier->verify($request['file_ref']);
        if ($reference->fileRef !== $request['file_ref']) {
            throw new ApiProblem('SAND_AI_AUDIO_REFERENCE_FORBIDDEN', 'The private audio verifier returned a different reference');
        }
        $result = $this->resultPolicy->normalize(
            $this->driver->transcribe($reference, $request),
            $request['speaker_diarization'],
        );
        return (new AudioTranscriptionSourcedResultBuilder())->build($reference, $result, $this->driver->code());
    }

    private function assertDriver(string $dataEgressPolicy): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $this->driver->code()) !== 1
            || !in_array($this->driver->adapterKind(), ['local', 'external'], true)) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_ADAPTER_INVALID', 'The audio transcription driver metadata is invalid');
        }
        $adapterKind = $this->driver->adapterKind();
        $policies = $this->driver->supportedDataEgressPolicies();
        $expectedPolicies = $adapterKind === 'local' ? ['local_only', 'private_only', 'approved_cloud'] : ['approved_cloud'];
        if (!array_is_list($policies) || $policies !== $expectedPolicies) {
            throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_ADAPTER_INVALID', 'The audio transcription driver egress policies are invalid');
        }
        if (!in_array($dataEgressPolicy, $policies, true)) {
            throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The audio transcription driver is incompatible with the requested data egress policy');
        }
    }
}
