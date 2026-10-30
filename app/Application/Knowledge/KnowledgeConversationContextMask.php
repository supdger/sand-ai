<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;

/** Masks recognizable secrets and personal numbers in reused conversation context. */
final class KnowledgeConversationContextMask
{
    private const PATTERNS = [
            '~(["\']?\b(?:authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|password)\b["\']?\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|(?:Bearer|Basic)\s+[^\s,;，；]+|[^\s,;，；]+)~iu',
            '~(["\']?(?:密码|密钥|令牌)["\']?\s*[:=：]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|[^\s,;，；]+)~u',
            '#\bBearer\s+[A-Za-z0-9._~+/-]{8,}#i',
            '~\bsk-[A-Za-z0-9_-]{8,}\b~i',
            '~(?<![0-9])[0-9]{17}[0-9Xx](?![0-9A-Za-z])~',
            '~(?<![0-9])1[3-9][0-9]{9}(?![0-9])~',
    ];

    public static function text(string $value): string
    {
        foreach (self::PATTERNS as $index => $pattern) {
            $masked = preg_replace($pattern, $index < 2 ? '$1[已遮盖]' : '[已遮盖]', $value);
            if ($masked === null) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation context is invalid');
            }
            $value = $masked;
        }
        return $value;
    }

    /** Operator-private observation only; no matching text or identifier leaves this method. */
    public static function matchesAcrossNativeBoundary(string $value, int $boundary): bool
    {
        if ($boundary <= 0 || $boundary >= strlen($value)) return false;
        foreach (self::PATTERNS as $pattern) {
            $count = preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE);
            if ($count === false) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation context is invalid');
            }
            foreach ($matches[0] as [$match, $offset]) {
                if ($offset < $boundary && $offset + strlen($match) > $boundary) return true;
            }
        }
        return false;
    }

}
