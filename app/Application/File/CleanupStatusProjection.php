<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Metadata only: absence of content or of a cleanup receipt is never proof of deletion. */
final class CleanupStatusProjection
{
    /** @return array<string,mixed> */
    public function file(AiFile $file): array
    {
        $fileState = (string) $file->state;
        $state = match ($fileState) {
            'deleting' => 'pending',
            'delete_failed' => 'failed',
            'deleted' => 'unconfirmed',
            default => 'retained',
        };
        $result = $this->metadata($state, in_array($fileState, [
            'uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled',
        ], true));
        $result['resources']['file'] = $state;
        $result['resources']['derived_text'] = $state;
        $result['resources']['results'] = $state;
        if ($fileState === 'deleted') {
            // The existing delete transaction commits this record only after
            // private storage deletion succeeds. Older/incomplete records stay unknown.
            $receipt = AuditLog::where('action', 'file.delete')->where('resource_type', 'file')
                ->where('resource_id', (int) $file->id)->where('actor_type', 'api_credential')
                ->where('actor_ref', (string) $file->environment_id)->order('id', 'desc')->find();
            $context = $receipt === null ? null
                : (is_object($receipt->context) ? (array) $receipt->context : $receipt->context);
            if (is_array($context)) {
                $sourceCount = $this->count($context['source_blocks_purged'] ?? null);
                $parseCount = $this->count($context['parses_purged'] ?? null);
                if ($sourceCount !== null && $parseCount !== null) {
                    $result['completed_at'] = $receipt->create_time === null ? null : (string) $receipt->create_time;
                    $result['resources']['file'] = 'purged';
                    $result['resources']['derived_text'] = 'purged';
                    $result['source_block_count'] = $sourceCount;
                    $result['parse_record_count'] = $parseCount;
                }
                $completeResults = true;
                foreach ([
                    'workflow_content_purged', 'audio_content_purged', 'agent_content_purged',
                    'ai_task_content_purged', 'tool_fulltext_content_purged',
                ] as $key) {
                    if (!is_array($context[$key] ?? null) && !is_object($context[$key] ?? null)) {
                        $completeResults = false;
                    }
                }
                if ($completeResults && $result['resources']['file'] === 'purged') {
                    $result['state'] = 'purged';
                    $result['resources']['results'] = 'purged';
                }
            }
        }
        // File deletion does not delete knowledge vectors. Only an existing
        // index linked through the actual source ledger proves their retention.
        $vectors = Db::connect('pgsql')->query(
            'SELECT 1 FROM sand_ai_knowledge_embedding_index i
             JOIN sand_ai_knowledge_embedding e ON e.index_id=i.id
             JOIN sand_ai_knowledge_base_source s ON s.knowledge_base_version_id=i.knowledge_base_version_id
             WHERE s.file_id=? AND i.owner_environment_id=? LIMIT 1',
            [(int) $file->id, (int) $file->environment_id],
        );
        $result['resources']['vectors'] = $vectors === [] ? 'unconfirmed' : 'retained';
        if ($vectors === [] && $fileState === 'deleted' && $result['resources']['file'] === 'purged') {
            // An empty source join alone cannot exclude a purged historical index.
            // Keep any environment with an embedding attempt/index uncertain.
            $unused = Db::connect('pgsql')->query(
                "SELECT 1 WHERE
                   NOT EXISTS (SELECT 1 FROM sand_ai_knowledge_base_source WHERE file_id=?)
                   AND NOT EXISTS (SELECT 1 FROM sand_ai_knowledge_embedding e
                       JOIN sand_ai_source_block b ON b.id=e.source_block_id WHERE b.file_id=?)
                   AND NOT EXISTS (SELECT 1 FROM sand_ai_knowledge_embedding_index WHERE owner_environment_id=?)
                   AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch
                       WHERE environment_id=? AND capability_code='embedding')",
                [(int) $file->id, (int) $file->id, (int) $file->environment_id, (int) $file->environment_id],
            );
            if ($unused !== []) $result['resources']['vectors'] = 'not_applicable';
        }
        return $this->withAudioChunks($result, $this->audioChunks(
            (int) $file->environment_id, (string) $file->file_ref, (string) $file->sha256,
            null, (int) $file->id, in_array($fileState, ['deleting', 'delete_failed', 'deleted'], true),
        ));
    }

    /** The task marker concerns its private content, not deletion of its original file or vectors.
     * @return array<string,mixed>
     */
    public function task(TaskRun $task): array
    {
        $purgedTime = $task->content_purged_time;
        // Material cleanup records its authoritative marker on the workflow
        // run; the task row retains the opaque task history.
        if ((string) $task->task_type === 'structured_workflow'
            && (string) $task->resource_type === 'workflow_run') {
            $runs = Db::connect('pgsql')->query(
                'SELECT content_purged_time FROM sand_ai_workflow_run
                 WHERE id=? AND task_id=? AND environment_id=?',
                [(int) $task->resource_id, (int) $task->id, (int) $task->environment_id],
            );
            if (count($runs) === 1 && $runs[0]['content_purged_time'] !== null) {
                $purgedTime = $runs[0]['content_purged_time'];
            }
        }
        $purged = $purgedTime !== null;
        $result = $this->metadata($purged ? 'purged' : 'retained', !$purged);
        $result['completed_at'] = $purged ? (string) $purgedTime : null;
        $result['resources']['results'] = $purged ? 'purged' : 'retained';
        if ((string) $task->task_type === 'file_parse' && (string) $task->resource_type === 'file') {
            $files = Db::connect('pgsql')->query(
                'SELECT state,status,delete_time FROM sand_ai_file WHERE id=? AND environment_id=?',
                [(int) $task->resource_id, (int) $task->environment_id],
            );
            // A parse task's retained result is metadata, not proof that its
            // original private file/source blocks can still be read.
            $result['source_available'] = !$purged && count($files) === 1
                && (int) $files[0]['status'] === 1 && $files[0]['delete_time'] === null
                && in_array($files[0]['state'], [
                    'uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled',
                ], true);
        }
        if ((string) $task->task_type === 'audio_transcription') {
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $request = is_array($input) ? ($input['request'] ?? null) : null;
            $request = is_object($request) ? (array) $request : $request;
            $fileRef = is_array($request) && is_string($request['file_ref'] ?? null) ? $request['file_ref'] : '';
            $fileSha256 = is_array($input) && is_string($input['file_sha256'] ?? null) ? $input['file_sha256'] : '';
            $result = $this->withAudioChunks($result, $this->audioChunks(
                (int) $task->environment_id, $fileRef, $fileSha256, (int) $task->id, null,
                $purged || $task->delete_time !== null || (string) $task->state === 'canceled',
            ));
        }
        return $result;
    }

    /**
     * Reads the existing ownership ledger only. No object locator, I/O or
     * cleanup claim is exposed; missing content is never deletion evidence.
     */
    private function audioChunks(int $environmentId, string $fileRef, string $fileSha256,
        ?int $taskId, ?int $fileId, bool $cleanupRequested): ?string
    {
        if (!\app\Application\Audio\Chunk\AudioChunkRuntimeSchema::present()) return null;
        $scope = $taskId === null ? "t.input->>'file_id'=?" : 't.id=?';
        $scopeId = $taskId === null ? (string) $fileId : $taskId;
        $rows = Db::connect('pgsql')->query(
            "SELECT p.state,(p.sample_count+479999)/480000+1 AS expected_object_count,
                count(o.id) AS object_count,count(o.id) FILTER (WHERE o.state='deleted') AS deleted_count,
                (p.file_ref=? AND p.file_sha256=?
                    AND p.file_ref=t.input #>> '{request,file_ref}'
                    AND p.file_sha256=t.input->>'file_sha256') AS source_matches,
                (t.content_purged_time IS NOT NULL OR t.delete_time IS NOT NULL
                    OR t.state='canceled' OR p.state='cleaning') AS cleanup_requested
             FROM sand_ai_audio_chunk_preparation p
             JOIN sand_ai_task_run t ON t.id=p.task_run_id AND t.environment_id=p.environment_id
             LEFT JOIN sand_ai_audio_chunk_object o ON o.audio_chunk_preparation_id=p.id
             WHERE p.environment_id=? AND t.task_type='audio_transcription' AND " . $scope . "
             GROUP BY p.id,t.id",
            [$fileRef, $fileSha256, $environmentId, $scopeId],
        );
        // No ownership ledger means the optional resource does not apply.
        // It does not emit audio_chunks=purged or manufacture an object count.
        if ($rows === []) return null;
        $state = 'purged';
        foreach ($rows as $row) {
            $owned = in_array($row['source_matches'] ?? null, [true, 1, '1', 't'], true);
            $expected = (int) $row['expected_object_count'];
            $total = (int) $row['object_count'];
            $deleted = (int) $row['deleted_count'];
            if (!$owned || $expected < 1 || $total !== $expected || $deleted < 0 || $deleted > $total
                || !in_array($row['state'], ['preparing', 'sealed', 'cleaning', 'cleaned'], true)
                || ($row['state'] === 'cleaned' && $deleted !== $total)) {
                return 'unconfirmed';
            }
            if ($row['state'] === 'cleaned' && $deleted === $total) continue;
            $requested = $cleanupRequested
                || in_array($row['cleanup_requested'] ?? null, [true, 1, '1', 't'], true);
            if ($requested) $state = 'pending';
            elseif ($state !== 'pending') $state = 'retained';
        }
        return $state;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function withAudioChunks(array $result, ?string $chunkState): array
    {
        if ($chunkState === null) return $result;
        $result['resources']['audio_chunks'] = $chunkState;
        // The original file.delete receipt predates asynchronous chunk removal;
        // the existing chunk ledger retains no actual completion timestamp.
        $result['completed_at'] = null;
        if ($result['state'] === 'purged' && $chunkState !== 'purged') {
            $result['state'] = in_array($chunkState, ['retained', 'pending'], true) ? 'pending' : 'unconfirmed';
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function metadata(string $state, bool $sourceAvailable): array
    {
        return [
            'state' => $state,
            'completed_at' => null,
            'source_available' => $sourceAvailable,
            'resources' => [
                'file' => 'unconfirmed',
                'derived_text' => 'unconfirmed',
                'vectors' => 'unconfirmed',
                'results' => 'unconfirmed',
                // Existing lifecycle operations retain billing/audit and the
                // opaque owner/resource metadata; this is not a zero-cost claim.
                'usage_history' => 'retained',
                'audit_history' => 'retained',
            ],
            'source_block_count' => null,
            'parse_record_count' => null,
        ];
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
