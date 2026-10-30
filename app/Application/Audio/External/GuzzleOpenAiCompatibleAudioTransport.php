<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** Production transport for the single adapter endpoint; all network policy is explicit here. */
final class GuzzleOpenAiCompatibleAudioTransport implements OpenAiCompatibleAudioTransport
{
    public function send(OpenAiCompatibleAudioHttpRequest $request): OpenAiCompatibleAudioHttpResponse
    {
        $multipart = [];
        $opened = [];
        try {
            foreach ($request->multipart() as $name => $value) {
                if ($value instanceof \CURLFile) {
                    $stream = @fopen($value->getFilename(), 'rb');
                    if (!is_resource($stream)) throw new \RuntimeException('Controlled audio input cannot be opened');
                    $opened[] = $stream;
                    $multipart[] = ['name' => $name, 'contents' => $stream, 'filename' => $value->getPostFilename(), 'headers' => ['Content-Type' => $value->getMimeType()]];
                } elseif (is_string($value)) {
                    $multipart[] = ['name' => $name, 'contents' => $value];
                } else {
                    throw new \RuntimeException('Audio multipart field is invalid');
                }
            }
            $endpoint = $request->endpoint();
            $sink = new BoundedAudioResponseSink($request->responseMaxBytes());
            try {
                // The explicit cURL handler applies CURLOPT_RESOLVE; the sink aborts oversized replies while receiving.
                $response = (new Client(['handler' => new CurlHandler()]))->request('POST', $endpoint->url, [
                    'headers' => $request->headers(),
                    'multipart' => $multipart,
                    'http_errors' => false,
                    'allow_redirects' => false,
                    'proxy' => '',
                    'connect_timeout' => min(30, $request->timeoutMs() / 1000),
                    'timeout' => $request->timeoutMs() / 1000,
                    'sink' => $sink,
                    'curl' => [CURLOPT_RESOLVE => $endpoint->curlResolveEntry === null ? [] : [$endpoint->curlResolveEntry]],
                ]);
                $body = $response->getBody();
                $body->rewind();
                return new OpenAiCompatibleAudioHttpResponse($response->getStatusCode(), $body->getContents());
            } finally {
                $sink->close();
            }
        } finally {
            foreach ($opened as $openedStream) fclose($openedStream);
        }
    }
}

/** cURL stops the transfer when a write would exceed the configured response limit. */
final class BoundedAudioResponseSink implements StreamInterface
{
    private StreamInterface $stream;
    private int $receivedBytes = 0;

    public function __construct(private readonly int $maxBytes)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    // StreamDecoratorTrait is a vendor trait outside the compiled baseline;
    // delegate the StreamInterface contract explicitly instead.
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

    public function write($string): int
    {
        $length = strlen($string);
        if ($length > $this->maxBytes - $this->receivedBytes) {
            return 0;
        }
        $written = $this->stream->write($string);
        $this->receivedBytes += $written;
        return $written;
    }
}
