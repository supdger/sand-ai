<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Non-raw provider facts retained in an encrypted receipt for Skill recovery. */
final readonly class SkillProviderOutcome
{
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public bool $usageMeasured,
        public int $latencyMs,
    ) {
        if ($content === '' || $inputTokens < 0 || $outputTokens < 0 || $latencyMs < 0) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill provider outcome is invalid');
        }
    }

    /** @param array<string,mixed> $providerResult */
    public static function fromProviderResult(array $providerResult): self
    {
        if (!is_string($providerResult['content'] ?? null) || !is_int($providerResult['input_tokens'] ?? null)
            || !is_int($providerResult['output_tokens'] ?? null) || !is_bool($providerResult['usage_measured'] ?? true) || !is_int($providerResult['latency_ms'] ?? null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill provider outcome is invalid');
        }
        return new self($providerResult['content'], $providerResult['input_tokens'], $providerResult['output_tokens'], $providerResult['usage_measured'] ?? true, $providerResult['latency_ms']);
    }

    public function encode(): string
    {
        return json_encode(['content' => $this->content, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'usage_measured' => $this->usageMeasured, 'latency_ms' => $this->latencyMs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $encoded): self
    {
        try {
            $value = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill provider outcome is invalid');
        }
        if (!is_array($value) || array_keys($value) !== ['content', 'input_tokens', 'output_tokens', 'usage_measured', 'latency_ms']) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill provider outcome is invalid');
        }
        return self::fromProviderResult($value);
    }
}
