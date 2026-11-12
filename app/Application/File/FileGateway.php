<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Agent\AgentFileUsageGateway;
use app\Application\Audio\AudioContentPurgeGateway;
use app\Application\Knowledge\KnowledgeBasePolicy;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\FileInvocationFacts;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Application\Knowledge\KnowledgeSourceReferenceGuard;
use app\Application\Usage\UsageAttribution;
use app\Application\Workflow\WorkflowContentPurgeGateway;
use app\Application\Task\TaskState;
use app\Infrastructure\Storage\ObjectKey;
use app\Infrastructure\Storage\StorageDriverFactory;
use app\Infrastructure\Storage\StorageException;
use Throwable;
use Webman\Http\UploadFile;
use think\facade\Db;
use support\Log;

final class FileGateway
{
    /** States the admin list may show, identical to the sand_ai.file.read whitelist. */
    public const LIST_STATES = ['uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled', 'deleting', 'delete_failed'];

    private const LIST_SCAN_CHUNK = 200;

    /** Durable intent precedes storage; a lost acknowledgement never creates a second file. */
    public function upload(int $environmentId, UploadFile $upload, UsageAttribution $attribution, string $requestKey): array
    {
        if ($attribution->environmentId !== $environmentId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }
        if (!\app\Identity\Infrastructure\RequestAuditId::isValid($requestKey)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid original X-Request-Id is required');
        }
        $db = Db::connect('pgsql');
        // The intent must commit independently before any private-storage write.
        $pdo = $db->getPdo();
        // ThinkORM returns false before this same connection's first transaction.
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_FILE_UPLOAD_TRANSACTION_OPEN', 'Upload must start outside a database transaction');
        }
        $scope = FileOwnershipGuard::scope($attribution);
        $keyHash = hash('sha256', $requestKey);
        $metadata = (new FileUploadPolicy())->inspect($upload);
        $fileId = $db->transaction(function () use ($db, $environmentId, $scope, $keyHash, $metadata, $attribution): int {
            $db->execute("SET LOCAL lock_timeout = '5s'");
            $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sand-ai/upload:' . $scope . ':' . $keyHash]);
            $file = AiFile::withTrashed()->where('environment_id', $environmentId)
                ->where('owner_scope', $scope)->where('upload_request_key_sha256', $keyHash)->lock(true)->find();
            if ($file !== null) {
                (new FileOwnershipGuard())->assert($file, $scope);
                foreach ($metadata as $field => $value) {
                    $stored = $field === 'size_bytes' ? (int) $file->$field : (string) $file->$field;
                    if ($stored !== $value) {
                        throw new ApiProblem('SAND_AI_FILE_UPLOAD_KEY_CONFLICT', 'The original upload request key is bound to different file facts');
                    }
                }
                return (int) $file->id;
            }
            $driver = StorageDriverFactory::create();
            $file = AiFile::create([
                'environment_id' => $environmentId, 'owner_scope' => $scope,
                'owner_attribution' => [...$attribution->toPersisted(), 'data_class' => null],
                'upload_request_key_sha256' => $keyHash,
                'file_ref' => OpaqueFileReference::new(), ...$metadata,
                'storage_driver' => $driver->code(), 'object_key' => ObjectKey::generate($metadata['extension']),
                'state' => 'upload_pending', 'status' => 1,
            ]);
            return (int) $file->id;
        });
        try {
            $db->transaction(function () use ($db, $fileId, $scope, $upload, $environmentId, $metadata): void {
                $db->execute("SET LOCAL lock_timeout = '5s'");
                $file = AiFile::withTrashed()->where('id', $fileId)->lock(true)->find();
                if ($file === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
                (new FileOwnershipGuard())->assert($file, $scope);
                if ((string) $file->state !== 'upload_pending') return;
                // Serializes identical retries and deletion. Both supported drivers
                // replace this one private object with the same inspected bytes.
                StorageDriverFactory::create((string) $file->storage_driver)->uploadFile(
                    (string) $file->object_key, $upload->getPathname(), $metadata['media_type'],
                );
                $file->save(['state' => 'uploaded']);
                $this->audit($environmentId, 'file.upload', $fileId, 'Uploaded SandAI file', [
                    'sha256' => $metadata['sha256'], 'size_bytes' => $metadata['size_bytes'],
                ]);
            });
        } catch (StorageException $exception) {
            // Never delete after an uncertain storage/commit outcome. The retained
            // intent permits exact-byte retry or an authorized normal deletion.
            throw new ApiProblem('SAND_AI_FILE_UPLOAD_PENDING', 'Upload outcome is not yet committed; read the original request before retrying', true);
        }
        $file = AiFile::withTrashed()->where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($file === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        (new FileOwnershipGuard())->assert($file, $scope);
        // file.write returns only the upload receipt, never parse/task/read DTOs.
        return $this->uploadReceipt($file);
    }

    /** @return array<string,mixed> */
    private function uploadReceipt(AiFile $file): array
    {
        $result = [
            'id' => (int) $file->id,
            'original_name' => (string) $file->original_name,
            'extension' => (string) $file->extension,
            'media_type' => (string) $file->media_type,
            'size_bytes' => (int) $file->size_bytes,
            'sha256' => (string) $file->sha256,
            'state' => (string) $file->state,
        ];
        if ((string) $file->state !== 'deleted' && is_string($file->file_ref) && $file->file_ref !== '') {
            $result['file_ref'] = (string) $file->file_ref;
        }
        return $result;
    }

    /** Pure metadata lookup: no object read/write, task creation or retry. */
    public function uploadByRequestKey(int $environmentId, string $scope, string $requestKey): array
    {
        if (!\app\Identity\Infrastructure\RequestAuditId::isValid($requestKey)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid original X-Request-Id is required');
        }
        $file = AiFile::withTrashed()->where('environment_id', $environmentId)->where('owner_scope', $scope)
            ->where('upload_request_key_sha256', hash('sha256', $requestKey))->find();
        if ($file === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'No committed upload association exists for this request');
        (new FileOwnershipGuard())->assert($file, $scope);
        if ((string) $file->state === 'upload_pending') {
            return ['state' => 'pending', 'file' => null];
        }
        return ['state' => 'resolved', 'file' => $this->read($environmentId, (int) $file->id, $scope)];
    }

    /** @return array<string, mixed> */
    public function read(int $environmentId, int $fileId, string $trustedIdentityScope): array
    {
        $file = AiFile::withTrashed()->where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($file !== null && (string) $file->state === 'deleted'
            && (int) $file->status === 2 && $file->delete_time !== null) {
            (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);
            // Deleted resources expose only the retained metadata and committed
            // cleanup projection. No parse, source blocks or storage locator.
            $result = $this->summary($file);
            unset($result['file_ref']);
            $result['latest_parse'] = null;
            return $result;
        }
        return $this->summary($this->fileForEnvironment($environmentId, $fileId, $trustedIdentityScope), true, $trustedIdentityScope);
    }

    /** @return array<string, mixed> */
    public function destroy(int $environmentId, int $fileId, string $trustedIdentityScope): array
    {
        Db::connect('pgsql')->transaction(function () use ($environmentId, $fileId, $trustedIdentityScope): void {
            Db::connect('pgsql')->execute("SET LOCAL lock_timeout = '5s'");
            $guard = new KnowledgeSourceReferenceGuard(); $guard->lockFile($fileId);
            $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->lock(true)->find();
            if ($file === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
            (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);
            if ((int) $file->status !== 1 || $file->delete_time !== null
                || !in_array((string) $file->state, [
                    'uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled',
                    'delete_failed', 'deleting', 'upload_pending',
                ], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
            }
            $guard->rejectPublishedReference($fileId);
            $activeParse = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_task_run
                 WHERE environment_id=? AND resource_type='file' AND resource_id=?
                   AND task_type='file_parse' AND state NOT IN ('succeeded','failed','canceled')
                 LIMIT 1",
                [$environmentId, $fileId],
            );
            if ($activeParse !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '这份资料还在解析中，先完成或取消解析再删除');
            }
            $activeWorkflow = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_workflow_run_file rf
                 JOIN sand_ai_workflow_run r ON r.id=rf.workflow_run_id
                 JOIN sand_ai_task_run t ON t.id=r.task_id
                 WHERE rf.file_id=? AND r.environment_id=? AND r.status=1 AND r.delete_time IS NULL
                   AND t.task_type='structured_workflow'
                   AND t.state NOT IN ('succeeded','failed','canceled')
                 LIMIT 1",
                [$fileId, $environmentId],
            );
            if ($activeWorkflow !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '还有工作流正在用这份资料，先完成或取消再删除');
            }
            $activeAudio = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_task_run
                 WHERE environment_id=? AND status=1 AND delete_time IS NULL
                   AND state NOT IN ('succeeded','failed','canceled')
                   AND ((task_type='audio_transcription' AND input->>'file_id'=?)
                     OR (task_type='ai_completion' AND input #>> '{_meeting_summary,file_id}'=?))
                 LIMIT 1",
                [$environmentId, (string) $fileId, (string) $fileId],
            );
            if ($activeAudio !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '还有转写或纪要任务在用这份资料，先完成或取消再删除');
            }
            $activeAiTask = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_task_run task
                 WHERE task.environment_id=? AND task.task_type='ai_completion'
                   AND NOT jsonb_exists(task.input, '_meeting_summary')
                   AND task.state NOT IN ('succeeded','failed','canceled')
                   AND EXISTS (
                       SELECT 1 FROM sand_ai_source_block block
                       WHERE block.file_id=?
                         AND task.input->'source_block_ids' @> jsonb_build_array(block.id)
                   ) LIMIT 1",
                [$environmentId, $fileId],
            );
            if ($activeAiTask !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '还有 AI 任务在用这份资料，先完成或取消再删除');
            }
            // Source registration holds this file row lock and rejects deleting files.
            // Existing uncertain runs still protect their own recorded source files.
            $activeAgent = Db::connect('pgsql')->query(
                "SELECT 1 FROM sand_ai_task_run task
                 WHERE task.environment_id=? AND task.task_type='agent_run'
                   AND task.state NOT IN ('succeeded','failed','canceled')
                   AND (EXISTS (
                       SELECT 1 FROM sand_ai_agent_run_file usage
                       WHERE usage.task_id=task.id AND usage.file_id=?
                   ) OR COALESCE(task.result->'source_references','[]'::jsonb) @> ?::jsonb)
                 LIMIT 1",
                [$environmentId, $fileId, json_encode([['file_id' => $fileId]], JSON_THROW_ON_ERROR)],
            );
            if ($activeAgent !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '还有智能体任务在用这份资料，先完成再删除');
            }
            if ((string) $file->state !== 'deleting') {
                $file->save(['state' => 'deleting']);
            }
        });
        try {
            // Hold the row lock through the external delete. A second retry
            // waits for this attempt, while a crashed attempt releases the
            // lock and leaves its deleting row available to the same owner.
            Db::connect('pgsql')->transaction(function () use ($environmentId, $fileId, $trustedIdentityScope): void {
            Db::connect('pgsql')->execute("SET LOCAL lock_timeout = '5s'");
                $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->lock(true)->find();
                if ($file === null || (int) $file->status !== 1 || $file->delete_time !== null
                    || (string) $file->state !== 'deleting') {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
                }
                (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);
                (new KnowledgeSourceReferenceGuard())->rejectPublishedReference($fileId);
                (new OcrUsageLedger())->assertSettledForFile($environmentId, $fileId);
                $workflowPurge = (new WorkflowContentPurgeGateway())->purgeForFile($environmentId, $fileId);
                $audioPurge = (new AudioContentPurgeGateway())->purgeForFile($environmentId, $fileId);
                $agentPurge = (new AgentFileUsageGateway())->purgeForFile($environmentId, $fileId);
                $aiTaskPurge = $this->purgeAiTaskContentForFile($environmentId, $fileId);
                $sourceBlocksPurged = Db::connect('pgsql')->execute(
                    "UPDATE sand_ai_source_block
                     SET content='',content_sha256=?,status=2,
                         delete_time=COALESCE(delete_time,CURRENT_TIMESTAMP(0)),
                         update_time=CURRENT_TIMESTAMP(0)
                     WHERE file_id=? AND (content<>'' OR status<>2 OR delete_time IS NULL)",
                    [hash('sha256', ''), $fileId],
                );
                $parsesPurged = Db::connect('pgsql')->execute(
                    'UPDATE sand_ai_file_parse
                     SET status=2,delete_time=COALESCE(delete_time,CURRENT_TIMESTAMP(0)),
                         update_time=CURRENT_TIMESTAMP(0)
                     WHERE file_id=? AND delete_time IS NULL',
                    [$fileId],
                );
                $file->save(['state' => 'deleted', 'status' => 2]);
                $file->delete();
                $toolFulltextPurge = (new \app\Application\Tool\ToolFulltextFileGateway())->purgeForFile($environmentId, $fileId);
                $this->audit($environmentId, 'file.delete', $fileId, 'Deleted SandAI file', [
                    'source_blocks_purged' => $sourceBlocksPurged,
                    'parses_purged' => $parsesPurged,
                    'workflow_content_purged' => $workflowPurge,
                    'audio_content_purged' => $audioPurge,
                    'agent_content_purged' => $agentPurge,
                    'ai_task_content_purged' => $aiTaskPurge,
                    'tool_fulltext_content_purged' => $toolFulltextPurge,
                ]);
                StorageDriverFactory::create((string) $file->storage_driver)->delete((string) $file->object_key);
            });
        } catch (StorageException $exception) {
            Db::connect('pgsql')->transaction(function () use ($environmentId, $fileId, $trustedIdentityScope): void {
            Db::connect('pgsql')->execute("SET LOCAL lock_timeout = '5s'");
                $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->lock(true)->find();
                if ($file === null || (string) $file->state !== 'deleting') return;
                (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);
                $file->save(['state' => 'delete_failed']);
                $this->audit($environmentId, 'file.delete_failed', $fileId, 'SandAI file delete failed', []);
            });
            throw new ApiProblem('SAND_AI_STORAGE_UNAVAILABLE', 'Private storage delete is unavailable', true);
        }
        $deleted = AiFile::withTrashed()->where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($deleted === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File metadata not found');
        (new FileOwnershipGuard())->assert($deleted, $trustedIdentityScope);
        return ['id' => $fileId, 'state' => 'deleted', 'cleanup' => (new CleanupStatusProjection())->file($deleted)];
    }

    /** @return array{tasks:int,receipts:int} */
    private function purgeAiTaskContentForFile(int $environmentId, int $fileId): array
    {
        $db = Db::connect('pgsql');
        $tasks = $db->query(
            "SELECT task.id FROM sand_ai_task_run task
             WHERE task.environment_id=? AND task.task_type='ai_completion'
               AND NOT jsonb_exists(task.input, '_meeting_summary')
               AND EXISTS (
                   SELECT 1 FROM sand_ai_source_block block
                   WHERE block.file_id=?
                     AND task.input->'source_block_ids' @> jsonb_build_array(block.id)
               ) ORDER BY task.id",
            [$environmentId, $fileId],
        );
        $counts = ['tasks' => 0, 'receipts' => 0];
        foreach ($tasks as $row) {
            $taskId = (int) $row['id'];
            try {
                // Retry paths lock task then file. Refuse contention here while
                // holding the file lock instead of waiting in the reverse order.
                $locked = $db->query(
                    "SELECT state FROM sand_ai_task_run
                     WHERE id=? AND environment_id=? AND task_type='ai_completion' FOR UPDATE NOWAIT",
                    [$taskId, $environmentId],
                );
            } catch (\think\db\exception\PDOException $failure) {
                if (($failure->getData()['PDO Error Info']['SQLSTATE'] ?? null) !== '55P03') throw $failure;
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'AI 任务状态正在变化，请稍后重试删除');
            }
            if (count($locked) !== 1 || !TaskState::terminal((string) $locked[0]['state'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '还有 AI 任务没结束，先完成或取消再删除资料');
            }
            // Cancellation can make the task terminal while its provider
            // outcome remains unknown. Keep retained output for reconciliation.
            $unsettled = $db->query(
                "SELECT 1 FROM sand_ai_invocation invocation
                 LEFT JOIN sand_ai_invocation_dispatch dispatch ON dispatch.invocation_id=invocation.id
                 WHERE invocation.environment_id=? AND invocation.parent_task_run_id=?
                   AND (invocation.state NOT IN ('succeeded','failed')
                     OR dispatch.state IN ('prepared','dispatching','ambiguous'))
                 LIMIT 1",
                [$environmentId, $taskId],
            );
            if ($unsettled !== []) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '这份资料的 AI 调用还没有处理完，请处理完后再删除');
            }
            $counts['tasks'] += $db->execute(
                'UPDATE sand_ai_task_run SET result=NULL,content_purged_time=CURRENT_TIMESTAMP(0),
                    update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND environment_id=? AND content_purged_time IS NULL',
                [$taskId, $environmentId],
            );
            $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='expired',cleanup_owner='sand-ai-task-file-purge',
                     cleanup_claim_time=CURRENT_TIMESTAMP(0),
                     cleanup_fence_version=cleanup_fence_version+1,update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state IN ('available','expired') AND receipt.environment_id=?
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='ai_task'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
            $counts['receipts'] += $db->execute(
                "UPDATE sand_ai_invocation_outcome_receipt receipt
                 SET state='destroyed',key_id=NULL,plaintext_bytes=NULL,ciphertext_bytes=NULL,
                     content_hmac=NULL,nonce=NULL,tag=NULL,ciphertext=NULL,
                     cleanup_owner=NULL,cleanup_claim_time=NULL,
                     destroy_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE receipt.state='expired' AND receipt.environment_id=?
                   AND receipt.cleanup_owner='sand-ai-task-file-purge'
                   AND receipt.invocation_id IN (
                       SELECT dispatch.invocation_id FROM sand_ai_invocation_dispatch dispatch
                       WHERE dispatch.environment_id=? AND dispatch.parent_task_run_id=?
                         AND dispatch.execution_scope='ai_task'
                   )",
                [$environmentId, $environmentId, $taskId],
            );
        }
        return $counts;
    }

    /**
     * Resume a deletion whose authorized request already committed `deleting`.
     * No caller identity is accepted here; the original ownership snapshot
     * must still validate before any cleanup or storage operation.
     */
    public function resumeDeleting(int $fileId): void
    {
        $file = AiFile::where('id', $fileId)->where('state', 'deleting')
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null) return;
        $scope = $file->owner_scope;
        if (!is_string($scope)) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'File ownership snapshot is invalid');
        }
        try {
            $this->destroy((int) $file->environment_id, $fileId, $scope);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_RESOURCE_NOT_FOUND') throw $problem;
            // A concurrent authorized request may have completed the same deletion.
            $current = AiFile::where('id', $fileId)->find();
            if ($current !== null && (string) $current->state !== 'deleted') throw $problem;
        }
    }

    /**
     * Admin file list. Ownership matches the single-file read contract: only
     * rows whose owner_attribution resolves to this environment's owner are
     * listed; the rest are skipped silently rather than throwing. Ownership is
     * verified in PHP (same approach as eligibleFiles), so SQL filters state
     * and keyword first, then a chunked scan applies ownership before paging.
     *
     * @param list<string>|null $states every readable state when null
     * @return array{current_page:int,per_page:int,total:int,data:list<array<string,mixed>>}
     */
    public function index(int $environmentId, int $page, int $limit, ?string $keyword, ?array $states): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list pagination is invalid');
        }
        $states ??= self::LIST_STATES;
        foreach ($states as $state) {
            if (!in_array($state, self::LIST_STATES, true)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list state filter is invalid');
            }
        }
        if ($keyword !== null && mb_strlen($keyword, 'UTF-8') > 128) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'File list keyword is invalid');
        }
        try {
            $owner = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        if ($states === []) {
            return ['current_page' => $page, 'per_page' => $limit, 'total' => 0, 'data' => []];
        }
        $conditions = 'f.environment_id=? AND f.status=1 AND f.delete_time IS NULL';
        $parameters = [$environmentId];
        $conditions .= ' AND f.state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        foreach ($states as $state) {
            $parameters[] = $state;
        }
        if ($keyword !== null && $keyword !== '') {
            $conditions .= " AND f.original_name ILIKE ? ESCAPE '\\\\'";
            $parameters[] = '%' . KnowledgeBasePolicy::escapedLike($keyword) . '%';
        }
        $matched = [];
        $offset = 0;
        do {
            $rows = Db::connect('pgsql')->query(
                "SELECT f.id,f.original_name,f.size_bytes,f.media_type,f.extension,f.sha256,f.state,
                        f.status,f.delete_time,f.environment_id,f.owner_scope,f.owner_attribution,f.create_time
                 FROM sand_ai_file f WHERE {$conditions} ORDER BY f.id DESC LIMIT ? OFFSET ?",
                [...$parameters, self::LIST_SCAN_CHUNK, $offset],
            );
            foreach ($rows as $row) {
                if ($this->isOwnedByEnvironment($row, $owner)) {
                    $matched[] = $row;
                }
            }
            $offset += self::LIST_SCAN_CHUNK;
        } while (count($rows) === self::LIST_SCAN_CHUNK);
        $pageRows = array_slice($matched, ($page - 1) * $limit, $limit);
        $failures = $this->latestParseFailures(
            array_map(static fn (array $row): int => (int) $row['id'], $pageRows),
        );
        $items = [];
        foreach ($pageRows as $row) {
            $fileId = (int) $row['id'];
            $items[] = [
                'id' => $fileId,
                'original_name' => (string) $row['original_name'],
                'size_bytes' => (int) $row['size_bytes'],
                'media_type' => (string) $row['media_type'],
                'extension' => (string) $row['extension'],
                'sha256' => (string) $row['sha256'],
                'state' => (string) $row['state'],
                'failure_text' => $failures[$fileId] ?? null,
                'create_time' => self::offsetTime($row['create_time']),
            ];
        }
        return ['current_page' => $page, 'per_page' => $limit, 'total' => count($matched), 'data' => $items];
    }

    /** @param array<string,mixed> $row @param array{organization_id:int,application_id:int,environment_id:int} $owner */
    private function isOwnedByEnvironment(array $row, array $owner): bool
    {
        $attribution = $row['owner_attribution'] ?? null;
        if (is_string($attribution)) {
            try {
                $attribution = json_decode($attribution, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }
        }
        try {
            $facts = FileInvocationFacts::fromPersisted([
                'id' => $row['id'], 'status' => $row['status'], 'delete_time' => $row['delete_time'],
                'state' => $row['state'], 'environment_id' => $row['environment_id'],
                'owner_scope' => $row['owner_scope'], 'owner_attribution' => $attribution,
            ], (int) $row['id'], 'sand_ai.file.read');
        } catch (IdentityContextException) {
            return false;
        }
        return $facts['environment_id'] === $owner['environment_id']
            && $facts['organization_id'] === $owner['organization_id']
            && $facts['application_id'] === $owner['application_id'];
    }

    /** @param list<int> $fileIds @return array<int,string> file_id => operator-facing failure text */
    private function latestParseFailures(array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($fileIds), '?'));
        $rows = Db::connect('pgsql')->query(
            "SELECT DISTINCT ON (file_id) file_id,state,error_code
             FROM sand_ai_file_parse
             WHERE file_id IN ({$marks}) AND status=1 AND delete_time IS NULL
             ORDER BY file_id,id DESC",
            $fileIds,
        );
        $failures = [];
        foreach ($rows as $row) {
            if ((string) $row['state'] !== 'failed') {
                continue;
            }
            $failures[(int) $row['file_id']] = self::failureText(
                $row['error_code'] === null ? null : (string) $row['error_code'],
            );
        }
        return $failures;
    }

    /** Pages get plain-language failure text only; error codes and raw summaries stay out of the list. */
    public static function failureText(?string $code): string
    {
        if ($code === null || $code === '') {
            return '处理失败，请重试';
        }
        return match (true) {
            str_contains($code, 'QUEUE_FAILED') => '提交处理失败，请点重试',
            str_contains($code, 'UNSUPPORTED') => '当前文件类型没有可用解析驱动',
            str_contains($code, 'EMPTY') => '没有从文件里解析出可用内容',
            str_contains($code, 'CAPABILITY') || str_contains($code, 'NOT_PUBLISHED') => '解析能力配置不可用，请检查能力配置后重试',
            str_contains($code, 'OCR') => '图片或扫描件识别不可用，请检查 OCR 配置后重试',
            str_contains($code, 'BUDGET') => 'AI 预算不足或已用完，请到费用页处理后重试',
            str_contains($code, 'STORAGE') => '文件存储暂时不可用，请稍后重试',
            str_contains($code, 'LEASE') || str_contains($code, 'TIMEOUT') => '处理超时，请重试',
            str_contains($code, 'IDENTITY') || str_contains($code, 'FORBIDDEN') || str_contains($code, 'AUTHORIZATION') => '处理授权已失效，请重试',
            default => '处理失败，请稍后重试',
        };
    }

    /** Upload time rendered in the host business timezone with its offset. */
    private static function offsetTime(mixed $value): ?string
    {
        $zone = function_exists('config') ? config('app.default_timezone') : null;
        if (!is_string($zone) || !in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            $zone = 'Asia/Shanghai';
        }
        try {
            $time = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : (is_string($value) && $value !== '' ? new \DateTimeImmutable($value, new \DateTimeZone($zone)) : null);
        } catch (\Exception) {
            return null;
        }
        return $time?->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i:sP');
    }

    private function fileForEnvironment(int $environmentId, int $fileId, string $trustedIdentityScope): AiFile
    {
        $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null || !in_array((string) $file->state, [
            'uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled',
            'deleting', 'delete_failed',
        ], true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }
        (new FileOwnershipGuard())->assert($file, $trustedIdentityScope);

        return $file;
    }

    /** @return array<string, mixed> */
    private function summary(AiFile $file, bool $includeLatestParse = false, ?string $trustedIdentityScope = null): array
    {
        $summary = [
            'id' => (int) $file->id,
            'original_name' => (string) $file->original_name,
            'extension' => (string) $file->extension,
            'media_type' => (string) $file->media_type,
            'size_bytes' => (int) $file->size_bytes,
            'sha256' => (string) $file->sha256,
            'state' => (string) $file->state,
            'create_time' => $file->create_time,
            'update_time' => $file->update_time,
            'cleanup' => (new CleanupStatusProjection())->file($file),
        ];
        if (is_string($file->file_ref ?? null) && $file->file_ref !== '') {
            $summary['file_ref'] = (string) $file->file_ref;
        }
        if ($includeLatestParse) {
            // Cleanup states expose metadata only; parsing/source content remains unavailable.
            $summary['latest_parse'] = in_array((string) $file->state, ['deleting', 'delete_failed'], true)
                ? null
                : (new FileParseGateway())->latest((int) $file->id, $trustedIdentityScope);
        }

        return $summary;
    }


    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $fileId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'api_credential',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'file',
            'resource_id' => $fileId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
