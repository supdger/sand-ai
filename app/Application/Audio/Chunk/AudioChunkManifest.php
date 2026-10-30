<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;
use app\Application\Audio\VerifiedPrivateAudioReference;

/** Frozen internal manifest. Opaque chunk refs are never exposed by a public result presenter. */
final readonly class AudioChunkManifest
{
    /** @var list<array<string,int|string>> */
    public array $chunks;
    public string $fileRef;
    public string $fileSha256;
    public string $mediaType;

    /** @param list<array<string,int|string>> $chunks */
    public function __construct(
        VerifiedPrivateAudioReference $source,
        public string $normalizedSha256,
        public AudioChunkPlan $plan,
        array $chunks,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $normalizedSha256) !== 1
            || !array_is_list($chunks) || count($chunks) !== $plan->count()) self::invalid();
        $canonical = [];
        $refs = [];
        foreach ($chunks as $index => $chunk) {
            $expected = $plan->chunk($index + 1);
            if (!is_array($chunk) || count($chunk) !== 8) self::invalid();
            foreach ($expected as $key => $value) {
                if (($chunk[$key] ?? null) !== $value) self::invalid();
            }
            $ref = $chunk['chunk_ref'] ?? null;
            $sha = $chunk['content_sha256'] ?? null;
            if (!is_string($ref) || preg_match('/\Asaac_[a-f0-9]{32}\z/D', $ref) !== 1
                || isset($refs[$ref]) || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) self::invalid();
            $refs[$ref] = true;
            $canonical[] = $expected + ['chunk_ref' => $ref, 'content_sha256' => $sha];
        }
        $this->fileRef = $source->fileRef;
        $this->fileSha256 = $source->contentSha256;
        $this->mediaType = $source->mediaType;
        $this->chunks = $canonical;
    }

    /** @return array<string,mixed> */
    public function frozen(): array
    {
        return ['version' => 1, 'file_ref' => $this->fileRef, 'file_sha256' => $this->fileSha256,
            'media_type' => $this->mediaType, 'normalized_sha256' => $this->normalizedSha256,
            'recipe' => AudioChunkPlan::RECIPE, 'sample_rate' => AudioChunkPlan::SAMPLE_RATE,
            'sample_count' => $this->plan->sampleCount, 'chunks' => $this->chunks];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->frozen(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Stable across worker attempts and lease fences; scoped to one environment/task. */
    public function executionId(int $environmentId, int $taskId, int $sequence): string
    {
        if ($environmentId < 1 || $taskId < 1) self::invalid();
        $this->plan->chunk($sequence);
        return 'audiochunk_' . hash('sha256', $environmentId . '|' . $taskId . '|' . $this->fingerprint() . '|' . $sequence);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_INVALID', 'The private audio chunk manifest is invalid');
    }
}
