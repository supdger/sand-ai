<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;

/** Strict bounded subset; deliberately not a general JSON Schema engine. */
final class ToolSchemaPolicy
{
    private const KEYWORDS = ['type', 'properties', 'required', 'items', 'maxItems', 'enum', 'minLength', 'maxLength', 'minimum', 'maximum', 'additionalProperties', 'format'];
    private const MAX_SCHEMA_BYTES = 32768;
    private const MAX_SCHEMA_NODES = 256;
    private const MAX_VALUE_BYTES = 65536;
    private const MAX_VALUE_NODES = 1024;
    private const MAX_DEPTH = 8;

    /** @return array<string,mixed> */
    public static function normalize(mixed $schema): array
    {
        if (!is_array($schema) || array_is_list($schema)) {
            self::unsupported();
        }
        $schema = self::schemaObjects($schema);
        try {
            $encoded = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::unsupported();
        }
        if (strlen($encoded) > self::MAX_SCHEMA_BYTES || self::nodes($schema) > self::MAX_SCHEMA_NODES) {
            self::unsupported();
        }
        self::assertSchema($schema, 0);
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool schema root must be a closed object');
        }
        return $schema;
    }

    /** @param array<string,mixed> $schema */
    public static function assertValue(array $schema, mixed $value): void
    {
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::unsupported();
        }
        if (strlen($encoded) > self::MAX_VALUE_BYTES || self::nodes($value) > self::MAX_VALUE_NODES || self::depth($value) > self::MAX_DEPTH) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool value exceeds the published safety bounds');
        }
        self::assertValueAt($schema, $value);
    }

    /** @param array<string,mixed> $schema */
    private static function assertSchema(array $schema, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || !is_string($schema['type'] ?? null)
            || !in_array($schema['type'], ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)) {
            self::unsupported();
        }
        foreach ($schema as $keyword => $definition) {
            if (!is_string($keyword) || !in_array($keyword, self::KEYWORDS, true)) {
                self::unsupported();
            }
            if ($keyword === 'properties') {
                if ($schema['type'] !== 'object' || !is_array($definition) || ($definition !== [] && array_is_list($definition)) || count($definition) > 64) self::unsupported();
                foreach ($definition as $name => $child) {
                    if (!is_string($name) || $name === '' || mb_strlen($name, 'UTF-8') > 128 || !is_array($child) || array_is_list($child)) self::unsupported();
                    self::assertSchema($child, $depth + 1);
                }
            }
            if ($keyword === 'required' && ($schema['type'] !== 'object' || !is_array($definition) || !array_is_list($definition)
                || count($definition) > 64
                || array_filter($definition, static fn (mixed $item): bool => is_string($item) && $item !== '') !== $definition
                || count(array_unique($definition, SORT_STRING)) !== count($definition))) self::unsupported();
            if ($keyword === 'additionalProperties' && ($schema['type'] !== 'object' || !is_bool($definition))) self::unsupported();
            if ($keyword === 'items') {
                if ($schema['type'] !== 'array' || !is_array($definition) || array_is_list($definition)) self::unsupported();
                self::assertSchema($definition, $depth + 1);
            }
            if ($keyword === 'maxItems' && ($schema['type'] !== 'array' || !is_int($definition) || $definition < 0 || $definition > 1000)) self::unsupported();
            if (in_array($keyword, ['minLength', 'maxLength'], true) && ($schema['type'] !== 'string' || !is_int($definition) || $definition < 0 || $definition > self::MAX_VALUE_BYTES)) self::unsupported();
            if (in_array($keyword, ['minimum', 'maximum'], true) && (!in_array($schema['type'], ['integer', 'number'], true) || (!is_int($definition) && !is_float($definition)))) self::unsupported();
            if ($keyword === 'format' && ($schema['type'] !== 'string' || !in_array($definition, ['date', 'date-time'], true))) self::unsupported();
            if ($keyword === 'enum' && (!is_array($definition) || !array_is_list($definition) || $definition === [] || count($definition) > 100)) self::unsupported();
        }
        if ($schema['type'] === 'array' && (!isset($schema['items']) || !isset($schema['maxItems']))) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool arrays require items and maxItems');
        }
        if ($schema['type'] === 'object' && ($schema['additionalProperties'] ?? null) !== false) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool objects must reject unknown fields');
        }
        if (isset($schema['required'])) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ($schema['required'] as $required) if (!array_key_exists($required, $properties)) self::unsupported();
        }
        if (isset($schema['minLength'], $schema['maxLength']) && $schema['minLength'] > $schema['maxLength']) self::unsupported();
        if (isset($schema['minimum'], $schema['maximum']) && $schema['minimum'] > $schema['maximum']) self::unsupported();
    }

    /** @param array<string,mixed> $schema */
    private static function assertValueAt(array $schema, mixed $value): void
    {
        $type = $schema['type'];
        $valid = match ($type) {
            'object' => (is_array($value) && !array_is_list($value))
                || ($value instanceof \stdClass && get_object_vars($value) === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid || (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) self::unsupported();
        if ($type === 'string') {
            if (isset($schema['format'])) self::assertDateFormat($schema['format'], $value);
            $length = mb_strlen($value, 'UTF-8');
            if ((isset($schema['minLength']) && $length < $schema['minLength']) || (isset($schema['maxLength']) && $length > $schema['maxLength'])) self::unsupported();
        }
        if (($type === 'integer' || $type === 'number')
            && ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum']))) self::unsupported();
        if ($type === 'array') {
            if (count($value) > $schema['maxItems']) self::unsupported();
            foreach ($value as $item) self::assertValueAt($schema['items'], $item);
        }
        if ($type === 'object') {
            if ($value instanceof \stdClass) $value = get_object_vars($value);
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $required) if (!array_key_exists($required, $value)) self::unsupported();
            foreach ($value as $name => $item) {
                if (!is_string($name) || (!array_key_exists($name, $properties) && ($schema['additionalProperties'] ?? true) === false)) self::unsupported();
                if (array_key_exists($name, $properties)) self::assertValueAt($properties[$name], $item);
            }
        }
    }

    /** JSON Schema date/date-time values stay unchanged; no timezone defaults or conversion. */
    private static function assertDateFormat(string $format, string $value): void
    {
        if ($format === 'date') {
            if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) !== 1
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) self::unsupported();
            return;
        }
        // Bounded RFC3339 spelling permits lowercase t/z; leap seconds need external facts and are rejected.
        if ($format !== 'date-time'
            || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt](?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]+)?(?:[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) self::unsupported();
    }

    /** Schema properties are maps even when their JSON object is empty. */
    private static function schemaObjects(array $schema): array
    {
        if (($schema['properties'] ?? null) instanceof \stdClass
            && get_object_vars($schema['properties']) === []) {
            $schema['properties'] = [];
        }
        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $name => $child) {
                if (is_array($child)) $schema['properties'][$name] = self::schemaObjects($child);
            }
        }
        if (is_array($schema['items'] ?? null)) $schema['items'] = self::schemaObjects($schema['items']);
        return $schema;
    }

    private static function nodes(mixed $value): int
    {
        if (!is_array($value)) return 1;
        $count = 1;
        foreach ($value as $item) $count += self::nodes($item);
        return $count;
    }

    private static function depth(mixed $value): int
    {
        if (!is_array($value) || $value === []) return 0;
        $depth = 0;
        foreach ($value as $item) $depth = max($depth, self::depth($item));
        return $depth + 1;
    }

    private static function unsupported(): never
    {
        throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Tool schema or value is outside the supported subset');
    }
}
