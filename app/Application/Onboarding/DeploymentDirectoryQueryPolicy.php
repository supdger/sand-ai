<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Capability\CapabilityDriverRegistry;

/** Normalizes the deliberately small, read-only deployment-directory query. */
final class DeploymentDirectoryQueryPolicy
{
    private const ALLOWED_FIELDS = ['page', 'limit', 'name', 'model_type', 'capability_code', 'environment_id', 'selection_scope'];

    /** @param array<string, mixed> $query @return array{page:int,limit:int,name:?string,model_type:?string,capability_code:?string,environment_id:?int,selection_scope:string} */
    public function normalize(array $query): array
    {
        if (array_diff(array_keys($query), self::ALLOWED_FIELDS) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Deployment directory query contains unknown fields');
        }

        $modelType = $this->optionalCode($query['model_type'] ?? null, 'model_type');
        $capabilityCode = $this->optionalCode($query['capability_code'] ?? null, 'capability_code');
        $registeredTypes = $this->registeredModelTypes();
        if ($modelType !== null && !in_array($modelType, $registeredTypes, true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model_type is not registered for a model deployment capability');
        }
        if ($capabilityCode !== null) {
            try {
                $capability = CapabilityDriverRegistry::capability($capabilityCode);
            } catch (ApiProblem) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'capability_code is not registered');
            }
            if (!in_array($capability['route_kind'] ?? null, ['model_deployment', 'audio_driver'], true) && $capabilityCode !== 'image_ocr') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'capability_code does not use a model deployment');
            }
            $types = is_array($capability['model_types'] ?? null) ? $capability['model_types'] : [];
            if ($modelType !== null && !in_array($modelType, $types, true)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'capability_code does not support model_type');
            }
        }

        $environmentId = $this->optionalPositiveId($query['environment_id'] ?? null, 'environment_id');
        if ($capabilityCode !== null && $environmentId === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'capability_code requires environment_id');
        }
        $selectionScope = array_key_exists('selection_scope', $query) ? $query['selection_scope'] : 'published_routes';
        if (!is_string($selectionScope) || !in_array($selectionScope, ['published_routes', 'ocr_route_configuration'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'selection_scope is invalid');
        }
        if ($selectionScope === 'ocr_route_configuration'
            && ($modelType !== 'image_ocr' || $capabilityCode !== 'image_ocr' || $environmentId === null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'OCR route configuration requires its model type, capability and environment');
        }

        return [
            'page' => $this->positiveInteger($query['page'] ?? null, 1, 1, 1000000, 'page'),
            'limit' => $this->positiveInteger($query['limit'] ?? null, 10, 1, 100, 'limit'),
            'name' => $this->optionalName($query['name'] ?? null),
            'model_type' => $modelType,
            'capability_code' => $capabilityCode,
            'environment_id' => $environmentId,
            'selection_scope' => $selectionScope,
        ];
    }

    /** @return list<string> */
    public function registeredModelTypes(): array
    {
        $types = [];
        foreach (CapabilityDriverRegistry::metadata() as $capability) {
            if (!in_array($capability['route_kind'] ?? null, ['model_deployment', 'audio_driver'], true) && !in_array('image_ocr', $capability['model_types'] ?? [], true)) {
                continue;
            }
            foreach (is_array($capability['model_types'] ?? null) ? $capability['model_types'] : [] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[$type] = true;
                }
            }
        }
        $result = array_keys($types);
        sort($result, SORT_STRING);

        return $result;
    }

    private function optionalName(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'name must be a string');
        }
        $name = trim($value);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'name must contain 1 to 160 characters');
        }

        return $name;
    }

    private function optionalCode(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{0,95}$/', $value) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' is invalid');
        }

        return $value;
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $this->assertWithinPhpIntRange($value, $field);
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' must be a positive integer');
        }

        return $value;
    }

    private function positiveInteger(mixed $value, int $default, int $minimum, int $maximum, string $field): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $this->assertWithinPhpIntRange($value, $field);
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' is invalid');
        }

        return $value;
    }

    private function assertWithinPhpIntRange(string $value, string $field): void
    {
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $field . ' exceeds the supported integer range');
        }
    }
}
