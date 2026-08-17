<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\storage;

/**
 * A database record stores this relative key only. Provider bucket, endpoint
 * and configured prefix stay private to the plugin storage adapter.
 */
final class ObjectKey
{
    public static function normalize(string $objectKey): string
    {
        $objectKey = trim($objectKey);
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, '\\')) {
            throw new StorageException('SandAI storage object key is invalid');
        }

        if (str_contains($objectKey, "\0") || str_contains($objectKey, '//')) {
            throw new StorageException('SandAI storage object key is invalid');
        }

        foreach (explode('/', $objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StorageException('SandAI storage object key is invalid');
            }
        }

        return $objectKey;
    }

    public static function generate(string $extension = ''): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        if ($extension !== '' && !preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
            throw new StorageException('SandAI storage file extension is invalid');
        }

        $suffix = $extension === '' ? '' : '.' . $extension;

        return sprintf('files/%s/%s%s', date('Y/m/d'), bin2hex(random_bytes(20)), $suffix);
    }
}
