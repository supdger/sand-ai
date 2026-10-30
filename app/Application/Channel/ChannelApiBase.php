<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

/** Resolves an optional outbound API base, enabling Lark/private deployments; absent falls back to the given official default. */
final class ChannelApiBase
{
    public static function resolve(mixed $apiBase, string $default): string
    {
        if ($apiBase === null || $apiBase === '') return $default;
        if (!is_string($apiBase) || strlen($apiBase) > 512) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Channel API base is invalid');
        }
        $parts = parse_url($apiBase);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Channel API base is invalid');
        }
        return rtrim($apiBase, '/');
    }
}
