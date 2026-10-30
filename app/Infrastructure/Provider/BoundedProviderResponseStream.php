<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use GuzzleHttp\Psr7\Stream;

/** cURL sink that aborts a response before an oversized decoded chunk is stored. */
final class BoundedProviderResponseStream extends Stream
{
    private int $written = 0;
    private bool $limitExceeded = false;

    public function __construct(private readonly int $maximum)
    {
        if ($maximum < 1 || $maximum > OpenAiCompatibleExecutionConfigPolicy::MAX_RESPONSE_MAX_BYTES) {
            throw new \InvalidArgumentException('Provider response limit is invalid');
        }
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Provider response sink is unavailable');
        }
        parent::__construct($stream);
    }

    public function write($string): int
    {
        if (!is_string($string)) {
            throw new \InvalidArgumentException('Provider response chunk must be a string');
        }
        if (strlen($string) > $this->maximum - $this->written) {
            $this->limitExceeded = true;
            return 0; // cURL treats a short write as a transfer failure.
        }
        $count = parent::write($string);
        $this->written += $count;
        return $count;
    }

    public function limitExceeded(): bool
    {
        return $this->limitExceeded;
    }
}
