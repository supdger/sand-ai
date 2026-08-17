<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\file;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\domain\capability\CapabilityProfileResolver;
use plugin\SandAi\app\domain\task\TaskGateway;
use plugin\SandAi\app\domain\task\TaskState;
use plugin\SandAi\app\infrastructure\parsing\ParseDriverFactory;
use plugin\SandAi\app\infrastructure\parsing\ParseException;
use plugin\SandAi\app\infrastructure\parsing\ParseInput;
use plugin\SandAi\app\infrastructure\parsing\ParseResult;
use plugin\SandAi\app\infrastructure\storage\StorageDriverFactory;
use plugin\SandAi\app\infrastructure\storage\StorageException;
use plugin\SandAi\app\model\AiFile;
use plugin\SandAi\app\model\AiFileParse;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\SourceBlock;
use plugin\SandAi\app\model\TaskRun;
use plugin\SandAi\app\model\TaskStep;
use support\Log;
use think\facade\Db;
use Throwable;

/** Asynchronous private-file parsing; all source blocks stay environment-bound. */
final class FileParseGateway
{
    /** @return array<string, mixed> */
    public function parse(IdentityContext $context, int $fileId, string $requestId): array
    {
        return Db::connect('pgsql')->transaction(function () use ($context, $fileId, $requestId): array {
            $environmentId = $context->environmentId;
            $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->lock(true)->find();
            if ($file === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
            }
            $replayed = TaskRun::where('environment_id', $environmentId)->where('request_id', $requestId)->find();
            if ($replayed !== null) {
                if ((string) $replayed->task_type !== 'file_parse' || (int) $replayed->resource_id !== $fileId) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                }

                return ['idempotent_replay' => true, 'file_id' => $fileId, 'parse' => $this->latest($fileId), 'task' => (new TaskGateway())->summary($replayed)];
            }
            if (in_array((string) $file->state, ['deleting', 'deleted'], true) || (int) $file->status !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The file cannot be parsed in its current state');
            }
            $active = TaskRun::where('environment_id', $environmentId)->where('task_type', 'file_parse')
                ->where('resource_type', 'file')->where('resource_id', $fileId)
                ->whereIn('state', [...TaskState::claimable(), TaskState::RUNNING])->find();
            if ($active !== null) {
                return ['idempotent_replay' => false, 'file_id' => $fileId, 'parse' => $this->latest($fileId), 'task' => (new TaskGateway())->summary($active)];
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
                'max_attempts' => max(1, (int) config('plugin.sand-ai.task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => ['file_id' => $fileId, 'parse_id' => (int) $parse->id],
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
            $this->audit($context, 'file.parse_queued', $fileId, 'Queued SandAI file parse', ['parse_id' => (int) $parse->id, 'task_id' => (int) $task->id]);

            return ['idempotent_replay' => false, 'file_id' => $fileId, 'parse' => $this->summary($parse), 'task' => (new TaskGateway())->summary($task)];
        });
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $fileId = is_array($input) ? (int) ($input['file_id'] ?? 0) : 0;
        $parseId = is_array($input) ? (int) ($input['parse_id'] ?? 0) : 0;
        if ($fileId <= 0 || $parseId <= 0) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The file parse task payload is invalid');
        }
        $file = $this->fileForEnvironment((int) $task->environment_id, $fileId);
        $parse = AiFileParse::where('id', $parseId)->where('file_id', $fileId)->find();
        if ($parse === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File parse record not found');
        }

        $file->save(['state' => 'parsing']);
        $parse->save(['state' => 'running', 'error_code' => null, 'error_summary' => null, 'completed_at' => null]);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'sand-ai-plugin-parse-');
        if ($temporaryPath === false) {
            return $this->fail($file, $parse, 'SAND_AI_PARSE_FAILED', 'Parse temporary file could not be created', false);
        }
        $persistenceStage = 'download';

        try {
            StorageDriverFactory::create((string) $file->storage_driver)->downloadFile((string) $file->object_key, $temporaryPath);
            $profileResolver = new CapabilityProfileResolver();
            $route = $profileResolver->primary((int) $task->environment_id, 'document_parse');
            if ($route === null && $profileResolver->hasPublishedProfile((int) $task->environment_id)) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The published capability profile has no document parse route');
            }
            $driver = $route === null
                ? ParseDriverFactory::forExtension((string) $file->extension)
                : ParseDriverFactory::forCapabilityDriver((string) $route['driver_code'], (string) $file->extension);
            $result = $driver->parse(new ParseInput($temporaryPath, (string) $file->extension, (string) $file->media_type));
            $rows = $this->sourceRows($file, $parse, $result);
            if ($rows === []) {
                throw new ParseException('SAND_AI_PARSE_EMPTY', 'The file contains no extractable source blocks');
            }

            Db::connect('pgsql')->transaction(function () use ($file, $parse, $result, $rows, &$persistenceStage): void {
                $persistenceStage = 'source_blocks';
                foreach ($rows as $row) {
                    SourceBlock::create($row);
                }
                $persistenceStage = 'parse_record';
                $parse->save([
                    'parser_code' => $result->driverCode,
                    'parser_version' => $result->driverVersion,
                    'state' => 'succeeded',
                    'source_block_count' => count($rows),
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                $persistenceStage = 'file_record';
                $file->save(['state' => 'parsed']);
            });
            $this->auditWorker((int) $task->environment_id, 'file.parse', (int) $file->id, 'Parsed SandAI file', [
                'parse_id' => (int) $parse->id,
                'task_id' => (int) $task->id,
                'source_block_count' => count($rows),
            ]);

            return ['file_id' => (int) $file->id, 'parse' => $this->latest((int) $file->id)];
        } catch (StorageException) {
            return $this->fail($file, $parse, 'SAND_AI_STORAGE_UNAVAILABLE', 'Private storage download is unavailable', true);
        } catch (ParseException $exception) {
            return $this->fail($file, $parse, $exception->errorCode, $exception->getMessage(), !in_array($exception->errorCode, ['SAND_AI_OCR_UNAVAILABLE', 'SAND_AI_OCR_REQUIRED'], true));
        } catch (ApiProblem $exception) {
            return $this->fail($file, $parse, $exception->errorCode, $exception->getMessage(), $exception->retryable);
        } catch (Throwable $exception) {
            Log::error('SandAI plugin file parse persistence failed', [
                'file_id' => (int) $file->id,
                'parse_id' => (int) $parse->id,
                'exception_type' => $exception::class,
                'exception_code' => (string) $exception->getCode(),
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
                'persistence_stage' => $persistenceStage,
            ]);

            return $this->fail($file, $parse, 'SAND_AI_PARSE_FAILED', 'File parsing failed', true);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function latest(int $fileId): ?array
    {
        $parse = AiFileParse::where('file_id', $fileId)->order('id', 'desc')->find();
        if ($parse === null) {
            return null;
        }
        $summary = $this->summary($parse);
        $task = TaskRun::where('file_parse_id', (int) $parse->id)->find();
        if ($task !== null) {
            $summary['task'] = (new TaskGateway())->summary($task);
        }

        return $summary;
    }

    private function fileForEnvironment(int $environmentId, int $fileId): AiFile
    {
        $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($file === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }

        return $file;
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(AiFile $file, AiFileParse $parse, ParseResult $result): array
    {
        $maxCharacters = max(256, (int) config('plugin.sand-ai.parse.max_source_block_characters', 12000));
        $rows = [];
        foreach ($result->blocks as $block) {
            $content = trim($block->content);
            $part = 0;
            while ($content !== '') {
                $part++;
                $piece = mb_strcut($content, 0, $maxCharacters, 'UTF-8');
                $content = (string) mb_substr($content, mb_strlen($piece, 'UTF-8'), null, 'UTF-8');
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
                    'char_count' => mb_strlen($piece, 'UTF-8'),
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function fail(AiFile $file, AiFileParse $parse, string $errorCode, string $message, bool $retryable): array
    {
        $parse->save([
            'state' => 'failed',
            'error_code' => $errorCode,
            'error_summary' => mb_strcut($message, 0, 512, 'UTF-8'),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $file->save(['state' => 'parse_failed']);
        $this->auditWorker((int) $file->environment_id, 'file.parse_failed', (int) $file->id, 'SandAI file parsing failed', [
            'parse_id' => (int) $parse->id,
            'error_code' => $errorCode,
        ]);
        throw new ApiProblem($errorCode, $message, $retryable);
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
    private function audit(IdentityContext $identity, string $action, int $fileId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'workload_client',
            'actor_ref' => (string) $identity->workloadClientId,
            'action' => $action,
            'resource_type' => 'file',
            'resource_id' => $fileId,
            'summary' => $summary,
            'context' => $context + [
                'organization_id' => $identity->organizationId,
                'application_id' => $identity->applicationId,
                'environment_id' => $identity->environmentId,
            ],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function auditWorker(int $environmentId, string $action, int $fileId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'system_worker',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'file',
            'resource_id' => $fileId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
