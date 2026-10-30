<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;

final class BoundedResponseBuffer
{
    private string $buffer = '';
    private bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        if ($maxBytes <= 0) {
            throw new \InvalidArgumentException('response buffer limit must be positive');
        }
    }

    /** Returns zero to make Curl abort the transfer immediately. */
    public function append(string $chunk): int
    {
        if ($this->exceeded) {
            return 0;
        }
        $chunkBytes = strlen($chunk);
        if ($chunkBytes > $this->maxBytes - strlen($this->buffer)) {
            $this->exceeded = true;

            return 0;
        }
        $this->buffer .= $chunk;

        return $chunkBytes;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function storedBytes(): int
    {
        return strlen($this->buffer);
    }

    public function body(): string
    {
        if ($this->exceeded) {
            throw new ApiProblem('SAND_AI_BILLING_RESPONSE_TOO_LARGE', 'OpenAI billing response too large');
        }

        return $this->buffer;
    }
}
