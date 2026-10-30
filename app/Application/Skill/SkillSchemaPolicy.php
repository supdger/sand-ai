<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;

/**
 * Deliberately small JSON Schema contract for the first Skill slice.
 *
 * It is not JSON Schema Draft 2020-12. Unknown keywords are rejected so a
 * caller cannot mistake an ignored constraint for an enforced one.
 */
final class SkillSchemaPolicy
{
    private const KEYWORDS = ['type', 'properties', 'required', 'items', 'enum', 'minLength', 'maxLength', 'minimum', 'maximum', 'additionalProperties'];

    /** @param mixed $schema @return array<string,mixed> */
    public static function normalize(mixed $schema): array
    {
        if (!is_array($schema) || array_is_list($schema)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema must be a JSON object');
        }
        if (strlen(json_encode($schema, JSON_THROW_ON_ERROR)) > 32768 || self::nodeCount($schema) > 256) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema exceeds the supported size');
        }
        self::assertSchema($schema, 0);
        if ($schema['type'] !== 'object') {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill input and output must be structured objects');
        }

        return $schema;
    }

    /** @param array<string,mixed> $schema */
    public static function assertValue(array $schema, mixed $value): void
    {
        self::assertValueAt($schema, $value, '$');
    }

    /** @param array<string,mixed> $schema */
    private static function assertSchema(array $schema, int $depth): void
    {
        if ($depth > 8 || !isset($schema['type']) || !is_string($schema['type']) || !in_array($schema['type'], ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema is outside the supported subset');
        }
        foreach ($schema as $keyword => $definition) {
            if (!in_array($keyword, self::KEYWORDS, true)) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema contains an unsupported keyword');
            }
            if ($keyword === 'properties') {
                if ($schema['type'] !== 'object' || !is_array($definition) || array_is_list($definition) || count($definition) > 64) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema properties are invalid');
                foreach ($definition as $property => $propertySchema) {
                    if (!is_string($property) || $property === '' || mb_strlen($property, 'UTF-8') > 128) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill schema property name is invalid');
                    if (!is_array($propertySchema) || array_is_list($propertySchema)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill property schema is invalid');
                    self::assertSchema($propertySchema, $depth + 1);
                }
            }
            if ($keyword === 'items') {
                if ($schema['type'] !== 'array' || !is_array($definition) || array_is_list($definition)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill array item schema is invalid');
                self::assertSchema($definition, $depth + 1);
            }
            if ($keyword === 'required' && ($schema['type'] !== 'object' || !is_array($definition) || !array_is_list($definition) || array_filter($definition, static fn (mixed $key): bool => is_string($key) && $key !== '') !== $definition || count(array_unique($definition)) !== count($definition))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill required fields are invalid');
            if ($keyword === 'additionalProperties' && ($schema['type'] !== 'object' || !is_bool($definition))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill additionalProperties is invalid');
            if (in_array($keyword, ['minLength', 'maxLength'], true) && ($schema['type'] !== 'string' || !is_int($definition) || $definition < 0 || $definition > 100000)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill string bounds are invalid');
            if (in_array($keyword, ['minimum', 'maximum'], true) && (!in_array($schema['type'], ['integer', 'number'], true) || (!is_int($definition) && !is_float($definition)))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill number bounds are invalid');
            if ($keyword === 'enum' && (!is_array($definition) || !array_is_list($definition) || $definition === [] || count($definition) > 100)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill enum is invalid');
        }
        if (isset($schema['minLength'], $schema['maxLength']) && $schema['minLength'] > $schema['maxLength']) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill string bounds are invalid');
        if (isset($schema['minimum'], $schema['maximum']) && $schema['minimum'] > $schema['maximum']) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill number bounds are invalid');
        if (isset($schema['required'], $schema['properties'])) foreach ($schema['required'] as $required) if (!array_key_exists($required, $schema['properties'])) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill required property is not declared');
    }

    /** @param array<string,mixed> $schema */
    private static function assertValueAt(array $schema, mixed $value, string $path): void
    {
        $type = $schema['type'];
        $valid = match ($type) {
            'object' => is_array($value) && !array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value), 'integer' => is_int($value),
            'number' => is_int($value) || is_float($value), 'boolean' => is_bool($value),
        };
        if (!$valid) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill value does not match its schema');
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill value is outside its schema enum');
        if ($type === 'string') {
            $length = mb_strlen($value, 'UTF-8');
            if ((isset($schema['minLength']) && (!is_int($schema['minLength']) || $length < $schema['minLength'])) || (isset($schema['maxLength']) && (!is_int($schema['maxLength']) || $length > $schema['maxLength']))) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill string length is outside its schema');
        }
        if ($type === 'integer' || $type === 'number') {
            $violations = [];
            if (isset($schema['minimum'])) {
                if (!is_int($schema['minimum']) && !is_float($schema['minimum'])) {
                    throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill number is outside its schema');
                }
                (new GreaterThanEqual($schema['minimum']))->validate($path, $value, $violations);
            }
            if (isset($schema['maximum'])) {
                if (!is_int($schema['maximum']) && !is_float($schema['maximum'])) {
                    throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill number is outside its schema');
                }
                (new LowerThanEqual($schema['maximum']))->validate($path, $value, $violations);
            }
            if ($violations !== []) {
                // Keep the existing public contract; SDK messages stay internal.
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill number is outside its schema');
            }
        }
        if ($type === 'array' && isset($schema['items'])) foreach ($value as $item) self::assertValueAt($schema['items'], $item, $path . '[]');
        if ($type === 'object') {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $required) if (!array_key_exists($required, $value)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill object is missing a required field');
            if (($schema['additionalProperties'] ?? true) === false) foreach (array_keys($value) as $key) if (!array_key_exists($key, $properties)) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Skill object has an unknown field');
            foreach ($properties as $key => $propertySchema) if (array_key_exists($key, $value)) self::assertValueAt($propertySchema, $value[$key], $path . '.' . $key);
        }
    }

    private static function nodeCount(mixed $value): int
    {
        if (!is_array($value)) {
            return 1;
        }
        $total = 1;
        foreach ($value as $item) {
            $total += self::nodeCount($item);
        }
        return $total;
    }
}
