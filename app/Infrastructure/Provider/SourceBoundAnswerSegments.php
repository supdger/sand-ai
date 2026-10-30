<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;

/** Business JSONL paragraphs from official SDK text chunks; never parses Provider SSE. */
final class SourceBoundAnswerSegments
{
    private string $pending = '';
    private string $modelText = '';
    /** @var list<string> */
    private array $answer = [];
    /** @var array<int,int> */
    private array $citations = [];
    private bool $closed = false;

    /** @param \Closure(array<string,mixed>):array{text:string,source_block_ids:list<int>} $acceptSegment */
    public function __construct(private readonly \Closure $acceptSegment)
    {
    }

    public function append(string $text): void
    {
        if ($this->closed) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer segments are closed', true);
        }
        $this->modelText .= $text;
        $this->pending .= $text;
        if (strlen($this->pending) > 24000) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream answer is invalid', true);
        }
        while (($lineEnd = strpos($this->pending, "\n")) !== false) {
            $this->acceptLine(substr($this->pending, 0, $lineEnd));
            $this->pending = substr($this->pending, $lineEnd + 1);
        }
    }

    /** @return array{content:string} */
    public function finish(string $officialContent): array
    {
        if ($this->closed || !hash_equals($this->modelText, $officialContent)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official stream differs from its answer segments', true);
        }
        $this->closed = true;
        if (trim($this->pending) !== '') {
            $this->acceptLine($this->pending);
        }
        if ($this->answer === []) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider stream returned no answer segments', true);
        }

        return ['content' => json_encode(
            ['answer' => implode("\n", $this->answer), 'citations' => array_values($this->citations)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )];
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
