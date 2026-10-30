<?php

declare(strict_types=1);

namespace app\Application\Skill;

/** Server-only dynamic business schema; not an invented PHP entity class. */
final readonly class SkillOutputContract extends \app\Application\Gateway\NeuronOutputContract
{
    /** @param array<string,mixed> $schema */
    public function __construct(string $strategy, array $schema)
    {
        parent::__construct($strategy, $schema);
        if (SkillSchemaPolicy::normalize($schema) !== $schema) {
            throw new \InvalidArgumentException('Skill output schema must be normalized');
        }
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $schema */
    public static function fromSnapshot(array $snapshot, array $schema): ?self
    {
        // Old immutable versions intentionally retain their old parser and body.
        if (!array_key_exists('skill_output_strategy', $snapshot)) return null;
        return new self(SkillOutputStrategy::assert($snapshot['skill_output_strategy']), SkillSchemaPolicy::normalize($schema));
    }

    /** @return array{strategy:string,schema:array<string,mixed>} */
    public function fingerprint(): array
    {
        return ['strategy' => $this->strategy, 'schema' => $this->schema];
    }

    /** Official extraction, constrained to the one complete JSON envelope required by SandAI. */
    public function extract(string $content): string
    {
        $json = (new \NeuronAI\StructuredOutput\JsonExtractor())
            ->setExtractors([static fn (string $text): array => [$text]])
            ->getJson($content);
        if ($json === null) {
            throw new \JsonException('Skill completion is not one JSON document');
        }
        // SDK canonical re-encoding can turn 1.0 into 1. Preserve the original
        // numeric type for the dynamic business validator and its depth64 map.
        return $content;
    }
}
