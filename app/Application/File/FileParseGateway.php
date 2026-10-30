<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Knowledge\KnowledgeSourceReferenceGuard;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Task\TaskInputAccessGuard;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\UsageAttribution;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskState;
use app\Infrastructure\Parsing\ParseDriverFactory;
use app\Infrastructure\Parsing\ParseDriver;
use app\Infrastructure\Parsing\ParseException;
use app\Infrastructure\Parsing\ParseInput;
use app\Infrastructure\Parsing\ParseResult;
use app\Infrastructure\Parsing\NeuronSourceTextSplitter;
use app\Infrastructure\Parsing\PdfOcrCoveragePolicy;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\AiFileParse;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\SourceBlock;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Storage\StorageDriverFactory;
use app\Infrastructure\Storage\StorageException;
use app\Identity\Infrastructure\FileParseAccessGuard;
use support\Log;
use think\facade\Db;
use Throwable;

final class FileParseGateway
{
    public const BEFORE_DISPATCH_RETRY_TARGET = 'file_parse_before_dispatch';

    /** Control metadata only; the original task input and source facts are not rewritten. */
    public function beforeDispatchRetryReceipt(TaskRun $task, bool $lock = false): ?array
    {
        $query = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'parse_file')
            ->where('sequence_no', 1)->where('status', 1)->whereNull('delete_time');
        if ($lock) $query->lock(true);
        $step = $query->find();
        $context = is_array($step?->context) ? $step->context : [];
        if (!array_key_exists('_ocr_before_dispatch_retry', $context)) return null;
        $receipt = $context['_ocr_before_dispatch_retry'];
        if (!is_array($receipt) || count($receipt) !== 6 || ($receipt['version'] ?? null) !== 1
            || ($receipt['target'] ?? null) !== self::BEFORE_DISPATCH_RETRY_TARGET
            || !is_string($receipt['request_key'] ?? null)
            || preg_match('/\Aocr_parse_retry:[a-f0-9]{64}\z/', $receipt['request_key']) !== 1
            || !is_string($receipt['request_fingerprint'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $receipt['request_fingerprint']) !== 1
            || !is_int($receipt['previous_attempt_count'] ?? null) || $receipt['previous_attempt_count'] < 1
            || $receipt['previous_attempt_count'] >= 2147483647
            || !is_int($receipt['retry_attempt_limit'] ?? null)
            || $receipt['retry_attempt_limit'] !== $receipt['previous_attempt_count'] + 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original OCR retry control receipt cannot be verified');
        }
        return $receipt;
    }

    /** Caller holds the original task lock; a late worker must acquire it before dispatch. */
    public function assertBeforeDispatchRetryTask(TaskRun $task, string $scope): TaskStep
    {
        if ((string) $task->task_type !== 'file_parse' || (string) $task->resource_type !== 'file'
            || (string) $task->state !== TaskState::FAILED || (int) $task->status !== 1
            || $task->delete_time !== null || $task->content_purged_time !== null || $task->result !== null
            || (int) $task->attempt_count !== (int) $task->max_attempts
            || (int) $task->attempt_count < 1 || (int) $task->attempt_count >= 2147483647
            || ((new \app\Application\Task\TaskAuthorizationContinuation())->present($task)['required'] ?? false) === true
            || $this->beforeDispatchRetryReceipt($task, true) !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only an unused exhausted local OCR task can recover before dispatch');
        }
        [$file, $parse] = $this->lockedTaskTarget($task, $scope);
        $latest = AiFileParse::where('file_id', (int) $file->id)->where('status', 1)
            ->whereNull('delete_time')->order('id', 'desc')->find();
        if ((int) $file->status !== 1 || $file->delete_time !== null || (string) $file->state !== 'parse_failed'
            || (int) $parse->status !== 1 || $parse->delete_time !== null || (string) $parse->state !== TaskState::FAILED
            || (int) $parse->source_block_count > 0
            || (string) $parse->parser_code !== 'local_paddle_ocr' || (string) $parse->parser_version !== 'http-sidecar'
            || $latest === null || (int) $latest->id !== (int) $parse->id) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original private file and failed OCR parse are not available for this recovery');
        }
        (new OcrUsageLedger())->assertNoDispatchForTask($task);
        $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'parse_file')
            ->where('sequence_no', 1)->where('status', 1)->whereNull('delete_time')->lock(true)->find();
        $context = is_array($step?->context) ? $step->context : [];
        if ($step === null || (string) $step->state !== TaskState::FAILED
            || (int) $step->attempt_count !== (int) $task->attempt_count
            || ($context['file_id'] ?? null) !== (int) $file->id
            || ($context['parse_id'] ?? null) !== (int) $parse->id
            || array_key_exists('_ocr_before_dispatch_retry', $context)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original OCR step changed before recovery');
        }
        return $step;
    }

    /** Safe availability or the accepted one-time control receipt, never request credentials. */
    public function beforeDispatchRetryPermission(TaskRun $task): ?array
    {
        if ((string) $task->task_type !== 'file_parse' || (string) $task->resource_type !== 'file') return null;
        try {
            $receipt = $this->beforeDispatchRetryReceipt($task);
            if ($receipt !== null) {
                return ['target' => self::BEFORE_DISPATCH_RETRY_TARGET, 'available' => false, 'consumed' => true,
                    'previous_attempt_count' => $receipt['previous_attempt_count'], 'retry_attempt_limit' => $receipt['retry_attempt_limit']];
            }
            if ((string) $task->state !== TaskState::FAILED) return null;
            $scope = (new TaskInputAccessGuard())->persistedScope($task);
            return Db::connect('pgsql')->transaction(function () use ($task, $scope): array {
                $current = TaskRun::where('id', (int) $task->id)->where('environment_id', (int) $task->environment_id)
                    ->lock(true)->find();
                if ($current === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Task not found');
                (new TaskInputAccessGuard())->assert($current, $scope);
                $this->assertBeforeDispatchRetryTask($current, $scope);
                return ['target' => self::BEFORE_DISPATCH_RETRY_TARGET, 'available' => true, 'consumed' => false,
                    'previous_attempt_count' => (int) $current->attempt_count, 'retry_attempt_limit' => (int) $current->attempt_count + 1];
            });
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function parse(int $environmentId, int $fileId, string $requestId, UsageAttribution $attribution, array $iamAuthorization): array
    {
        if ($attribution->environmentId !== $environmentId) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $fileId, $requestId, $attribution, $scope, $iamAuthorization): array {
            $replayed = TaskRun::where('environment_id', $environmentId)->where('request_id', $requestId)->find();
            if ($replayed !== null) {
                if ((string) $replayed->task_type !== 'file_parse' || (int) $replayed->resource_id !== $fileId) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                $this->fileForEnvironment($environmentId, $fileId, $scope);
                (new TaskInputAccessGuard())->assert($replayed, $scope);
                $taskSummary = (new TaskGateway())->summary($replayed);
                $result = ['idempotent_replay' => true, 'file_id' => $fileId, 'parse' => $this->parseSummaryForTask($replayed, $taskSummary), 'task' => $taskSummary];
                $this->revalidateSubmission($replayed, $iamAuthorization, true);
                return $result;
            }
            $guard = new KnowledgeSourceReferenceGuard();
            $guard->lockFile($fileId);
            $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->lock(true)->find();
            if ($file === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
            }
            (new FileOwnershipGuard())->assert($file, $scope);
            $guard->rejectPublishedReference($fileId);
            if (in_array((string) $file->state, ['deleting', 'deleted'], true) || (int) $file->status !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The file cannot be parsed in its current state');
            }

            $active = TaskRun::where('environment_id', $environmentId)
                ->where('task_type', 'file_parse')
                ->where('resource_type', 'file')
                ->where('resource_id', $fileId)
                ->whereIn('state', [...TaskState::claimable(), TaskState::RUNNING])
                ->find();
            if ($active !== null) {
                (new TaskInputAccessGuard())->assert($active, $scope);
                $taskSummary = (new TaskGateway())->summary($active);
                $result = ['idempotent_replay' => false, 'file_id' => $fileId, 'parse' => $this->parseSummaryForTask($active, $taskSummary), 'task' => $taskSummary];
                $this->revalidateSubmission($active, $iamAuthorization, true);
                return $result;
            }

            if ((string) $file->state === 'parse_canceled') {
                $latest = AiFileParse::where('file_id', $fileId)->where('status', 1)
                    ->whereNull('delete_time')->order('id', 'desc')->lock(true)->find();
                $parent = $latest === null ? null : TaskRun::where('environment_id', $environmentId)
                    ->where('file_parse_id', (int) $latest->id)->lock(true)->find();
                if ($parent === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '原取消解析的任务证据不可用，请保留原任务核对');
                }
                (new TaskInputAccessGuard())->assert($parent, $scope);
                // The canceled parent lock remains held until this new parse commits.
                // OCR preparation uses the same parent lock and running lease fence.
                if ($this->restartPermission($parent) === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '原取消解析尚不能确认未发送 OCR 请求，请保留原任务核对，不新建解析');
                }
            }

            $file->save(['state' => 'parsing']);
            $parse = AiFileParse::create([
                'file_id' => (int) $file->id,
                'parser_code' => 'pending',
                'parser_version' => 'pending',
                'state' => TaskState::QUEUED,
                'started_at' => date('Y-m-d H:i:s'),
                'status' => 1,
            ]);
            $task = TaskRun::create([
                'environment_id' => $environmentId,
                'task_type' => 'file_parse',
                'resource_type' => 'file',
                'resource_id' => $fileId,
                'file_parse_id' => (int) $parse->id,
                'request_id' => $requestId,
                'state' => TaskState::QUEUED,
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('sand_ai_task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => [
                    'file_id' => $fileId, 'parse_id' => (int) $parse->id,
                    '_usage_attribution' => $attribution->toPersisted(),
                    '_trusted_identity_scope' => $scope,
                    'iam_authorization' => $iamAuthorization,
                ],
                'status' => 1,
            ]);
            TaskStep::create([
                'task_id' => (int) $task->id,
                'step_code' => 'parse_file',
                'sequence_no' => 1,
                'state' => TaskState::QUEUED,
                'attempt_count' => 0,
                'context' => ['file_id' => $fileId, 'parse_id' => (int) $parse->id],
                'status' => 1,
            ]);
            $this->audit($environmentId, 'file.parse_queued', $fileId, 'Queued SandAI file parse', ['parse_id' => (int) $parse->id, 'task_id' => (int) $task->id]);

            $result = ['idempotent_replay' => false, 'file_id' => $fileId, 'parse' => $this->summary($parse), 'task' => (new TaskGateway())->summary($task)];
            $this->revalidateSubmission($task, $iamAuthorization, false);
            return $result;
        });
    }

    /** The original task and parse must stay paired even after a later parse of the same file.
     * @param array<string,mixed> $taskSummary
     * @return array<string,mixed>
     */
    private function parseSummaryForTask(TaskRun $task, array $taskSummary): array
    {
        [$fileId, $parseId] = $this->taskTarget($task);
        $parse = AiFileParse::where('id', $parseId)->where('file_id', $fileId)->find();
        if ($parse === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original file parse record not found');
        }
        return $this->summary($parse) + ['task' => $taskSummary];
    }

    /** Replaying a task never replaces its queued identity; the caller must still be authorized after reads and lock waits.
     * @param array<string,mixed> $currentAuthorization
     */
    private function revalidateSubmission(TaskRun $task, array $currentAuthorization, bool $existing): void
    {
        $access = new FileParseAccessGuard();
        if ($existing && in_array((string) $task->state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $originalAuthorization = is_array($input) ? ($input['iam_authorization'] ?? null) : null;
            if (!is_array($originalAuthorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued file parse authorization is unavailable');
            }
            $access->revalidateQueued($originalAuthorization, $task);
        }
        $access->revalidateQueued($currentAuthorization, $task);
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task, TaskExecutionLease $lease): array
    {
        $scope = (new TaskInputAccessGuard())->persistedScope($task);
        [$fileId, $parseId] = $this->taskTarget($task);
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $authorization = is_array($input) ? ($input['iam_authorization'] ?? null) : null;
        $file = $this->fileForEnvironment((int) $task->environment_id, $fileId, $scope);
        $parse = AiFileParse::where('id', $parseId)->where('file_id', $fileId)->find();
        if ($parse === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File parse record not found');
        }
        try {
            if (!is_array($authorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued file parse authorization is unavailable');
            }
            (new FileParseAccessGuard())->revalidateQueued($authorization, $task);
        } catch (ApiProblem $problem) {
            return $this->fail($task, $lease, $file, $parse, $problem->errorCode, 'File parse authorization was refused', false);
        }

        Db::connect('pgsql')->transaction(function () use ($file, $parse, $lease): void {
            (new TaskGateway())->assertRunningLease($lease);
            $guard = new KnowledgeSourceReferenceGuard();
            $guard->lockFile((int) $file->id);
            $guard->rejectPublishedReference((int) $file->id);
            $file->save(['state' => 'parsing']);
            $parse->save(['state' => 'running', 'error_code' => null, 'error_summary' => null, 'completed_at' => null]);
        });
        $temporaryPath = tempnam(sys_get_temp_dir(), 'sand-ai-parse-');
        if ($temporaryPath === false) {
            return $this->fail($task, $lease, $file, $parse, 'SAND_AI_PARSE_FAILED', 'Parse temporary file could not be created', false);
        }
        $persistenceStage = 'download';

        try {
            StorageDriverFactory::create((string) $file->storage_driver)->downloadFile((string) $file->object_key, $temporaryPath);
            $this->assertDownloadedFileIntegrity($temporaryPath, $file);
            (new TaskGateway())->heartbeat($lease);
            $profileResolver = new CapabilityProfileResolver();
            $capabilityCode = in_array(strtolower((string) $file->extension), ['png', 'jpg', 'jpeg', 'webp'], true)
                ? 'image_ocr'
                : 'document_parse';
            $route = $profileResolver->primary((int) $task->environment_id, $capabilityCode);
            if ($route === null && $profileResolver->hasPublishedProfile((int) $task->environment_id)) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The published capability profile has no route for this file capability');
            }
            $driver = $route === null
                ? ParseDriverFactory::forExtension((string) $file->extension)
                : ParseDriverFactory::forCapabilityDriver((string) $route['driver_code'], (string) $file->extension);
            try {
                $this->assertOcrDispatchAllowed($task, $lease, $parse, $driver);
                $result = $driver->parse($this->parseInput($temporaryPath, $file, $task, $lease, $parse, $driver, $route));
            } catch (ParseException $exception) {
                if ($exception->errorCode !== 'SAND_AI_OCR_REQUIRED' || strtolower((string) $file->extension) !== 'pdf') {
                    throw $exception;
                }
                $ocrRoute = $profileResolver->primary((int) $task->environment_id, 'image_ocr');
                if ($ocrRoute === null) {
                    throw $exception;
                }
                $ocrDriver = ParseDriverFactory::forCapabilityDriver((string) $ocrRoute['driver_code'], (string) $file->extension);
                $this->assertOcrDispatchAllowed($task, $lease, $parse, $ocrDriver);
                $result = $ocrDriver->parse($this->parseInput($temporaryPath, $file, $task, $lease, $parse, $ocrDriver, $ocrRoute));
                (new PdfOcrCoveragePolicy())->assert($exception->requiredSourcePages, $exception->documentPageCount, $result);
            }
            (new TaskGateway())->heartbeat($lease);
            $rows = $this->sourceRows($file, $parse, $result);
            if ($rows === []) {
                throw new ParseException('SAND_AI_PARSE_EMPTY', 'The file contains no extractable source blocks');
            }

            $completedAt = date('Y-m-d H:i:s');
            $taskResult = [
                'file_id' => (int) $file->id,
                'parse' => [
                    'id' => (int) $parse->id,
                    'parser_code' => $result->driverCode,
                    'parser_version' => $result->driverVersion,
                    'state' => TaskState::SUCCEEDED,
                    'source_block_count' => count($rows),
                    'error_code' => null,
                    'error_summary' => null,
                    'started_at' => $parse->started_at,
                    'completed_at' => $completedAt,
                ],
            ];
            (new TaskGateway())->completeFileParse($task, $taskResult, $lease, function () use ($task, $file, $parse, $result, $rows, $completedAt, &$persistenceStage): void {
                $guard = new KnowledgeSourceReferenceGuard();
                $guard->lockFile((int) $file->id);
                $guard->rejectPublishedReference((int) $file->id);
                $this->rejectExistingSourceBlocks((int) $parse->id);
                $persistenceStage = 'source_blocks';
                // Model::create opens an unreleased nested savepoint per row in
                // ThinkORM. Keep the enclosing lease-fenced transaction, while
                // bounding each statement to 250 rows (2,500 parameters).
                foreach (array_chunk($rows, 250) as $batch) {
                    // Pgsql insertAll builds SELECT ... UNION ALL, which resolves
                    // untyped parameters as text before the target-column types apply.
                    $parameters = [];
                    foreach ($batch as $row) {
                        array_push($parameters,
                            $row['file_id'], $row['parse_id'], $row['sequence_no'],
                            $row['locator_type'], json_encode($row['locator'], JSON_THROW_ON_ERROR),
                            $row['content'], $row['content_sha256'], $row['status'],
                            $row['create_time'], $row['update_time'],
                        );
                    }
                    $inserted = Db::connect('pgsql')->execute(
                        'INSERT INTO sand_ai_source_block '
                        . '(file_id,parse_id,sequence_no,locator_type,locator,content,content_sha256,status,create_time,update_time) VALUES '
                        . implode(',', array_fill(0, count($batch), '(?,?,?,?,CAST(? AS jsonb),?,?,?,?,?)')),
                        $parameters,
                    );
                    if ($inserted !== count($batch)) {
                        throw new \RuntimeException('Source block batch was not fully persisted');
                    }
                }
                $persistenceStage = 'parse_record';
                $parse->save([
                    'parser_code' => $result->driverCode,
                    'parser_version' => $result->driverVersion,
                    'state' => 'succeeded',
                    'source_block_count' => count($rows),
                    'completed_at' => $completedAt,
                ]);
                $persistenceStage = 'file_record';
                $file->save(['state' => 'parsed']);
                $this->audit((int) $task->environment_id, 'file.parse', (int) $file->id, 'Parsed SandAI file', ['parse_id' => (int) $parse->id, 'task_id' => (int) $task->id, 'source_block_count' => count($rows)]);
            });
            return $taskResult + ['_terminal_committed' => true];
        } catch (StorageException $exception) {
            return $this->fail($task, $lease, $file, $parse, 'SAND_AI_STORAGE_UNAVAILABLE', 'Private storage download is unavailable', true);
        } catch (ParseException $exception) {
            // Temporary parser failures can retry; unsupported input and
            // missing source content cannot improve by rerunning the worker.
            return $this->fail($task, $lease, $file, $parse, $exception->errorCode, $exception->getMessage(), $exception->retryable());
        } catch (ApiProblem $exception) {
            if (in_array($exception->errorCode, ['SAND_AI_KNOWLEDGE_SOURCE_REFERENCED', 'SAND_AI_TASK_LEASE_LOST'], true)) throw $exception;
            return $this->fail($task, $lease, $file, $parse, $exception->errorCode, $exception->getMessage(), $exception->retryable);
        } catch (Throwable $exception) {
            Log::error('SandAI file parse persistence failed', [
                'file_id' => (int) $file->id,
                'parse_id' => (int) $parse->id,
                'exception_type' => $exception::class,
                'exception_code' => (string) $exception->getCode(),
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
                'persistence_stage' => $persistenceStage,
            ]);
            return $this->fail($task, $lease, $file, $parse, 'SAND_AI_PARSE_FAILED', 'File parsing failed', true);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function assertDownloadedFileIntegrity(string $temporaryPath, AiFile $file): void
    {
        $expectedHash = $file->sha256;
        $expectedSize = $file->size_bytes;
        if (!is_string($expectedHash) || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1
            || (!is_int($expectedSize) && !is_string($expectedSize))
            || preg_match('/\A[1-9][0-9]*\z/', (string) $expectedSize) !== 1) {
            throw new ParseException('SAND_AI_FILE_INVALID', 'The uploaded file integrity metadata is invalid');
        }

        try {
            // Download overwrites an existing temporary file; discard its cached size.
            clearstatcache(true, $temporaryPath);
            $actualSize = @filesize($temporaryPath);
            $actualHash = @hash_file('sha256', $temporaryPath);
        } catch (Throwable $exception) {
            throw new ParseException('SAND_AI_FILE_INVALID', 'The downloaded file integrity could not be read', $exception);
        }
        if (!is_int($actualSize) || !is_string($actualHash)) {
            throw new ParseException('SAND_AI_FILE_INVALID', 'The downloaded file integrity could not be read');
        }
        if ((string) $actualSize !== (string) $expectedSize || !hash_equals($expectedHash, $actualHash)) {
            throw new ParseException('SAND_AI_FILE_INVALID', 'The downloaded file does not match the uploaded file');
        }
    }

    private function assertOcrDispatchAllowed(TaskRun $task, TaskExecutionLease $lease, AiFileParse $parse, ParseDriver $driver): void
    {
        if ($driver->code() !== 'local_paddle_ocr') {
            return;
        }
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $parse, $driver): void {
            $attribution = $this->ocrAttribution($task);
            (new TaskGateway())->assertRunningLease($lease);
            $lockedTask = TaskRun::where('id', (int) $task->id)->lock(true)->find();
            $lockedParse = AiFileParse::where('id', (int) $parse->id)->where('file_id', (int) $parse->file_id)->where('state', 'running')->lock(true)->find();
            if ($lockedTask === null || $lockedParse === null
                || (int) $lockedTask->file_parse_id !== (int) $lockedParse->id
                || (int) $lockedTask->resource_id !== (int) $lockedParse->file_id
                || $this->ocrAttribution($lockedTask)->canonicalTrustedIdentity() !== $attribution->canonicalTrustedIdentity()) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'OCR task identity or parser target changed before dispatch');
            }
            $lockedParse->save(['parser_code' => $driver->code(), 'parser_version' => $driver->version()]);
            AuditLog::create([
                'actor_type' => 'system_worker', 'actor_ref' => (string) $lockedTask->id, 'action' => 'file.ocr_dispatch_prepared',
                'resource_type' => 'file_parse', 'resource_id' => (int) $lockedParse->id,
                'summary' => 'OCR dispatch identity and parser frozen before private sidecar request',
                'context' => ['task_id' => (int) $lockedTask->id, 'file_id' => (int) $lockedParse->file_id,
                    'parser_code' => $driver->code(), 'parser_version' => $driver->version(),
                    'attribution' => $attribution->toPersisted(), 'service_action' => 'sand_ai.document_parse'],
            ]);
        });
    }

    private function parseInput(string $path, AiFile $file, TaskRun $task, TaskExecutionLease $lease, AiFileParse $parse, ParseDriver $driver, ?array $route): ParseInput
    {
        $boundary = null;
        if ($driver->code() === 'local_paddle_ocr') {
            $ledger = new OcrUsageLedger();
            $dispatchId = null;
            $boundary = function (string $event, ?int $status) use ($ledger, $task, $lease, $parse, $driver, $route, &$dispatchId): void {
                if ($event === 'dispatch_started' && $dispatchId === null && $status === null) {
                    $dispatchId = $ledger->start($task, $lease, $parse, $driver, $route);
                    return;
                }
                if (!is_int($dispatchId) || !in_array($event, ['response_known', 'uncertain'], true)) {
                    throw new ApiProblem('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch boundary is invalid');
                }
                $ledger->outcome($dispatchId, $event, $status);
            };
        }
        return new ParseInput($path, (string) $file->extension, (string) $file->media_type, $boundary);
    }

    private function ocrAttribution(TaskRun $task): UsageAttribution
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        try {
            return UsageAttribution::fromCurrentPersisted(
                is_array($input) && is_array($input['_usage_attribution'] ?? null) ? $input['_usage_attribution'] : null,
                (int) $task->environment_id, 'file_parse', 'sand_ai.document_parse',
            )->withCapability('image_ocr');
        } catch (\InvalidArgumentException $exception) {
            throw new ApiProblem('SAND_AI_BUDGET_SCOPE_UNVERIFIED', 'Frozen OCR identity cannot be verified for budget checking');
        }
    }

    /**
     * Lifecycle proof only. A canceled task alone does not prove an OCR request was unsent.
     * @return array{allowed:true,file_id:int,task_id:int}|null
     */
    public function restartPermission(TaskRun $task): ?array
    {
        if ((string) $task->task_type !== 'file_parse' || (string) $task->state !== TaskState::CANCELED) return null;
        try {
            $scope = (new TaskInputAccessGuard())->persistedScope($task);
            return Db::connect('pgsql')->transaction(function () use ($task, $scope): ?array {
                $current = TaskRun::where('id', (int) $task->id)
                    ->where('environment_id', (int) $task->environment_id)->lock(true)->find();
                if ($current === null || (string) $current->state !== TaskState::CANCELED) return null;
                (new TaskInputAccessGuard())->assert($current, $scope);
                [$fileId, $parseId] = $this->taskTarget($current);
                $file = AiFile::where('id', $fileId)->where('environment_id', (int) $current->environment_id)
                    ->where('status', 1)->whereNull('delete_time')->lock(true)->find();
                if ($file === null || (string) $file->state !== 'parse_canceled') return null;
                (new FileOwnershipGuard())->assert($file, $scope);
                $parse = AiFileParse::where('file_id', $fileId)->where('status', 1)
                    ->whereNull('delete_time')->order('id', 'desc')->lock(true)->find();
                if ($parse === null || (int) $parse->id !== $parseId
                    || (string) $parse->state !== TaskState::CANCELED
                    || SourceBlock::where('parse_id', $parseId)->find() !== null) return null;
                $installed = Db::connect('pgsql')->query("SELECT to_regclass('sand_ai_ocr_dispatch') IS NOT NULL AS installed");
                if (!in_array($installed[0]['installed'] ?? null, [true, 't', 1, '1'], true)) return null;
                $sent = Db::connect('pgsql')->query('SELECT 1 FROM sand_ai_ocr_dispatch WHERE task_run_id=? LIMIT 1', [(int) $current->id]);
                if ($sent !== []) return null;
                // Any late OCR start must reacquire this parent lock, then prove a
                // running lease; CANCELED and the cancellation fence reject it.
                return ['allowed' => true, 'file_id' => $fileId, 'task_id' => (int) $current->id];
            });
        } catch (Throwable) {
            // Missing schema, ownership or lifecycle evidence cannot authorize a new send.
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function latest(int $fileId, ?string $trustedIdentityScope = null): ?array
    {
        $parse = AiFileParse::where('file_id', $fileId)->order('id', 'desc')->find();

        if ($parse === null) {
            return null;
        }
        $summary = $this->summary($parse);
        $task = TaskRun::where('file_parse_id', (int) $parse->id)->find();
        if ($task !== null) {
            try {
                (new TaskInputAccessGuard())->assert($task, $trustedIdentityScope);
                $summary['task'] = (new TaskGateway())->summary($task);
            } catch (ApiProblem) {
                // A file may have parse attempts from another workload client.
            }
        }

        return $summary;
    }

    private function fileForEnvironment(int $environmentId, int $fileId, string $scope): AiFile
    {
        $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($file === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }
        (new FileOwnershipGuard())->assert($file, $scope);

        return $file;
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(AiFile $file, AiFileParse $parse, ParseResult $result): array
    {
        // The legacy configuration name is retained; mb_strcut consumes bytes, not characters.
        $maxBytes = min(65536, max(256, (int) config('sand_ai_parse.max_source_block_characters', 12000)));
        $locatedText = $result->driverCode === 'text/plain' && $result->driverVersion === '2.0';
        $rows = [];
        foreach ($result->blocks as $block) {
            $content = $locatedText ? $block->content : trim($block->content);
            if ($locatedText) {
                $locator = FileSourceBlockLocator::project($block->locatorType, $block->locator);
                if ($block->locatorType !== 'line' || !is_int($locator['char_start'] ?? null)
                    || !is_int($locator['char_end'] ?? null) || trim($content) === ''
                    || !mb_check_encoding($content, 'UTF-8') || strlen($content) > 65536
                    || $locator['char_end'] - $locator['char_start'] + 1 !== mb_strlen($content, 'UTF-8')) {
                    throw new ParseException('SAND_AI_PARSE_FAILED', 'Text source positions could not be persisted');
                }
            }
            // A complete short block keeps its position. For a longer block, four bytes per
            // Unicode code point bound the SDK window within the configured UTF-8 byte ceiling.
            $pieces = $locatedText || strlen($content) <= $maxBytes
                ? ($content === '' ? [] : [$content])
                : NeuronSourceTextSplitter::split($content, intdiv($maxBytes, 4));
            $part = 0;
            foreach ($pieces as $piece) {
                $part++;
                if (trim($piece) === '') continue;
                $locator = $block->locator;
                if ($part > 1) {
                    $locator['part'] = $part;
                }
                $rows[] = [
                    'file_id' => (int) $file->id,
                    'parse_id' => (int) $parse->id,
                    'sequence_no' => count($rows) + 1,
                    'locator_type' => $block->locatorType,
                    'locator' => $locator,
                    'content' => $piece,
                    'content_sha256' => hash('sha256', $piece),
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fail(TaskRun $task, TaskExecutionLease $lease, AiFile $file, AiFileParse $parse, string $errorCode, string $message, bool $retryable): array
    {
        (new TaskGateway())->failFileParse($task, $errorCode, $message, $retryable, $lease, function () use ($task, $file, $parse, $errorCode, $message): void {
            $guard = new KnowledgeSourceReferenceGuard();
            $guard->lockFile((int) $file->id);
            $guard->rejectPublishedReference((int) $file->id);
            $this->rejectExistingSourceBlocks((int) $parse->id);
            $parse->save([
                'state' => TaskState::FAILED,
                'error_code' => $errorCode,
                'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $file->save(['state' => 'parse_failed']);
            $this->audit((int) $file->environment_id, 'file.parse_failed', (int) $file->id, 'SandAI file parsing failed', [
                'parse_id' => (int) $parse->id, 'task_id' => (int) $task->id, 'error_code' => $errorCode,
            ]);
        });
        return ['file_id' => (int) $file->id, '_terminal_committed' => true];
    }

    public function cancelTask(TaskRun $task, string $scope): void
    {
        [$file, $parse] = $this->lockedTaskTarget($task, $scope);
        $parse->save(['state' => TaskState::CANCELED, 'completed_at' => date('Y-m-d H:i:s')]);
        $file->save(['state' => 'parse_canceled']);
    }

    public function retryTask(TaskRun $task, string $scope): void
    {
        [$file, $parse] = $this->lockedTaskTarget($task, $scope);
        $parse->save([
            'state' => TaskState::QUEUED,
            'error_code' => null,
            'error_summary' => null,
            'completed_at' => null,
        ]);
        $file->save(['state' => 'parsing']);
    }

    public function recoverStaleTask(TaskRun $task, string $state): void
    {
        $scope = (new TaskInputAccessGuard())->persistedScope($task);
        [$file, $parse] = $this->lockedTaskTarget($task, $scope);
        $failed = $state === TaskState::FAILED;
        $parse->save([
            'state' => $state,
            'error_code' => 'SAND_AI_TASK_LEASE_EXPIRED',
            'error_summary' => 'The file parse worker lease expired',
            'completed_at' => $failed ? date('Y-m-d H:i:s') : null,
        ]);
        $file->save(['state' => $failed ? 'parse_failed' : 'parsing']);
    }

    /** @return array{AiFile, AiFileParse} */
    private function lockedTaskTarget(TaskRun $task, string $scope): array
    {
        [$fileId, $parseId] = $this->taskTarget($task);
        $guard = new KnowledgeSourceReferenceGuard();
        $guard->lockFile($fileId);
        $guard->rejectPublishedReference($fileId);
        $file = AiFile::where('environment_id', (int) $task->environment_id)->where('id', $fileId)->lock(true)->find();
        $parse = AiFileParse::where('id', $parseId)->where('file_id', $fileId)->lock(true)->find();
        if ($file === null || $parse === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File parse record not found');
        (new FileOwnershipGuard())->assert($file, $scope);
        $this->rejectExistingSourceBlocks($parseId);
        if (in_array((string) $parse->state, [TaskState::SUCCEEDED, TaskState::CANCELED], true)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Completed file parse cannot change state');
        }
        return [$file, $parse];
    }

    /** @return array{int, int} */
    private function taskTarget(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $fileId = is_array($input) ? (int) ($input['file_id'] ?? 0) : 0;
        $parseId = is_array($input) ? (int) ($input['parse_id'] ?? 0) : 0;
        if ((string) $task->task_type !== 'file_parse' || (string) $task->resource_type !== 'file'
            || $fileId < 1 || $parseId < 1
            || $fileId !== (int) $task->resource_id || $parseId !== (int) $task->file_parse_id) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The file parse task payload is invalid');
        }
        return [$fileId, $parseId];
    }

    private function rejectExistingSourceBlocks(int $parseId): void
    {
        if (SourceBlock::where('parse_id', $parseId)->count() > 0) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'File parse already has source blocks');
        }
    }

    /** @return array<string, mixed> */
    private function summary(AiFileParse $parse): array
    {
        return [
            'id' => (int) $parse->id,
            'parser_code' => (string) $parse->parser_code,
            'parser_version' => (string) $parse->parser_version,
            'state' => (string) $parse->state,
            'source_block_count' => (int) $parse->source_block_count,
            'error_code' => $parse->error_code,
            'error_summary' => $parse->error_summary,
            'started_at' => $parse->started_at,
            'completed_at' => $parse->completed_at,
        ];
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
