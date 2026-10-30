<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Versioned integrity check for sealed material file payloads only. */
final class MaterialAnalysisFilePayloadDigest
{
    public const VERSION = 2;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @param array<string,mixed> $payload */
    public static function version2(array $payload): string
    {
        return self::hash(self::canonicalize(self::payload($payload)));
    }

    /** @return array<string,mixed> */
    public static function verified(
        mixed $payload,
        string $storedDigest,
        mixed $version,
        mixed $contentPurgedTime,
    ): array {
        if ($contentPurgedTime !== null
            || preg_match('/\A[a-f0-9]{64}\z/D', $storedDigest) !== 1
            || !in_array($version, [null, self::VERSION, '2'], true)) {
            self::invalid();
        }
        $normalized = self::payload($payload);
        // Legacy evidence has no reproducible ordering contract. Verify exactly
        // this observed representation once; never guess or permute lost keys.
        $actualDigest = $version === null
            ? self::hash($normalized)
            : self::version2($normalized);
        if (!hash_equals($storedDigest, $actualDigest)) {
            self::invalid();
        }
        // Shape for the existing merger only after integrity has been proved.
        $normalized['schema'] = ['code' => 'sand_ai.material_analysis', 'version' => '1.0.0'];
        return $normalized;
    }

    /** @return array<string,mixed> */
    private static function payload(mixed $payload): array
    {
        $normalized = self::normalize($payload);
        if (!is_array($normalized) || array_is_list($normalized)) {
            self::invalid();
        }
        $schema = $normalized['schema'] ?? null;
        if (!is_array($schema) || count($schema) !== 2
            || ($schema['code'] ?? null) !== 'sand_ai.material_analysis'
            || ($schema['version'] ?? null) !== '1.0.0') {
            self::invalid();
        }
        $extensions = $normalized['extensions'] ?? null;
        if (is_array($extensions)) {
            if ($extensions !== [] && array_is_list($extensions)) {
                self::invalid();
            }
            $normalized['extensions'] = (object) $extensions;
        } elseif (!$extensions instanceof \stdClass) {
            self::invalid();
        }
        return $normalized;
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 512) {
            self::invalid();
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === [] || array_is_list($properties)) {
                $object = new \stdClass();
                foreach ($properties as $key => $item) {
                    $object->{(string) $key} = self::normalize($item, $depth + 1);
                }
                return $object;
            }
            $value = $properties;
        } elseif (is_object($value) || is_resource($value)) {
            self::invalid();
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item, $depth + 1);
            }
        }
        return $value;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $object = new \stdClass();
            foreach ($properties as $key => $item) {
                $object->{(string) $key} = self::canonicalize($item);
            }
            return $object;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }
            return $value;
        }
        ksort($value, SORT_STRING);
        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{(string) $key} = self::canonicalize($item);
        }
        return $object;
    }

    private static function hash(mixed $value): string
    {
        try {
            return hash('sha256', json_encode($value, self::JSON_FLAGS));
        } catch (\JsonException) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'A sealed material payload cannot be verified');
    }
}
