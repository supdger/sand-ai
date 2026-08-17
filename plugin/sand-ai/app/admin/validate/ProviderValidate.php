<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\validate;

use plugin\SandAi\app\admin\support\ProviderSecret;
use plugin\sandadmin\exception\ApiException;

final class ProviderValidate
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $payload = $this->common($input);
        $payload['code'] = $this->code($input['code'] ?? null);
        $payload['name'] = $this->name($input['name'] ?? null);
        if (trim((string) ($input['adapter'] ?? '')) === '') {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: adapter is required');
        }

        return $payload;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(array $input): array
    {
        $payload = $this->common($input);
        unset($payload['code']);
        return $payload;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function common(array $input): array
    {
        $payload = [];
        foreach (['name', 'adapter', 'timeout_ms', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $payload[$field] = $input[$field];
            }
        }
        if (isset($payload['name'])) {
            $payload['name'] = $this->name($payload['name']);
        }
        if (isset($payload['adapter'])) {
            $payload['adapter'] = trim((string) $payload['adapter']);
            if ($payload['adapter'] === '') {
                throw new ApiException('SAND_AI_VALIDATION_ERROR: adapter is required');
            }
        }
        if (isset($payload['timeout_ms'])) {
            $payload['timeout_ms'] = (int) $payload['timeout_ms'];
            if ($payload['timeout_ms'] <= 0) {
                throw new ApiException('SAND_AI_VALIDATION_ERROR: timeout_ms must be positive');
            }
        }
        if (isset($payload['status']) && !in_array((int) $payload['status'], [1, 2], true)) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: invalid status');
        }
        if (array_key_exists('encrypted_config', $input)) {
            if (!is_array($input['encrypted_config'])) {
                throw new ApiException('SAND_AI_VALIDATION_ERROR: encrypted_config must be an object');
            }
            $payload['encrypted_config'] = ProviderSecret::encrypt($input['encrypted_config']);
        }

        return $payload;
    }

    private function code(mixed $value): string
    {
        $code = trim((string) $value);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $code)) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: invalid code');
        }
        return $code;
    }

    private function name(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: name is required');
        }
        return $name;
    }
}
