<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use Psr\Http\Message\ResponseInterface;

/** Incremental, bounded Provider SSE parser; a frame is accepted as it arrives. */
final class OpenAiCompatibleSegmentStream
{
    private string $buffer = '';
    private string $modelText = '';
    /** @var list<string> */
    private array $answer = [];
    /** @var array<int,int> */
    private array $citations = [];
    private int $bytes = 0;
    private bool $pendingCr = false;
    private bool $closed = false;
    private bool $done = false;
    private bool $finished = false;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;

    /** @param callable(array<string,mixed>):array{text:string,source_block_ids:list<int>} $acceptSegment */
    public function __construct(private readonly int $maximumBytes, private readonly mixed $acceptSegment)
    {
        if ($maximumBytes < 1 || !is_callable($acceptSegment)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider stream receiver is invalid');
        }
    }

    /** Retained for response based callers; the pinned cURL path calls feed() directly. */
    public static function readResponse(ResponseInterface $response, int $maximumBytes, callable $acceptSegment): array
    {
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream content type is invalid', true);
        }
        $parser = new self($maximumBytes, $acceptSegment);
        $body = $response->getBody();
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $parser->feed($chunk);
        }
        return $parser->finish();
    }

    public function feed(string $chunk): void
    {
        if ($this->closed) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream is closed', true);
        }
        $this->bytes += strlen($chunk);
        if ($this->bytes > $this->maximumBytes) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream exceeds the configured byte limit', true);
        }
        if ($this->pendingCr) {
            $chunk = "\r" . $chunk;
            $this->pendingCr = false;
        }
        if (str_ends_with($chunk, "\r")) {
            $chunk = substr($chunk, 0, -1);
            $this->pendingCr = true;
        }
        $this->buffer = str_replace("\r\n", "\n", $this->buffer . $chunk);
        while (($boundary = strpos($this->buffer, "\n\n")) !== false) {
            if ($boundary > 65536) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream frame is too large', true);
            }
            $frame = substr($this->buffer, 0, $boundary);
            $this->buffer = substr($this->buffer, $boundary + 2);
            $this->feedFrame($frame);
        }
        if (strlen($this->buffer) > 65536) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream frame is too large', true);
        }
    }

    /** @return array{content:string,input_tokens:int,output_tokens:int,usage_measured:bool} */
    public function finish(): array
    {
        if ($this->closed) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream is closed', true);
        }
        $this->closed = true;
        if ($this->pendingCr || !$this->done || !$this->finished || $this->inputTokens === null || $this->outputTokens === null
            || trim($this->buffer) !== '') {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream ended without a complete measured result', true);
        }
        if (trim($this->modelText) !== '') {
            $this->acceptLine($this->modelText);
        }
        if ($this->answer === []) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream returned no answer segments', true);
        }
        return [
            'content' => json_encode(['answer' => implode("\n", $this->answer), 'citations' => array_values($this->citations)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'usage_measured' => true,
        ];
    }

    private function feedFrame(string $frame): void
    {
        $data = null;
        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (!str_starts_with($line, 'data:') || $data !== null) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream frame is invalid', true);
            }
            $data = ltrim(substr($line, 5), ' ');
        }
        if ($data === null) {
            return;
        }
        if ($this->done) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream has data after completion', true);
        }
        if ($data === '[DONE]') {
            $this->done = true;
            return;
        }
        try {
            $event = json_decode($data, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream JSON is invalid', true);
        }
        if (!is_array($event)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream event is invalid', true);
        }
        $usage = $event['usage'] ?? null;
        if ($usage !== null) {
            if (!is_array($usage) || !is_int($usage['prompt_tokens'] ?? null)
                || !is_int($usage['completion_tokens'] ?? null)
                || $usage['prompt_tokens'] < 0 || $usage['completion_tokens'] < 0
                || $this->inputTokens !== null) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream usage is invalid', true);
            }
            $this->inputTokens = $usage['prompt_tokens'];
            $this->outputTokens = $usage['completion_tokens'];
        }
        $choices = $event['choices'] ?? null;
        if (!is_array($choices) || count($choices) > 1) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream choices are invalid', true);
        }
        if ($choices === []) {
            return;
        }
        $choice = $choices[0];
        if (!is_array($choice) || !is_array($choice['delta'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream delta is invalid', true);
        }
        $finishReason = $choice['finish_reason'] ?? null;
        if ($finishReason !== null) {
            if ($finishReason !== 'stop' || $this->finished) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream did not finish normally', true);
            }
            $this->finished = true;
        }
        $delta = $choice['delta']['content'] ?? '';
        if (!is_string($delta) || ($this->finished && $delta !== '')) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream content is invalid', true);
        }
        $this->modelText .= $delta;
        if (strlen($this->modelText) > 24000) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream answer is invalid', true);
        }
        while (($lineEnd = strpos($this->modelText, "\n")) !== false) {
            $this->acceptLine(substr($this->modelText, 0, $lineEnd));
            $this->modelText = substr($this->modelText, $lineEnd + 1);
        }
    }

    private function acceptLine(string $line): void
    {
        if ($line === '' || !mb_check_encoding($line, 'UTF-8') || count($this->answer) >= 16) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer segment count is invalid', true);
        }
        try {
            $parsed = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer segment is invalid', true);
        }
        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer segment is invalid', true);
        }
        $segment = ($this->acceptSegment)($parsed);
        if (!is_string($segment['text'] ?? null) || !is_array($segment['source_block_ids'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Validated answer segment is invalid', true);
        }
        $this->answer[] = $segment['text'];
        if (mb_strlen(implode("\n", $this->answer), 'UTF-8') > 4000) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer is too long', true);
        }
        foreach ($segment['source_block_ids'] as $id) {
            $this->citations[$id] = $id;
        }
    }
}
