<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class ArtifactManifestConsistencyPolicy
{
    /**
     * @param array<string,mixed> $expectedPayload
     * @param list<array{field_path:string,source_block_id:int}> $expectedSources
     * @param list<array{field_path:string,source_block_id:int}> $persistedSources
     */
    public function assertExisting(string $schemaCode, string $schemaVersion, string $state, int $status, bool $reviewRequired, bool $expectedReviewRequired, mixed $persistedPayload, array $expectedPayload, array $expectedSources, array $persistedSources): void
    {
        if ($schemaCode === '' || $schemaVersion === '' || $state !== 'succeeded' || $status !== 1
            || $reviewRequired !== $expectedReviewRequired
            || !$this->sameJson($persistedPayload, $expectedPayload)
            || $this->sources($persistedSources) !== $this->sources($expectedSources)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_CONFLICT', 'Existing Artifact does not match the validated workflow outcome');
        }
    }

    private function sameJson(mixed $left, mixed $right): bool
    {
        try {
            return hash_equals($this->digest($left), $this->digest($right));
        } catch (\Throwable) {
            return false;
        }
    }

    private function digest(mixed $value): string
    {
        $normalized = json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        return hash('sha256', json_encode($this->canonical($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map($this->canonical(...), $value); }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) { $value[$key] = $this->canonical($item); }
        return $value;
    }

    /** @param list<array{field_path:string,source_block_id:int}> $sources @return list<string> */
    private function sources(array $sources): array
    {
        $normalized = array_map(static fn (array $source): string => (string) ($source['field_path'] ?? '') . "\0" . (int) ($source['source_block_id'] ?? 0), $sources);
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
