<?php

declare(strict_types=1);

namespace app\Application\Gateway;

/** Selects the immutable route contract from the persisted dispatch identity. */
final class DispatchClaimRoutePolicy
{
    /** @param array<string,mixed> $route */
    public static function usesCapabilityProfileRoute(array $route, string $executionScope, string $sourceKind): bool
    {
        if ($executionScope === 'skill' && in_array($sourceKind, ['skill', 'skill_test'], true)) {
            return self::hasFrozenSkillRoute($route);
        }

        return ($route['selection_kind'] ?? null) === 'capability_profile'
            || ($executionScope === 'chat' && $sourceKind === 'chat'
                && ($route['selection_kind'] ?? null) === 'lowest_cost');
    }

    /** @param array<string,mixed> $route */
    public static function isFrozenSkillRoute(array $route, string $executionScope, string $sourceKind): bool
    {
        return $executionScope === 'skill'
            && in_array($sourceKind, ['skill', 'skill_test'], true)
            && self::hasFrozenSkillRoute($route);
    }

    /** @param array<string,mixed> $route */
    private static function hasFrozenSkillRoute(array $route): bool
    {
        // API 1.46 freezes the Skill snapshot without selection_kind. The
        // omission is deliberate; any supplied value is a conflicting route
        // contract and must not widen this exception.
        if (array_key_exists('selection_kind', $route)) {
            return false;
        }
        try {
            DispatchRouteSnapshot::assert($route, true);
        } catch (\Throwable) {
            return false;
        }
        foreach (['profile_id', 'profile_revision', 'route_id', 'deployment_id', 'deployment_revision', 'model_id', 'provider_id', 'config_revision_id', 'config_revision', 'profile_config_revision_id'] as $key) {
            if (!is_int($route[$key] ?? null) || $route[$key] < 1) {
                return false;
            }
        }

        return ($route['capability_code'] ?? null) === 'chat'
            && ($route['route_kind'] ?? null) === 'model_deployment';
    }
}
