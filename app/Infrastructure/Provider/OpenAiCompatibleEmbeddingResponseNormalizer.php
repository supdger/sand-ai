<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;

/** Shared strict shape and value validation for embedding responses. */
final class OpenAiCompatibleEmbeddingResponseNormalizer
{
    /** @return array{vectors:list<list<float>>,input_tokens:int,usage_measured:bool} */
    public function normalize(mixed $payload, int $expected): array
    {
        if (!is_array($payload) || !is_array($payload['data'] ?? null)
            || !array_is_list($payload['data']) || count($payload['data']) !== $expected) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider returned incomplete vectors', true);
        }
        $vectors = [];
        $dimensions = null;
        foreach ($payload['data'] as $row) {
            $index = is_array($row) ? ($row['index'] ?? null) : null;
            $vector = is_array($row) ? ($row['embedding'] ?? null) : null;
            if (!is_int($index) || $index < 0 || $index >= $expected || isset($vectors[$index])
                || !is_array($vector) || !array_is_list($vector)
                || count($vector) < 1 || count($vector) > 4096
                || ($dimensions !== null && count($vector) !== $dimensions)) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider returned invalid dimensions', true);
            }
            $dimensions = count($vector);
            $sum = 0.0;
            $numbers = [];
            foreach ($vector as $value) {
                if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value)
                    || abs((float) $value) > 1000000) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider returned invalid values', true);
                }
                $number = (float) $value;
                $sum += $number * $number;
                $numbers[] = $number;
            }
            if ($sum <= 0.0) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Embedding provider returned a zero vector', true);
            }
            $norm = sqrt($sum);
            $vectors[$index] = array_map(static fn (float $value): float => $value / $norm, $numbers);
        }
        ksort($vectors, SORT_NUMERIC);
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        $tokens = $usage['prompt_tokens'] ?? null;
        $measured = is_int($tokens) && $tokens >= 0;
        if (is_string($tokens) && ctype_digit($tokens)) {
            $value = ltrim($tokens, '0');
            $value = $value === '' ? '0' : $value;
            $limit = (string) PHP_INT_MAX;
            if (strlen($value) < strlen($limit)
                || (strlen($value) === strlen($limit) && strcmp($value, $limit) <= 0)) {
                $tokens = (int) $value;
                $measured = true;
            }
        }
        return ['vectors' => array_values($vectors), 'input_tokens' => $measured ? $tokens : 0, 'usage_measured' => $measured];
    }
}
