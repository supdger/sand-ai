<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\SourceBlock;

/** Resolves private source blocks and the file facts behind them. */
final class AiTaskSourceFacts
{
    /** @param list<int> $sourceBlockIds */
    public function resolve(int $environmentId, array $sourceBlockIds, UsageAttribution $attribution): ?string
    {
        return $this->resolveFiles($environmentId, $sourceBlockIds, $attribution)['data_class'];
    }

    /**
     * @param list<int> $sourceBlockIds
     * @return array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>}
     */
    public function resolveFiles(int $environmentId, array $sourceBlockIds, UsageAttribution $attribution): array
    {
        $facts = [];
        $fileIds = [];
        $blockFileIds = [];
        $expectedScope = FileOwnershipGuard::scope($attribution);
        foreach ($sourceBlockIds as $sourceBlockId) {
            $block = SourceBlock::where('id', $sourceBlockId)->where('status', 1)->whereNull('delete_time')->find();
            if ($block === null || (int) $block->file_id < 1 || (int) $block->parse_id < 1) {
                self::unverified();
            }
            if (!hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))) {
                self::unverified();
            }
            $fileId = (int) $block->file_id;
            $parse = AiFileParse::where('id', (int) $block->parse_id)->where('file_id', $fileId)
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->find();
            if ($parse === null) {
                self::unverified();
            }
            $latestParse = AiFileParse::where('file_id', $fileId)->where('state', 'succeeded')
                ->where('status', 1)->whereNull('delete_time')->order('id', 'desc')->find();
            if ($latestParse === null || (int) $latestParse->id !== (int) $parse->id) {
                self::unverified();
            }
            $file = AiFile::where('id', $fileId)->where('environment_id', $environmentId)
                ->where('owner_scope', $expectedScope)->where('state', 'parsed')
                ->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null) {
                self::unverified();
            }
            $fileFacts = FileInvocationFacts::fromPersisted([
                'id' => $file->id,
                'status' => $file->status,
                'delete_time' => $file->delete_time,
                'state' => $file->state,
                'environment_id' => $file->environment_id,
                'owner_scope' => $file->owner_scope,
                'owner_attribution' => $file->owner_attribution,
            ], $fileId);
            $facts[] = ['source_block_id' => $sourceBlockId, 'file_id' => $fileId, 'file_facts' => $fileFacts];
            $fileIds[$fileId] = $fileId;
            $blockFileIds[$sourceBlockId] = $fileId;
        }
        return [
            'data_class' => self::fromFacts($sourceBlockIds, $facts, $attribution),
            'file_ids' => array_values($fileIds),
            'block_file_ids' => $blockFileIds,
        ];
    }

    /**
     * @param list<int> $sourceBlockIds
     * @param list<array{source_block_id:int,file_id:int,file_facts:array<string,mixed>}> $facts
     */
    public static function fromFacts(array $sourceBlockIds, array $facts, UsageAttribution $attribution): ?string
    {
        if ($attribution->organizationId === null || $attribution->applicationId === null
            || $attribution->workloadClientId === null || $attribution->environmentId < 1
            || count($sourceBlockIds) !== count($facts)
            || count($sourceBlockIds) !== count(array_unique($sourceBlockIds))) {
            self::unverified();
        }
        $dataClass = null;
        foreach ($sourceBlockIds as $index => $blockId) {
            $item = $facts[$index] ?? null;
            $fileFacts = is_array($item) ? ($item['file_facts'] ?? null) : null;
            if (!is_int($blockId) || $blockId < 1 || !is_array($item)
                || ($item['source_block_id'] ?? null) !== $blockId
                || !is_int($item['file_id'] ?? null) || $item['file_id'] < 1
                || !is_array($fileFacts)
                || ($fileFacts['resource_type'] ?? null) !== 'sand_ai.file'
                || ($fileFacts['resource_ref'] ?? null) !== (string) $item['file_id']
                || ($fileFacts['organization_id'] ?? null) !== $attribution->organizationId
                || ($fileFacts['application_id'] ?? null) !== $attribution->applicationId
                || ($fileFacts['environment_id'] ?? null) !== $attribution->environmentId
                || ($fileFacts['workload_client_id'] ?? null) !== $attribution->workloadClientId
                || !array_key_exists('data_class', $fileFacts)) {
                self::unverified();
            }
            $candidate = $fileFacts['data_class'];
            if ($candidate !== null && (!is_string($candidate)
                || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $candidate) !== 1)) {
                self::unverified();
            }
            if ($index > 0 && $candidate !== $dataClass) {
                self::unverified();
            }
            $dataClass = $candidate;
        }
        return $dataClass;
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>} $facts
     */
    public static function assertMatches(array $matches, array $facts): void
    {
        if (count($matches) !== count($facts['block_file_ids'])
            || count($matches) !== count(array_unique(array_column($matches, 'source_block_id')))) {
            self::unverified();
        }
        foreach ($matches as $match) {
            if (!is_array($match)
                || !is_int($match['source_block_id'] ?? null)
                || !is_int($match['file_id'] ?? null)
                || ($facts['block_file_ids'][$match['source_block_id']] ?? null) !== $match['file_id']) {
                self::unverified();
            }
        }
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'AI task source facts are unavailable');
    }
}
