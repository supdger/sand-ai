<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;

/**
 * Lightweight per-admin rate limit for trial context issuance. Webman workers
 * are long-lived, so an in-process sliding window is sufficient for the "same
 * admin per minute" ceiling; it deliberately makes no cross-worker claim.
 */
final class AdminTrialRateLimiter
{
    private const WINDOW_SECONDS = 60;
    private const MAX_ISSUES = 12;

    /** @var array<int,list<int>> */
    private static array $hits = [];

    public static function assertAllowed(int $adminId, ?int $now = null): void
    {
        $now ??= time();
        $windowStart = $now - self::WINDOW_SECONDS;
        $recent = array_values(array_filter(
            self::$hits[$adminId] ?? [],
            static fn (int $hit): bool => $hit > $windowStart,
        ));
        if (count($recent) >= self::MAX_ISSUES) {
            throw new ApiProblem('SAND_AI_TRIAL_RATE_LIMITED', '试用身份签发过于频繁，请稍后再试');
        }
        $recent[] = $now;
        self::$hits[$adminId] = $recent;
    }

    public static function reset(): void
    {
        self::$hits = [];
    }

    public static function maxIssues(): int
    {
        return self::MAX_ISSUES;
    }

    public static function windowSeconds(): int
    {
        return self::WINDOW_SECONDS;
    }
}
