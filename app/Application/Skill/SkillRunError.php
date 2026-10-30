<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** Immutable public error representation returned identically on replay. */
final class SkillRunError
{
    /** @return array{code:string,http_status:int,retryable:bool,public_detail:string} */
    public static function fromCode(string $code): array
    {
        $retryable = in_array($code, ['SAND_AI_USAGE_PERSISTENCE_FAILED', 'SAND_AI_PROVIDER_FAILURE', 'SAND_AI_DISPATCH_AMBIGUOUS'], true);
        $status = $code === 'SAND_AI_SCHEMA_UNSUPPORTED' || $code === 'SAND_AI_SKILL_OUTPUT_INVALID' ? 422 : ($retryable ? 503 : 409);
        return ['code' => $code, 'http_status' => $status, 'retryable' => $retryable, 'public_detail' => $code === 'SAND_AI_SCHEMA_UNSUPPORTED' ? 'Skill output did not match its published schema' : 'Skill run failed'];
    }

    public static function problem(string $code): ApiProblem
    {
        $error = self::fromCode($code);
        return new ApiProblem($error['code'], $error['public_detail'], $error['retryable']);
    }

    /** @param array<string,mixed> $error */
    public static function persisted(array $error): ApiProblem
    {
        $keys = array_keys($error);
        sort($keys, SORT_STRING);
        if ($keys !== ['code', 'http_status', 'public_detail', 'retryable']
            || !is_string($error['code']) || !is_int($error['http_status']) || $error['http_status'] < 400 || $error['http_status'] > 599
            || !is_bool($error['retryable']) || !is_string($error['public_detail']) || $error['public_detail'] === '') {
            throw new \InvalidArgumentException('Persisted Skill error contract is invalid');
        }
        return new ApiProblem($error['code'], $error['public_detail'], $error['retryable']);
    }
}
