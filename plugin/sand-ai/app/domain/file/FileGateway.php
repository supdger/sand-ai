<?php

declare(strict_types=1);

namespace plugin\SandAi\app\domain\file;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\IdentityContext;
use plugin\SandAi\app\infrastructure\storage\ObjectKey;
use plugin\SandAi\app\infrastructure\storage\StorageDriverFactory;
use plugin\SandAi\app\infrastructure\storage\StorageException;
use plugin\SandAi\app\model\AiFile;
use plugin\SandAi\app\model\AuditLog;
use Throwable;
use Webman\Http\UploadFile;

/**
 * SandAI-owned file persistence for an already authorized workload context.
 * It stores only a relative object key and never exposes storage coordinates.
 */
final class FileGateway
{
    /** @return array<string, mixed> */
    public function upload(IdentityContext $context, UploadFile $upload): array
    {
        $metadata = (new FileUploadPolicy())->inspect($upload);
        $driver = StorageDriverFactory::create();
        $objectKey = ObjectKey::generate($metadata['extension']);

        try {
            $driver->uploadFile($objectKey, $upload->getPathname(), $metadata['media_type']);
        } catch (StorageException $exception) {
            throw new ApiProblem('SAND_AI_STORAGE_UNAVAILABLE', 'Private storage upload is unavailable', true);
        }

        try {
            $file = AiFile::create([
                'environment_id' => $context->environmentId,
                'original_name' => $metadata['original_name'],
                'extension' => $metadata['extension'],
                'media_type' => $metadata['media_type'],
                'size_bytes' => $metadata['size_bytes'],
                'sha256' => $metadata['sha256'],
                'storage_driver' => $driver->code(),
                'object_key' => $objectKey,
                'state' => 'uploaded',
                'status' => 1,
            ]);
        } catch (Throwable $exception) {
            try {
                $driver->delete($objectKey);
            } catch (StorageException) {
            }
            throw $exception;
        }

        $this->audit($context, 'file.upload', (int) $file->id, 'Uploaded SandAI file', [
            'sha256' => $metadata['sha256'],
            'size_bytes' => $metadata['size_bytes'],
        ]);

        return $this->summary($file);
    }

    /** @return array<string, mixed> */
    public function read(IdentityContext $context, int $fileId): array
    {
        return $this->summary($this->fileForEnvironment($context->environmentId, $fileId), true);
    }

    /** @return array<string, mixed> */
    public function destroy(IdentityContext $context, int $fileId): array
    {
        $file = $this->fileForEnvironment($context->environmentId, $fileId);
        $file->save(['state' => 'deleting']);
        try {
            StorageDriverFactory::create((string) $file->storage_driver)->delete((string) $file->object_key);
        } catch (StorageException $exception) {
            $file->save(['state' => 'delete_failed']);
            $this->audit($context, 'file.delete_failed', (int) $file->id, 'SandAI file delete failed', []);
            throw new ApiProblem('SAND_AI_STORAGE_UNAVAILABLE', 'Private storage delete is unavailable', true);
        }

        $file->save(['state' => 'deleted', 'status' => 2]);
        $file->delete();
        $this->audit($context, 'file.delete', (int) $file->id, 'Deleted SandAI file', []);

        return ['id' => (int) $file->id, 'state' => 'deleted'];
    }

    private function fileForEnvironment(int $environmentId, int $fileId): AiFile
    {
        $file = AiFile::where('environment_id', $environmentId)->where('id', $fileId)->find();
        if ($file === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'File not found');
        }

        return $file;
    }

    /** @return array<string, mixed> */
    private function summary(AiFile $file, bool $includeLatestParse = false): array
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
        ];
        if ($includeLatestParse) {
            $summary['latest_parse'] = (new FileParseGateway())->latest((int) $file->id);
        }

        return $summary;
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
}
