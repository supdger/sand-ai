<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Infrastructure\Provider\ResolvedProviderEndpoint;

/** Transport-only request material. It cannot be serialized or rendered into logs. */
final class OpenAiCompatibleAudioHttpRequest
{
    /** @param array<string,string> $headers @param array<string,mixed> $multipart */
    public function __construct(private readonly ResolvedProviderEndpoint $endpoint, private readonly array $headers, private readonly array $multipart, private readonly int $timeoutMs, private readonly int $responseMaxBytes) {}
    public function endpoint(): ResolvedProviderEndpoint { return $this->endpoint; }
    /** @return array<string,string> */ public function headers(): array { return $this->headers; }
    /** @return array<string,mixed> */ public function multipart(): array { return $this->multipart; }
    public function timeoutMs(): int { return $this->timeoutMs; }
    public function responseMaxBytes(): int { return $this->responseMaxBytes; }
    public function __serialize(): array { throw new \LogicException('Audio Provider request cannot be serialized'); }
    public function __debugInfo(): array { return []; }
}
