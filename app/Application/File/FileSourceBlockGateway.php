<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\FileInvocationFacts;
use app\Infrastructure\Persistence\Model\SourceBlock;
use support\think\Db;

final class FileSourceBlockGateway
{
    /**
     * @return array{
     *   file:array{id:int,sha256:string,parse_id:int},
     *   blocks:list<array{source_block_id:int,content_sha256:string,locator_type:string,locator:array<string,mixed>,preview:string}>,
     *   next_after_id:int|null
     * }
     */
    public function read(
        int $environmentId,
        int $fileId,
        string $trustedIdentityScope,
        ?string $expectedDataClass,
        ?int $afterId = null,
        int $limit = 40,
        ?int $expectedParseId = null,
    ): array {
        if ($environmentId < 1 || $fileId < 1 || $limit < 1 || $limit > 40
            || ($afterId !== null && $afterId < 1) || ($expectedParseId !== null && $expectedParseId < 1)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Invalid source block page parameters');
        }

        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $fileId, $trustedIdentityScope, $expectedDataClass, $afterId, $limit, $expectedParseId,
        ): array {
            // The existing parse/delete paths lock the file before changing its sources.
            Db::connect('pgsql')->query(
                'SELECT id FROM sand_ai_file WHERE environment_id=? AND id=? FOR SHARE',
                [$environmentId, $fileId],
            );
            $files = new FileGateway();
            $file = $files->read($environmentId, $fileId, $trustedIdentityScope);
            $this->assertClassification($fileId, $expectedDataClass);
            if (($file['state'] ?? null) !== 'parsed') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The file must have a completed current parse');
            }
            $sha256 = $file['sha256'] ?? null;
            if (!is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
                $this->invalidBlock();
            }
            $parseId = $this->currentParseId($fileId);
            if ($expectedParseId !== null && $expectedParseId !== $parseId) {
                $this->parseChanged();
            }
            if ($afterId !== null) {
                $cursor = SourceBlock::where('id', $afterId)->where('file_id', $fileId)
                    ->where('parse_id', $parseId)->where('status', 1)->whereNull('delete_time')->find();
                if ($cursor === null) {
                    throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'after_id does not belong to the current file parse');
                }
            }
            $query = SourceBlock::where('file_id', $fileId)->where('parse_id', $parseId)
                ->where('status', 1)->whereNull('delete_time')->order('id', 'asc')->limit($limit + 1);
            if ($afterId !== null) {
                $query->where('id', '>', $afterId);
            }
            $rows = $query->select();
            $blocks = [];
            $hasMore = false;
            foreach ($rows as $row) {
                if (count($blocks) === $limit) {
                    $hasMore = true;
                    break;
                }
                $content = $row->content;
                $contentSha256 = $row->content_sha256;
                if ((int) $row->id < 1 || (int) $row->file_id !== $fileId || (int) $row->parse_id !== $parseId
                    || (int) $row->sequence_no < 1 || (int) $row->status !== 1 || $row->delete_time !== null
                    || !is_string($content) || trim($content) === '' || !mb_check_encoding($content, 'UTF-8')
                    || !is_string($contentSha256) || preg_match('/\A[a-f0-9]{64}\z/D', $contentSha256) !== 1
                    || !hash_equals($contentSha256, hash('sha256', $content))
                    || !is_string($row->locator_type)) {
                    $this->invalidBlock();
                }
                $locator = is_object($row->locator) ? (array) $row->locator : $row->locator;
                if (!is_array($locator)) {
                    $this->invalidBlock();
                }
                $blocks[] = [
                    'source_block_id' => (int) $row->id,
                    'content_sha256' => $contentSha256,
                    'locator_type' => $row->locator_type,
                    'locator' => FileSourceBlockLocator::project($row->locator_type, $locator),
                    'preview' => mb_substr($content, 0, 500, 'UTF-8'),
                ];
            }

            // Keep the page bound to the same owned file and current parse until it is complete.
            $currentFile = $files->read($environmentId, $fileId, $trustedIdentityScope);
            $this->assertClassification($fileId, $expectedDataClass);
            if (($currentFile['state'] ?? null) !== 'parsed'
                || !is_string($currentFile['sha256'] ?? null)
                || !hash_equals($sha256, $currentFile['sha256'])
                || $this->currentParseId($fileId) !== $parseId) {
                $this->parseChanged();
            }
            return [
                'file' => ['id' => $fileId, 'sha256' => $sha256, 'parse_id' => $parseId],
                'blocks' => $blocks,
                'next_after_id' => $hasMore ? $blocks[count($blocks) - 1]['source_block_id'] : null,
            ];
        });
    }

    private function currentParseId(int $fileId): int
    {
        // Include inactive/deleted rows in the latest-id check; neither can revive an older success.
        $rows = Db::connect('pgsql')->query(
            'SELECT id,state,status,delete_time FROM sand_ai_file_parse WHERE file_id=? ORDER BY id DESC LIMIT 1',
            [$fileId],
        );
        $parse = $rows[0] ?? null;
        if (!is_array($parse) || (int) ($parse['id'] ?? 0) < 1 || ($parse['state'] ?? null) !== 'succeeded'
            || (int) ($parse['status'] ?? 0) !== 1 || !array_key_exists('delete_time', $parse)
            || $parse['delete_time'] !== null) {
            $this->parseChanged();
        }
        return (int) $parse['id'];
    }

    private function parseChanged(): never
    {
        throw new ApiProblem('SAND_AI_SNAPSHOT_FILE_MISMATCH', 'The current file parse changed or is not available');
    }

    private function assertClassification(int $fileId, ?string $expectedDataClass): void
    {
        try {
            $facts = (new FileInvocationFacts())->resolve('sand_ai', 'sand_ai.file.read', (string) $fileId);
        } catch (IdentityContextException) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'The current file classification is unavailable');
        }
        if ($facts['data_class'] !== $expectedDataClass) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'The file classification changed during the read');
        }
    }

    private function invalidBlock(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A source block has invalid content or source evidence');
    }
}
