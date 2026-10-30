<?php

declare(strict_types=1);

namespace app\Infrastructure\Storage;

/**
 * Local development fallback. The root is deliberately outside the public
 * directory; access must be mediated by a future SandAI file API.
 */
final class LocalPrivateStorageDriver implements StorageDriver
{
    private string $root;

    public function __construct(string $root)
    {
        $root = rtrim(trim($root), '/\\');
        if ($root === '') {
            throw new StorageException('SandAI local storage root is required');
        }

        $this->root = $root;
    }

    public function code(): string
    {
        return 'local-private';
    }

    public function upload(string $objectKey, string $contents, string $contentType = 'application/octet-stream'): void
    {
        $path = $this->path($objectKey);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('SandAI local storage directory could not be created');
        }

        $temporaryPath = tempnam($directory, '.upload-');
        if ($temporaryPath === false) {
            throw new StorageException('SandAI local storage temporary file could not be created');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new StorageException('SandAI local storage upload failed');
            }
            chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $path)) {
                throw new StorageException('SandAI local storage upload could not be finalized');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function uploadFile(string $objectKey, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        if (!is_file($localPath)) {
            throw new StorageException('SandAI upload temporary file was not found');
        }

        $path = $this->path($objectKey);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('SandAI local storage directory could not be created');
        }

        $temporaryPath = tempnam($directory, '.upload-');
        if ($temporaryPath === false) {
            throw new StorageException('SandAI local storage temporary file could not be created');
        }

        try {
            if (!copy($localPath, $temporaryPath)) {
                throw new StorageException('SandAI local storage upload failed');
            }
            chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $path)) {
                throw new StorageException('SandAI local storage upload could not be finalized');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function read(string $objectKey): string
    {
        $path = $this->path($objectKey);
        if (!is_file($path)) {
            throw new StorageException('SandAI storage object was not found');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new StorageException('SandAI local storage read failed');
        }

        return $contents;
    }

    public function downloadFile(string $objectKey, string $localPath): void
    {
        $source = $this->path($objectKey);
        if (!is_file($source)) {
            throw new StorageException('SandAI storage object was not found');
        }
        if (!copy($source, $localPath)) {
            throw new StorageException('SandAI local storage download failed');
        }
        chmod($localPath, 0600);
    }

    public function exists(string $objectKey): bool
    {
        return is_file($this->path($objectKey));
    }

    public function temporaryUrl(string $objectKey, int $ttlSeconds): ?string
    {
        ObjectKey::normalize($objectKey);

        return null;
    }

    public function delete(string $objectKey): void
    {
        $path = $this->path($objectKey);
        if (is_file($path) && !@unlink($path)) {
            throw new StorageException('SandAI local storage delete failed');
        }
    }

    private function path(string $objectKey): string
    {
        $objectKey = ObjectKey::normalize($objectKey);

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $objectKey);
    }
}
