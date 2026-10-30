<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;

/** Public selection is a configuration intention, never an IAM decision. */
final class AgentReadonlyToolSelection
{
    public static function normalize(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 2) self::invalid();
        $result = [];
        $seen = [];
        foreach ($input as $item) {
            if (!is_array($item) || array_is_list($item)
                || array_diff(array_keys($item), ['code', 'version', 'business_scope']) !== []
                || count($item) !== 3
                || !is_string($item['code'] ?? null)
                || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/D', $item['code']) !== 1
                || !is_int($item['version'] ?? null) || $item['version'] < 1
                || !is_string($item['business_scope'] ?? null)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $item['business_scope']) !== 1) self::invalid();
            $key = $item['code'] . ':' . $item['version'];
            if (isset($seen[$key])) self::invalid();
            $seen[$key] = true;
            $result[] = ['code' => $item['code'], 'version' => $item['version'], 'business_scope' => $item['business_scope']];
        }
        return $result;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Readonly tools require up to two exact published versions and business scopes');
    }
}
