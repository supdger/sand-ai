<?php

declare(strict_types=1);

namespace app\Mcp\Identity;

use app\Api\Support\ApiProblem;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/** Request-local SDK state and lock, with only handshake metadata persisted. */
final class McpSessionScope implements SessionStoreInterface
{
    private ?McpMetadataSessionStore $store = null;
    /** @var array<string,string> SDK state for this HTTP request only. */
    private array $requestData = [];
    /** @var resource|null */
    private mixed $lockHandle = null;

    public function __construct(private readonly McpInvocationContext $identity)
    {
    }

    public function store(): SessionStoreInterface
    {
        return $this;
    }

    private function metadataStore(): McpMetadataSessionStore
    {
        if ($this->store !== null) return $this->store;
        $ttl = config('sand_ai_mcp_server.session_ttl', 1800);
        if (!is_int($ttl) || $ttl < 60 || $ttl > 86400) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'MCP session lifetime is invalid');
        }
        $scope = $this->identity->sessionScope();
        $root = runtime_path('sand-ai');
        $directory = $root . '/mcp/' . $scope;
        foreach ([$root, $root . '/mcp', $directory] as $path) {
            if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))
                || !is_dir($path) || !@chmod($path, 0700)) {
                self::unavailable();
            }
        }
        return $this->store = new McpMetadataSessionStore($directory, $ttl);
    }

    public function exists(Uuid $id): bool
    {
        return $this->metadataStore()->exists($id);
    }

    public function read(Uuid $id): string|false
    {
        $metadata = $this->metadataStore()->read($id);
        $key = $id->toRfc4122();
        if ($metadata === false) {
            unset($this->requestData[$key]);
            return false;
        }
        return $this->requestData[$key] ?? $metadata;
    }

    public function write(Uuid $id, string $data): bool
    {
        $key = $id->toRfc4122();
        if (!$this->metadataStore()->write($id, $data)) {
            unset($this->requestData[$key]);
            return false;
        }
        // The SDK reloads the session when draining outgoing responses.
        $this->requestData[$key] = $data;
        return true;
    }

    public function destroy(Uuid $id): bool
    {
        unset($this->requestData[$id->toRfc4122()]);
        return $this->metadataStore()->destroy($id);
    }

    /** @return list<Uuid> */
    public function gc(): array
    {
        $deleted = $this->metadataStore()->gc();
        foreach ($deleted as $id) unset($this->requestData[$id->toRfc4122()]);
        return $deleted;
    }

    /** Held until the HTTP handler's finally block, including SDK response construction. */
    public function lock(?string $sessionId): void
    {
        if ($sessionId === null) return; // SDK initialize creates a fresh UUID.
        if ($this->lockHandle !== null || !Uuid::isValid($sessionId)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'MCP session identifier is invalid');
        }
        $id = Uuid::fromString($sessionId);
        if ($id->toRfc4122() !== strtolower($sessionId)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'MCP session identifier is invalid');
        }
        $this->lockHandle = $this->metadataStore()->acquireLock($id);
        if ($this->lockHandle === false) {
            $this->lockHandle = null;
            throw new ApiProblem('SAND_AI_MCP_SESSION_BUSY', 'MCP session is processing another request', true);
        }
    }

    public function release(): void
    {
        $this->requestData = [];
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    public function __debugInfo(): array
    {
        return ['locked' => is_resource($this->lockHandle)];
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_MCP_SESSION_UNAVAILABLE', 'MCP session storage is unavailable', true);
    }
}
