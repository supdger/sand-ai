<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Versioned canonical snapshot; no prompt, secret, object key, or provider response is accepted. */
final class ExecutionSnapshotBuilder
{
    public const VERSION = 'execution_snapshot@1';

    /** @param array<string,mixed> $snapshot @return array{snapshot:array<string,mixed>,sha256:string} */
    public function build(array $snapshot): array
    {
        $snapshot['version'] = self::VERSION;
        $snapshot['files'] = $this->normalizeFiles($snapshot['files'] ?? null);
        return ['snapshot' => self::canonical($snapshot), 'sha256' => self::hash($snapshot)];
    }

    /** @param array<string,mixed> $stored @param list<array<string,mixed>> $files */
    public function assertRebuild(array $stored, string $expectedHash, array $files): void
    {
        $rebuilt = $stored;
        $rebuilt['files'] = $this->normalizeFiles($files);
        if (($rebuilt['version'] ?? null) !== self::VERSION || !hash_equals($expectedHash, self::hash($rebuilt))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The persisted execution snapshot no longer matches controlled inputs');
        }
    }

    /** @param list<array<string,mixed>>|mixed $files */
    private function normalizeFiles(mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The execution snapshot files are invalid');
        }
        $normalized = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_int($file['file_id'] ?? null) || !is_int($file['parse_id'] ?? null) || !is_array($file['blocks'] ?? null)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The execution snapshot files are invalid');
            }
            $blocks = [];
            foreach ($file['blocks'] as $block) {
                if (!is_array($block) || !is_int($block['id'] ?? null) || !is_int($block['sequence_no'] ?? null)
                    || !is_string($block['content'] ?? null) || !is_string($block['content_sha256'] ?? null)
                    || !hash_equals($block['content_sha256'], hash('sha256', $block['content']))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A source block content hash is inconsistent');
                }
                // Verify the live content before freezing only its stable, non-sensitive identity.
                $blocks[] = [
                    'id' => $block['id'],
                    'sequence_no' => $block['sequence_no'],
                    'locator_type' => is_string($block['locator_type'] ?? null) ? $block['locator_type'] : '',
                    'locator' => is_array($block['locator'] ?? null) ? $block['locator'] : [],
                    'content_sha256' => $block['content_sha256'],
                ];
            }
            $normalized[] = [
                'file_id' => $file['file_id'],
                'parse_id' => $file['parse_id'],
                'sha256' => is_string($file['sha256'] ?? null) ? $file['sha256'] : '',
                'version_ref' => is_string($file['version_ref'] ?? null) ? $file['version_ref'] : '',
                'blocks' => $blocks,
            ];
        }
        return $normalized;
    }

    /** @param array<string,mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The execution snapshot has an invalid key');
            }
            $result[(string) $key] = self::canonical($item);
        }
        ksort($result, SORT_STRING);
        return $result;
    }
}
