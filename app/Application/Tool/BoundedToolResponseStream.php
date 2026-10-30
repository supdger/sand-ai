<?php

declare(strict_types=1);

namespace app\Application\Tool;

use GuzzleHttp\Psr7\Stream;

/** Stops Guzzle's receive sink before an oversized response can be buffered. */
final class BoundedToolResponseStream extends Stream
{
    private int $storedBytes = 0;
    private bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        $resource = fopen('php://temp', 'w+');
        if ($resource === false) {
            throw new \RuntimeException('External tool response stream is unavailable');
        }
        parent::__construct($resource);
    }

    public function write($string): int
    {
        $size = strlen($string);
        if ($size > $this->maxBytes - $this->storedBytes) {
            $this->exceeded = true;
            return 0;
        }
        $written = parent::write($string);
        $this->storedBytes += $written;
        return $written;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }
}
