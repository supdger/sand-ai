<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeConversationContextMask;

/** One provider round: only stable, masked prefixes become persistent public events. */
final class AgentStreamTextMask
{
    private string $pending = '';
    private bool $finished = false;
    private int $nativeBytes = 0;
    private int $nativeChunkNo = 0;

    public function __construct(private readonly ?\Closure $nativeChunkObserver = null) {}

    public function push(string $text, ?int $nativeChunkNo = null): string
    {
        if ($this->finished || !mb_check_encoding($text, 'UTF-8')) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent stream text is invalid');
        }
        $boundary = strlen($this->pending);
        $start = $this->nativeBytes;
        $this->nativeBytes += strlen($text);
        $this->nativeChunkNo = $nativeChunkNo ?? $this->nativeChunkNo + 1;
        $this->pending .= $text;
        $observationPending = $this->nativeChunkObserver === null ? null : $this->pending;
        $cut = $this->stableCut();
        $safe = $cut === 0 ? '' : KnowledgeConversationContextMask::text(substr($this->pending, 0, $cut));
        $this->pending = substr($this->pending, $cut);
        if (strlen($this->pending) > 8192) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent stream has an oversized unfinished text field');
        }
        if ($this->nativeChunkObserver !== null) {
            // Observation failures never change the stream, its ordering, receipt or accounting.
            try {
                $crossed = KnowledgeConversationContextMask::matchesAcrossNativeBoundary($observationPending, $boundary);
                ($this->nativeChunkObserver)([
                    'native_chunk_no' => $this->nativeChunkNo,
                    'text_byte_start' => $start,
                    'text_byte_end' => $this->nativeBytes,
                    'mask_match_crossed_native_boundary' => $crossed,
                ]);
            } catch (\Throwable) {}
        }
        return $safe;
    }

    /** Only a reliable, accounted provider completion may release the final pending word. */
    public function finish(): string
    {
        if ($this->finished) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent stream text already completed');
        }
        $this->finished = true;
        $safe = KnowledgeConversationContextMask::text($this->pending);
        $this->pending = '';
        return $safe;
    }

    private function stableCut(): int
    {
        $text = $this->pending;
        // Keep the final word even after whitespace: it can be a split "Bearer" or field name.
        preg_match('/[^\s,;，；。！？]+[\s,;，；。！？]*$/u', $text, $tail, PREG_OFFSET_CAPTURE);
        $cut = isset($tail[0]) ? $tail[0][1] : strlen($text);
        $keyPattern = '~(["\']?\b(?:authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password)\b["\']?\s*[:=]\s*|["\']?(?:密码|密钥|令牌)["\']?\s*[:=：]\s*)~iu';
        preg_match_all($keyPattern, $text, $fields, PREG_OFFSET_CAPTURE);
        foreach ($fields[0] as [$field, $offset]) {
            $start = $offset + strlen($field);
            $rest = substr($text, $start);
            if ($rest === '' || trim($rest) === '') {
                $cut = min($cut, $offset);
                continue;
            }
            $quote = $rest[0];
            if ($quote === '"' || $quote === "'") {
                $closed = false;
                for ($i = 1, $length = strlen($rest); $i < $length; $i++) {
                    if ($rest[$i] === '\\') { $i++; continue; }
                    if ($rest[$i] === $quote) { $closed = true; break; }
                }
                if (!$closed || $start + $i + 1 > $cut) $cut = min($cut, $offset);
            } else {
                $matched = preg_match('/^(?:(?:Bearer|Basic)\s+)?[^\s,;，；]+[\s,;，；]/iu', $rest, $value);
                if ($matched !== 1 || $start + strlen($value[0]) > $cut) {
                    $cut = min($cut, $offset);
                }
            }
        }
        // Bearer and its value must remain together until the token is complete.
        preg_match_all('/\bBearer\s+[A-Za-z0-9._~+\/-]*/i', $text, $bearers, PREG_OFFSET_CAPTURE);
        foreach ($bearers[0] as [$value, $offset]) {
            if ($offset + strlen($value) > $cut) $cut = min($cut, $offset);
        }
        return $cut;
    }
}
