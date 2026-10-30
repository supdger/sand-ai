<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

final class AudioTranscriptionRequestPolicy
{
    /**
     * Restore the fixed public envelope only at the persisted-task boundary.
     * Caller requests still require their own schema through normalize().
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function fromPersisted(array $request): array
    {
        $this->exactKeys($request, ['file_ref', 'language', 'speaker_diarization', 'glossary', 'data_egress_policy'], 'persisted request');
        $payload = [
            'schema' => ['code' => 'sand_ai.audio_transcription_request', 'version' => '1.0.0'],
        ] + $request;
        $this->normalize($payload);
        return $payload;
    }

    /** @param array<string,mixed> $input @return array{file_ref:string,language:string,speaker_diarization:bool,glossary:list<string>,data_egress_policy:string} */
    public function normalize(array $input): array
    {
        $this->exactKeys($input, ['schema', 'file_ref', 'language', 'speaker_diarization', 'glossary', 'data_egress_policy'], 'request');
        $schema = $input['schema'] ?? null;
        $this->exactKeys($schema, ['code', 'version'], 'schema');
        if (($schema['code'] ?? null) !== 'sand_ai.audio_transcription_request'
            || ($schema['version'] ?? null) !== '1.0.0') {
            $this->fail('The audio transcription request schema is unsupported');
        }

        $fileRef = $input['file_ref'] ?? null;
        if (!is_string($fileRef) || preg_match('/\Asaf_[A-Za-z0-9_-]{16,120}\z/D', $fileRef) !== 1) {
            $this->fail('file_ref must be an opaque SandAI private file reference');
        }
        $language = $input['language'] ?? null;
        if (!is_string($language) || ($language !== 'auto'
            && preg_match('/\A[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?\z/D', $language) !== 1)) {
            $this->fail('language must be auto or a supported BCP 47 form');
        }
        if (!is_bool($input['speaker_diarization'] ?? null)) {
            $this->fail('speaker_diarization must be a boolean');
        }
        $glossary = $input['glossary'] ?? null;
        if (!is_array($glossary) || !array_is_list($glossary) || count($glossary) > 100) {
            $this->fail('glossary must be a list with at most 100 entries');
        }
        $normalizedGlossary = [];
        foreach ($glossary as $term) {
            if (!is_string($term) || trim($term) === '' || trim($term) !== $term || mb_strlen($term, 'UTF-8') > 80
                || in_array($term, $normalizedGlossary, true)) {
                $this->fail('glossary entries must be unique non-empty strings of at most 80 characters');
            }
            $normalizedGlossary[] = $term;
        }
        $egress = $input['data_egress_policy'] ?? null;
        if (!is_string($egress) || !in_array($egress, ['local_only', 'private_only', 'approved_cloud'], true)) {
            $this->fail('data_egress_policy is unsupported');
        }

        return [
            'file_ref' => $fileRef,
            'language' => $language,
            'speaker_diarization' => $input['speaker_diarization'],
            'glossary' => $normalizedGlossary,
            'data_egress_policy' => $egress,
        ];
    }

    /** @param list<string> $keys */
    private function exactKeys(mixed $value, array $keys, string $field): void
    {
        if (!is_array($value) || array_is_list($value)
            || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== []) {
            $this->fail("{$field} contains unsupported fields");
        }
    }

    private function fail(string $message): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPTION_VALIDATION_ERROR', $message);
    }
}
