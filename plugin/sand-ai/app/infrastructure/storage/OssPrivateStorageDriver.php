<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\storage;

use OSS\Core\OssException;
use OSS\OssClient;

/** Private Aliyun OSS adapter; provider coordinates stay out of API DTOs. */
final class OssPrivateStorageDriver implements StorageDriver
{
    /** @var array<string, mixed> */
    private array $config;

    private ?OssClient $client = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function code(): string
    {
        return 'oss-private';
    }

    public function upload(string $objectKey, string $contents, string $contentType = 'application/octet-stream'): void
    {
        try {
            $this->client()->putObject($this->bucket(), $this->qualifiedKey($objectKey), $contents, [OssClient::OSS_CONTENT_TYPE => $contentType]);
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS upload failed', previous: $exception);
        }
    }

    public function uploadFile(string $objectKey, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        if (!is_file($localPath)) {
            throw new StorageException('SandAI upload temporary file was not found');
        }
        try {
            $this->client()->uploadFile($this->bucket(), $this->qualifiedKey($objectKey), $localPath, [OssClient::OSS_CONTENT_TYPE => $contentType]);
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS upload failed', previous: $exception);
        }
    }

    public function read(string $objectKey): string
    {
        try {
            return (string) $this->client()->getObject($this->bucket(), $this->qualifiedKey($objectKey));
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS read failed', previous: $exception);
        }
    }

    public function downloadFile(string $objectKey, string $localPath): void
    {
        try {
            $this->client()->getObject($this->bucket(), $this->qualifiedKey($objectKey), [OssClient::OSS_FILE_DOWNLOAD => $localPath]);
            chmod($localPath, 0600);
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS download failed', previous: $exception);
        }
    }

    public function exists(string $objectKey): bool
    {
        try {
            return (bool) $this->client()->doesObjectExist($this->bucket(), $this->qualifiedKey($objectKey));
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS query failed', previous: $exception);
        }
    }

    public function temporaryUrl(string $objectKey, int $ttlSeconds): ?string
    {
        $configuredTtl = (int) ($this->config['sign_ttl'] ?? 900);
        $maxTtl = max(60, (int) ($this->config['max_sign_ttl'] ?? 900));
        $ttlSeconds = min(max(60, $ttlSeconds ?: $configuredTtl), $maxTtl);
        try {
            return (string) $this->client()->signUrl($this->bucket(), $this->qualifiedKey($objectKey), $ttlSeconds, OssClient::OSS_HTTP_GET);
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS temporary access signing failed', previous: $exception);
        }
    }

    public function delete(string $objectKey): void
    {
        try {
            $this->client()->deleteObject($this->bucket(), $this->qualifiedKey($objectKey));
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS delete failed', previous: $exception);
        }
    }

    private function client(): OssClient
    {
        if ($this->client instanceof OssClient) {
            return $this->client;
        }
        if (!class_exists(OssClient::class)) {
            throw new StorageException('Aliyun OSS SDK is not installed');
        }
        try {
            return $this->client = new OssClient(
                $this->requiredConfig('access_key_id'),
                $this->requiredConfig('access_key_secret'),
                trim($this->requiredConfig('endpoint'), " \t\n\r\0\x0B/"),
            );
        } catch (OssException $exception) {
            throw new StorageException('SandAI OSS initialization failed', previous: $exception);
        }
    }

    private function bucket(): string
    {
        return $this->requiredConfig('bucket');
    }

    private function qualifiedKey(string $objectKey): string
    {
        $objectKey = ObjectKey::normalize($objectKey);
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');

        return $prefix === '' ? $objectKey : $prefix . '/' . $objectKey;
    }

    private function requiredConfig(string $key): string
    {
        $value = trim((string) ($this->config[$key] ?? ''));
        if ($value === '') {
            throw new StorageException(sprintf('SandAI OSS configuration %s is required', $key));
        }

        return $value;
    }
}
