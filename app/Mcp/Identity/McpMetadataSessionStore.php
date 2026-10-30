<?php

declare(strict_types=1);

namespace app\Mcp\Identity;

use app\Api\Support\ApiProblem;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/** Persist handshake metadata only. SDK response queues and request state remain request local. */
final class McpMetadataSessionStore implements SessionStoreInterface
{
    private readonly FileSessionStore $delegate;

    public function __construct(private readonly string $directory, int $ttl)
    {
        try {
            $this->delegate = new FileSessionStore($directory, $ttl);
        } catch (\Throwable) {
            self::unavailable();
        }
    }

    public function exists(Uuid $id): bool
    {
        return $this->read($id) !== false;
    }

    public function read(Uuid $id): string|false
    {
        $path = $this->path($id);
        if (is_link($path)) self::unavailable();
        if (!is_file($path)) return false;
        $size = @filesize($path);
        if (!is_int($size) || $size > 1024) return false;
        if (!@chmod($path, 0600)) self::unavailable();
        $data = $this->delegate->read($id);
        return $data === false ? false : $this->metadata($data);
    }

    public function write(Uuid $id, string $data): bool
    {
        $metadata = $this->metadata($data);
        if ($metadata === false) return false;
        $path = $this->path($id);
        $temporary = $path . '.tmp';
        if (is_link($path) || is_link($temporary)) self::unavailable();
        // Pre-create the SDK temporary file privately, before it writes any bytes.
        $handle = @fopen($temporary, 'c+b');
        if ($handle === false) self::unavailable();
        $private = @chmod($temporary, 0600);
        fclose($handle);
        if (!$private) self::unavailable();
        if (!$this->delegate->write($id, $metadata)) return false;
        if (!@chmod($path, 0600)) self::unavailable();
        return true;
    }

    public function destroy(Uuid $id): bool
    {
        if (is_link($this->path($id))) self::unavailable();
        return $this->delegate->destroy($id);
    }

    /** @return list<Uuid> */
    public function gc(): array
    {
        $deleted = [];
        $handle = @opendir($this->directory);
        if ($handle === false) self::unavailable();
        try {
            while (($entry = readdir($handle)) !== false) {
                if (!Uuid::isValid($entry)) continue;
                $id = Uuid::fromString($entry);
                if ($id->toRfc4122() !== $entry) continue;
                $lock = $this->acquireLock($id);
                if ($lock === false) continue;
                try {
                    if (!$this->delegate->exists($id) && $this->destroy($id)) $deleted[] = $id;
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        } finally {
            closedir($handle);
        }
        return $deleted;
    }

    /** @return resource|false */
    public function acquireLock(Uuid $id): mixed
    {
        $path = $this->path($id) . '.lock';
        if (is_link($path)) self::unavailable();
        $handle = @fopen($path, 'c+b');
        if ($handle === false) self::unavailable();
        if (!@chmod($path, 0600)) {
            fclose($handle);
            self::unavailable();
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        // Do not unlink lock files: another worker may already hold the same inode.
        return $handle;
    }

    private function metadata(string $data): string|false
    {
        try {
            $decoded = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) return false;
        $initialized = array_key_exists('initialized', $decoded) ? $decoded['initialized'] : false;
        if (!is_bool($initialized)) return false;
        $version = $decoded['protocol_version'] ?? null;
        if ($version !== null && (!is_string($version)
            || ($revision = ProtocolVersion::tryFrom($version)) === null || $revision->isModern())) return false;
        if ($initialized && $version === null) return false;
        $metadata = ['initialized' => $initialized, 'client_capabilities' => []];
        if ($version !== null) $metadata['protocol_version'] = $version;
        return json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    private function path(Uuid $id): string
    {
        return $this->directory . '/' . $id->toRfc4122();
    }

    public function __debugInfo(): array
    {
        return ['metadata_only' => true];
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_MCP_SESSION_UNAVAILABLE', 'MCP session storage is unavailable', true);
    }
}
