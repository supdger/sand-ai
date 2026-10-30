<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Publicly testable, prompt-free admission policy for API 1.47 evidence. */
final class PreDispatchRejectionPolicy
{
    /** @var list<string> */
    private const STAGES = ['model', 'profile', 'route', 'deployment', 'provider', 'config', 'price', 'budget', 'scope', 'lease'];

    public static function stage(ApiProblem $problem): string
    {
        if (str_starts_with($problem->errorCode, 'SAND_AI_BUDGET_')) {
            return 'budget';
        }
        return match ($problem->errorCode) {
            'SAND_AI_MODEL_NOT_AVAILABLE' => 'model',
            'SAND_AI_CONFIGURATION_NOT_PUBLISHED' => 'route',
            'SAND_AI_PROVIDER_UNAVAILABLE' => 'provider',
            'SAND_AI_PROVIDER_CONFIG_INVALID' => 'config',
            'SAND_AI_PRICING_SNAPSHOT_INVALID' => 'price',
            'SAND_AI_TASK_LEASE_LOST' => 'lease',
            default => 'scope',
        };
    }

    public static function assertStage(string $stage): void
    {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \InvalidArgumentException('Pre-dispatch rejection stage is invalid');
        }
    }

    public static function sameReplay(array $record, string $fingerprint): bool
    {
        return is_string($record['request_fingerprint'] ?? null) && hash_equals($record['request_fingerprint'], $fingerprint);
    }

    /** @param array<string,mixed>|null $existing */
    public static function admission(?array $existing, string $fingerprint): string
    {
        if ($existing === null) return 'record';
        if (!self::sameReplay($existing, $fingerprint)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id was already used with different input');
        }
        return 'replay';
    }
}
