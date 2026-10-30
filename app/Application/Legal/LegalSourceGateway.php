<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\File\FileSourceBlockLocator;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\SourceBlock;
use think\facade\Db;

/** Read-only legal source snapshot from files already owned by the calling workload. */
final class LegalSourceGateway
{
    /** @param array<string,mixed> $input @return list<array<string,mixed>> */
    public function normalize(array $input): array
    {
        if (array_diff(array_keys($input), ['sources']) !== []) {
            $this->invalid();
        }
        $sources = $input['sources'] ?? null;
        if (!is_array($sources) || !array_is_list($sources) || $sources === [] || count($sources) > 10) {
            $this->invalid();
        }
        $normalized = [];
        $sourceRefs = [];
        $blockIds = [];
        foreach ($sources as $source) {
            if (!is_array($source) || array_is_list($source)
                || array_diff(array_keys($source), ['source_ref', 'source_version', 'declared_effect_status', 'file_id', 'file_sha256', 'parse_id', 'version_ref', 'source_block_ids']) !== []) {
                $this->invalid();
            }
            $sourceRef = $this->reference($source['source_ref'] ?? null);
            $sourceVersion = $this->reference($source['source_version'] ?? null);
            $effectStatus = array_key_exists('declared_effect_status', $source)
                ? $source['declared_effect_status'] : 'unknown';
            $versionRef = $this->reference($source['version_ref'] ?? null);
            $fileId = $this->positiveId($source['file_id'] ?? null);
            $parseId = $this->positiveId($source['parse_id'] ?? null);
            $fileSha = $source['file_sha256'] ?? null;
            $ids = $source['source_block_ids'] ?? null;
            if (isset($sourceRefs[$sourceRef])
                || !in_array($effectStatus, ['effective', 'amended', 'repealed', 'unknown'], true)
                || !is_string($fileSha)
                || preg_match('/\A[a-f0-9]{64}\z/D', $fileSha) !== 1
                || !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 40) {
                $this->invalid();
            }
            $sourceRefs[$sourceRef] = true;
            $fixedIds = [];
            foreach ($ids as $id) {
                $blockId = $this->positiveId($id);
                if (isset($blockIds[$blockId])) {
                    $this->invalid();
                }
                $blockIds[$blockId] = true;
                $fixedIds[] = $blockId;
            }
            $normalized[] = [
                'source_ref' => $sourceRef, 'source_version' => $sourceVersion,
                ...(array_key_exists('declared_effect_status', $source)
                    ? ['declared_effect_status' => $effectStatus] : []),
                'file_id' => $fileId, 'file_sha256' => $fileSha,
                'parse_id' => $parseId, 'version_ref' => $versionRef,
                'source_block_ids' => $fixedIds,
            ];
        }
        if (count($blockIds) > 40) {
            $this->invalid();
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $sources @return list<int> */
    public function fileIds(array $sources): array
    {
        $ids = array_values(array_unique(array_column($sources, 'file_id')));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param list<array<string,mixed>> $sources @return array{sources:list<array<string,mixed>>} */
    public function resolve(int $environmentId, string $ownerScope, array $sources): array
    {
        if ($environmentId < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $ownerScope) !== 1 || $sources === []) {
            $this->invalid();
        }
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $ownerScope, $sources): array {
            $resolved = [];
            $characters = 0;
            foreach ($this->fileIds($sources) as $fileId) {
                Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_file WHERE environment_id=? AND id=? FOR SHARE',
                    [$environmentId, $fileId],
                );
            }
            foreach ($sources as $source) {
                $file = AiFile::where('environment_id', $environmentId)->where('id', $source['file_id'])
                    ->where('status', 1)->whereNull('delete_time')->find();
                if ($file === null || (string) $file->state !== 'parsed'
                    || !hash_equals((string) $file->sha256, $source['file_sha256'])) {
                    throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'The legal source file snapshot changed');
                }
                (new FileOwnershipGuard())->assert($file, $ownerScope);
                $parse = AiFileParse::where('file_id', (int) $file->id)->order('id', 'desc')->find();
                if ($parse === null || (int) $parse->id !== $source['parse_id']
                    || (string) $parse->state !== 'succeeded'
                    || (int) $parse->status !== 1 || $parse->delete_time !== null) {
                    throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'The legal source parse version changed');
                }
                $blocks = [];
                foreach ($source['source_block_ids'] as $blockId) {
                    $block = SourceBlock::where('id', $blockId)->where('file_id', (int) $file->id)
                        ->where('parse_id', (int) $parse->id)->where('status', 1)->whereNull('delete_time')->find();
                    if ($block === null || !is_string($block->content) || trim($block->content) === ''
                        || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A legal source block changed');
                    }
                    $locator = is_object($block->locator) ? (array) $block->locator : $block->locator;
                    if (!is_array($locator) || !is_string($block->locator_type)) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A legal source locator is invalid');
                    }
                    $characters += mb_strlen((string) $block->content, 'UTF-8');
                    if ($characters > 64000) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The legal source snapshot exceeds 64000 characters');
                    }
                    $blocks[] = [
                        'source_block_id' => (int) $block->id,
                        'content' => (string) $block->content,
                        'content_sha256' => (string) $block->content_sha256,
                        'locator_type' => (string) $block->locator_type,
                        'locator' => FileSourceBlockLocator::project((string) $block->locator_type, $locator),
                    ];
                }
                $resolved[] = [
                    'source_ref' => $source['source_ref'],
                    'source_version' => $source['source_version'],
                    'authority_state' => 'consumer_declared',
                    'declared_effect_status' => $source['declared_effect_status'] ?? 'unknown',
                    'file_id' => (int) $file->id,
                    'file_sha256' => (string) $file->sha256,
                    'parse_id' => (int) $parse->id,
                    'version_ref' => $source['version_ref'],
                    'blocks' => $blocks,
                ];
            }
            return ['sources' => $resolved];
        });
    }

    private function reference(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > 512) {
            $this->invalid();
        }
        return trim($value);
    }

    private function positiveId(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            $this->invalid();
        }
        return $value;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The legal source snapshot request is invalid');
    }
}
