<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;

/** A configured protocol capability, never inferred from a host or model name. */
final class SkillOutputStrategy
{
    public const EXTRACT = 'neuron_json_extract_v1';
    public const ZAI = 'neuron_zai_json_object_v1';
    public const OPENAI = 'neuron_openai_json_schema_v1';

    public static function assert(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, [self::EXTRACT, self::ZAI, self::OPENAI], true)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Skill output strategy is unsupported');
        }
        return $value;
    }

    /** Absence defaults only when freezing a new Skill, never when reading history. */
    public static function forNewSnapshot(array $config, string $adapter): string
    {
        if ($adapter !== 'openai_compatible') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill output strategies require the official compatible provider');
        }
        return self::assert($config['skill_output_strategy'] ?? self::EXTRACT);
    }

    public static function isStructured(?string $value): bool
    {
        return in_array($value, [self::ZAI, self::OPENAI], true);
    }
}
