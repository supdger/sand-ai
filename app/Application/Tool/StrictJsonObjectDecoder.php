<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** JSON decoder that rejects duplicate object members at every nesting level. */
final class StrictJsonObjectDecoder
{
    private const MAX_DEPTH = 32;
    private const MAX_NODES = 100000;
    private const MAX_BYTES = 1048576;

    private int $offset = 0;
    private int $length;
    private int $nodes = 0;

    private function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    /** @return array<string,mixed> */
    public static function decodeObject(string $json): array
    {
        $decoded = self::decodeObjectValue($json);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiProblem('SAND_AI_MCP_RESPONSE_INVALID', 'MCP server returned an invalid JSON response');
        }
        return $decoded;
    }

    /** Empty JSON objects remain stdClass; JSON arrays always remain arrays. */
    public static function decodeObjectValue(string $json): array|\stdClass
    {
        $scanner = new self($json);
        if ($scanner->length > self::MAX_BYTES) $scanner->limitExceeded();
        $scanner->skipWhitespace();
        if ($scanner->peek() !== '{') $scanner->invalid();
        $scanner->scanObject(1);
        $scanner->skipWhitespace();
        if ($scanner->offset !== $scanner->length) $scanner->invalid();
        try {
            $decoded = json_decode($json, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $scanner->invalid();
        }
        if (!$decoded instanceof \stdClass) $scanner->invalid();
        return self::preserveObjectShape($decoded);
    }


    private static function preserveObjectShape(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            if ($members === []) return new \stdClass();
            foreach ($members as $name => $item) {
                // PHP coerces numeric JSON member names into integer array keys.
                // Such names are outside the managed closed-object Schema subset.
                if (!is_string($name) || $name === '') {
                    throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool JSON object member is outside the supported subset');
                }
                $members[$name] = self::preserveObjectShape($item);
            }
            return $members;
        }
        if (is_array($value)) return array_map(self::preserveObjectShape(...), $value);
        return $value;
    }

    private function scanValue(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) $this->limitExceeded();
        $this->skipWhitespace();
        $next = $this->peek();
        if ($next === '{') { $this->scanObject($depth); return; }
        if ($next === '[') { $this->scanArray($depth); return; }
        if ($next === '"') { $this->node(); $this->scanString(); return; }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($this->json, $this->offset, strlen($literal)) === $literal) {
                $this->node();
                $this->offset += (int) strlen($literal);
                return;
            }
        }
        $remaining = substr($this->json, $this->offset);
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $remaining, $match) === 1) {
            $this->node();
            $this->offset += (int) strlen($match[0]);
            return;
        }
        $this->invalid();
    }

    private function scanObject(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) $this->limitExceeded();
        $this->node();
        $this->expect('{');
        $this->skipWhitespace();
        if ($this->peek() === '}') { $this->offset++; return; }
        $members = [];
        while (true) {
            $this->skipWhitespace();
            $rawKey = $this->scanString();
            try {
                $key = json_decode($rawKey, true, 2, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $this->invalid();
            }
            if (!is_string($key) || array_key_exists($key, $members)) {
                throw new ApiProblem('SAND_AI_MCP_DUPLICATE_JSON_MEMBER', 'MCP server returned duplicate JSON object members');
            }
            $members[$key] = true;
            $this->skipWhitespace();
            $this->expect(':');
            $this->scanValue($depth + 1);
            $this->skipWhitespace();
            if ($this->peek() === '}') { $this->offset++; return; }
            $this->expect(',');
        }
    }

    private function scanArray(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) $this->limitExceeded();
        $this->node();
        $this->expect('[');
        $this->skipWhitespace();
        if ($this->peek() === ']') { $this->offset++; return; }
        while (true) {
            $this->scanValue($depth + 1);
            $this->skipWhitespace();
            if ($this->peek() === ']') { $this->offset++; return; }
            $this->expect(',');
        }
    }

    private function scanString(): string
    {
        $start = $this->offset;
        $this->expect('"');
        while ($this->offset < $this->length) {
            $character = $this->json[$this->offset++];
            if ($character === '"') return substr($this->json, $start, $this->offset - $start);
            if (ord($character) < 0x20) $this->invalid();
            if ($character !== '\\') continue;
            if ($this->offset >= $this->length) $this->invalid();
            $escape = $this->json[$this->offset++];
            if (!str_contains('"\\/bfnrtu', $escape)) $this->invalid();
            if ($escape === 'u') {
                $hex = substr($this->json, $this->offset, 4);
                if (strlen($hex) !== 4 || !ctype_xdigit($hex)) $this->invalid();
                $this->offset += 4;
            }
        }
        $this->invalid();
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length && str_contains(" \t\r\n", $this->json[$this->offset])) $this->offset++;
    }

    private function expect(string $character): void
    {
        if ($this->peek() !== $character) $this->invalid();
        $this->offset++;
    }

    private function peek(): ?string
    {
        return $this->offset < $this->length ? $this->json[$this->offset] : null;
    }

    private function node(): void
    {
        $this->nodes++;
        if ($this->nodes > self::MAX_NODES) $this->limitExceeded();
    }

    private function limitExceeded(): never
    {
        throw new ApiProblem('SAND_AI_MCP_RESPONSE_LIMIT_EXCEEDED', 'MCP server response exceeded managed JSON safety limits');
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_MCP_RESPONSE_INVALID', 'MCP server returned an invalid JSON response');
    }
}
