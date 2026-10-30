<?php

declare(strict_types=1);

namespace app\Application\Tool;

final readonly class ToolHttpResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(public int $statusCode, public string $body, public int $latencyMs, public array $headers = [])
    {
    }

    public function header(string $name): ?string
    {
        $values = $this->headerValues($name);
        return count($values) === 1 ? $values[0] : null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $matches = [];
        foreach ($this->headers as $candidate => $values) if (strcasecmp($candidate, $name) === 0) {
            foreach ($values as $value) if (is_string($value)) $matches[] = trim($value);
        }
        return $matches;
    }
}
