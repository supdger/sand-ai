<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\validate;

use plugin\sandadmin\exception\ApiException;

final class ModelValidate
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $payload = $this->common($input);
        $payload['code'] = $this->code($input['code'] ?? null);
        $payload['name'] = $this->name($input['name'] ?? null);
        if (!isset($payload['type'])) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: model type is required');
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
        foreach (['name', 'type', 'capabilities', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $payload[$field] = $input[$field];
            }
        }
        if (isset($payload['name'])) {
            $payload['name'] = $this->name($payload['name']);
        }
        if (isset($payload['type']) && !in_array($payload['type'], ['chat', 'embedding', 'rerank'], true)) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: invalid model type');
        }
        if (isset($payload['capabilities'])) {
            if (!is_array($payload['capabilities']) || array_filter($payload['capabilities'], 'is_string') !== $payload['capabilities']) {
                throw new ApiException('SAND_AI_VALIDATION_ERROR: capabilities must be a string array');
            }
            $payload['capabilities'] = array_values(array_unique($payload['capabilities']));
        }
        if (isset($payload['status']) && !in_array((int) $payload['status'], [1, 2], true)) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: invalid status');
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
