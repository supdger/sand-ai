<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Infrastructure\Provider\ResolvedProviderEndpoint;

/** Secret-bearing runtime material; never place this object in a snapshot, DTO, or log context. */
final class OpenAiCompatibleAudioRuntime
{
    /** @param array<string,string> $extraHeaders */
    public function __construct(private readonly ResolvedProviderEndpoint $endpoint, private readonly string $apiKey, private readonly array $extraHeaders, private readonly string $remoteModel, private readonly int $timeoutMs, private readonly int $responseMaxBytes, private readonly string $providerIdempotencyMode) {}
    public function endpoint(): ResolvedProviderEndpoint { return $this->endpoint; }
    public function apiKey(): string { return $this->apiKey; }
    /** @return array<string,string> */ public function extraHeaders(): array { return $this->extraHeaders; }
    public function remoteModel(): string { return $this->remoteModel; }
    public function timeoutMs(): int { return $this->timeoutMs; }
    public function responseMaxBytes(): int { return $this->responseMaxBytes; }
    public function providerIdempotencyMode(): string { return $this->providerIdempotencyMode; }
    public function __serialize(): array { throw new \LogicException('Audio Provider runtime cannot be serialized'); }
    public function __debugInfo(): array { return []; }
}
