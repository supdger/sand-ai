<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** Guzzle cURL sink that aborts before storing bytes beyond the configured cap. */
final class BoundedEmbeddingResponseStream implements StreamInterface
{
    private StreamInterface $stream;
    private int $storedBytes = 0;
    private bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        $resource = fopen('php://temp', 'w+');
        if ($resource === false) {
            throw new \RuntimeException('Embedding response stream is unavailable');
        }
        $this->stream = Utils::streamFor($resource);
    }

    public function write($string): int
    {
        $size = strlen($string);
        if ($size > $this->maxBytes - $this->storedBytes) {
            $this->exceeded = true;
            return 0;
        }
        $written = $this->stream->write($string);
        $this->storedBytes += $written;
        return $written;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    // Guzzle Stream is a vendor class outside the compiled baseline; delegate
    // the StreamInterface contract to a Utils-created inner stream instead.
    public function __toString(): string { return $this->stream->__toString(); }
    public function close(): void { $this->stream->close(); }
    public function detach() { return $this->stream->detach(); }
    public function getSize(): ?int { return $this->stream->getSize(); }
    public function tell(): int { return $this->stream->tell(); }
    public function eof(): bool { return $this->stream->eof(); }
    public function isSeekable(): bool { return $this->stream->isSeekable(); }
    public function seek($offset, $whence = SEEK_SET): void { $this->stream->seek($offset, $whence); }
    public function rewind(): void { $this->stream->rewind(); }
    public function isWritable(): bool { return $this->stream->isWritable(); }
    public function isReadable(): bool { return $this->stream->isReadable(); }
    public function read($length): string { return $this->stream->read($length); }
    public function getContents(): string { return $this->stream->getContents(); }
    public function getMetadata($key = null) { return $this->stream->getMetadata($key); }
}
