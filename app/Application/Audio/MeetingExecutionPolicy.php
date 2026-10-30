<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;

final class MeetingExecutionPolicy
{
    /** @param array<string,mixed> $input @return array{environment_id:int,model:?string} */
    public static function read(array $input): array
    {
        self::keys($input, ['environment_id', 'model']);
        return ['environment_id' => self::integer($input['environment_id'] ?? null, 1),
            'model' => isset($input['model']) ? self::model($input['model']) : null];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function mutation(array $input, bool $state): array
    {
        $keys = ['environment_id', 'model', 'expected_route_sha256', 'expected_deployment_revision',
            'expected_profile_revision', 'expected_revision', $state ? 'enabled' : 'capacity'];
        self::keys($input, $keys, true);
        foreach (['environment_id', 'expected_revision', 'expected_deployment_revision', 'expected_profile_revision'] as $key) {
            if (!is_int($input[$key])) self::invalid();
        }
        $input['environment_id'] = self::integer($input['environment_id'], 1);
        $input['model'] = self::model($input['model']);
        $input['expected_revision'] = self::integer($input['expected_revision'], 0);
        $input['expected_deployment_revision'] = self::integer($input['expected_deployment_revision'], 1);
        $input['expected_profile_revision'] = self::integer($input['expected_profile_revision'], 1);
        self::sha($input['expected_route_sha256']);
        if ($state && !is_bool($input['enabled'])) self::invalid();
        if (!$state && !is_array($input['capacity'])) self::invalid();
        return $input;
    }

    /** @param array<string,mixed> $capacity @param array<string,mixed> $route @return array<string,mixed> */
    public static function capacity(array $capacity, array $route, int $output, ?int $inputCeiling): array
    {
        if (array_key_exists('planning_driver', $capacity)) {
            return MeetingLocalPlanning::capacity($capacity, $route, $output, $inputCeiling);
        }
        self::keys($capacity, ['tokenizer_driver', 'context_window_tokens', 'input_token_limit',
            'max_tokenizer_requests', 'capacity_source_url', 'capacity_source_sha256'], true);
        if (!is_string($capacity['tokenizer_driver'])) self::invalid();
        $driver = MeetingTokenizerDrivers::require($route, $capacity['tokenizer_driver']);
        // JSON body integer types remain strict; query identifiers are handled separately.
        foreach (['context_window_tokens', 'input_token_limit', 'max_tokenizer_requests'] as $key) {
            if (!is_int($capacity[$key])) self::invalid();
        }
        if ($capacity['context_window_tokens'] < 2 || $capacity['context_window_tokens'] > $driver['context_window_ceiling']
            || $capacity['input_token_limit'] < 1 || $capacity['input_token_limit'] > $capacity['context_window_tokens'] - $output
            || ($inputCeiling !== null && $capacity['input_token_limit'] > $inputCeiling)
            || $capacity['max_tokenizer_requests'] < $driver['request_limit_min']
            || $capacity['max_tokenizer_requests'] > $driver['request_limit_max']) self::invalid();
        $url = $capacity['capacity_source_url'];
        if (!is_string($url) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) self::invalid();
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) self::invalid();
        self::sha($capacity['capacity_source_sha256']);
        return $capacity;
    }

    public static function integer(mixed $value, int $minimum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum) self::invalid();
        return $value;
    }

    private static function model(mixed $value): string
    {
        if (!is_string($value) || trim($value) !== $value || $value === '' || strlen($value) > 128) self::invalid();
        return $value;
    }

    private static function sha(mixed $value): void
    {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) self::invalid();
    }

    /** @param array<string,mixed> $input @param list<string> $keys */
    private static function keys(array $input, array $keys, bool $complete = false): void
    {
        if (array_diff(array_keys($input), $keys) !== [] || ($complete && array_diff($keys, array_keys($input)) !== [])) self::invalid();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', '会议执行设置的字段、类型或容量范围无效，请核对当前发布部署与官方能力依据');
    }
}
