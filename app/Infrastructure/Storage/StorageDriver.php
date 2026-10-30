<?php

declare(strict_types=1);

namespace app\Infrastructure\Storage;

/**
 * Private object storage boundary for SandAI files.
 *
 * Object keys are always relative to the SandAI storage prefix. The caller
 * never receives a bucket name, endpoint, or permanent public URL.
 */
interface StorageDriver
{
    public function code(): string;

    public function upload(string $objectKey, string $contents, string $contentType = 'application/octet-stream'): void;

    /** Uploads a Webman temporary file without loading the whole file into PHP memory. */
    public function uploadFile(string $objectKey, string $localPath, string $contentType = 'application/octet-stream'): void;

    /** Downloads a private object into a local temporary file without returning its contents to callers. */
    public function downloadFile(string $objectKey, string $localPath): void;

    public function read(string $objectKey): string;

    public function exists(string $objectKey): bool;

    /**
     * Returns a short-lived URL when the driver supports direct private-object
     * access. A null value means the runtime must stream the object itself.
     */
    public function temporaryUrl(string $objectKey, int $ttlSeconds): ?string;

    public function delete(string $objectKey): void;
}
