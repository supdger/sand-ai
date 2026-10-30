<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** A provider model identifier is a JSON value, never an endpoint path. */
final class RemoteModelIdentifier
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) === 1) {
            throw self::invalid();
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 256
            || str_starts_with($value, '/') || str_contains($value, '//') || str_contains($value, '\\')) {
            throw self::invalid();
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw self::invalid();
            }
        }
        return $value;
    }

    public static function displayName(string $value): string
    {
        $value = self::normalize($value);
        if (strlen($value) <= 160) {
            return $value;
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw self::invalid();
        }
        $name = '';
        foreach ($characters as $character) {
            if (strlen($name . $character) > 157) {
                break;
            }
            $name .= $character;
        }
        return $name . '...';
    }

    private static function invalid(): ApiProblem
    {
        return new ApiProblem('SAND_AI_ONBOARDING_MODEL_INVALID', '请填写服务商提供的模型标识，最多 256 字节，不能包含控制字符或路径跳转段');
    }
}
