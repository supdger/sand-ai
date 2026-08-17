<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\storage;

/**
 * Private object storage boundary. Implementations never expose their bucket,
 * endpoint, object prefix or long-lived URL through the runtime DTO.
 */
interface StorageDriver
{
    public function code(): string;

    public function upload(string $objectKey, string $contents, string $contentType = 'application/octet-stream'): void;

    public function uploadFile(string $objectKey, string $localPath, string $contentType = 'application/octet-stream'): void;

    public function downloadFile(string $objectKey, string $localPath): void;

    public function read(string $objectKey): string;

    public function exists(string $objectKey): bool;

    public function temporaryUrl(string $objectKey, int $ttlSeconds): ?string;

    public function delete(string $objectKey): void;
}
