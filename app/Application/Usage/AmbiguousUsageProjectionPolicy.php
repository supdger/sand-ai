<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Keeps ambiguous dispatches and immutable UsageEvent aggregates disjoint. */
final class AmbiguousUsageProjectionPolicy
{
    public static function noUsageEventPredicate(string $dispatchAlias = 'dispatch'): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $dispatchAlias) !== 1) {
            throw new \InvalidArgumentException('Dispatch alias is invalid');
        }

        return "NOT EXISTS (SELECT 1 FROM sand_ai_usage_event usage_event WHERE usage_event.invocation_id = {$dispatchAlias}.invocation_id)";
    }

    /** @param array<string,mixed> $dispatch @param list<array<string,mixed>> $usageEvents */
    public static function countable(array $dispatch, array $usageEvents): bool
    {
        if (($dispatch['state'] ?? null) !== 'ambiguous' || !is_int($dispatch['invocation_id'] ?? null) || $dispatch['invocation_id'] < 1) {
            return false;
        }
        foreach ($usageEvents as $event) {
            if (($event['invocation_id'] ?? null) === $dispatch['invocation_id']) {
                return false;
            }
        }

        return true;
    }
}
