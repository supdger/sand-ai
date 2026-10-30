<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Skill\SkillOutputContract;
use app\Application\Skill\SkillOutputStrategy;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Validator;

/** Immutable server-side review protocol; absence preserves historical requests. */
final readonly class MaterialReviewOutputContract extends \app\Application\Gateway\NeuronOutputContract
{
    public const VERSION = 'material_review_output_contract@2';

    /** @param array<string,mixed> $schema */
    private function __construct(string $strategy, array $schema)
    {
        parent::__construct($strategy, $schema);
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function forNewRun(array $profile): array
    {
        $strategy = SkillOutputStrategy::assert($profile['review_output_strategy'] ?? SkillOutputStrategy::EXTRACT);
        $schema = (new JsonSchema())->generate(MaterialReviewResponseSchema::class);
        // SDK 4.0.2 array SchemaProperty describes class items; primitive integer
        // item metadata is a business leaf supplied by the declared ArrayOf rule.
        $schema['properties']['items_to_verify']['items']['properties']['source_block_ids']['items'] = ['type' => 'integer', 'minimum' => 1];
        return ['version' => self::VERSION, 'strategy' => $strategy,
            'schema' => $schema, 'schema_sha256' => StructuredWorkflowPolicy::fingerprint($schema)];
    }

    /** @param array<string,mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): ?self
    {
        if (!array_key_exists('material_review_output_contract', $snapshot)) return null;
        $value = $snapshot['material_review_output_contract'];
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION
            || !is_array($value['schema'] ?? null) || !is_string($value['schema_sha256'] ?? null)
            || array_diff(array_keys($value), ['version', 'strategy', 'schema', 'schema_sha256']) !== []
            || !hash_equals($value['schema_sha256'], StructuredWorkflowPolicy::fingerprint($value['schema']))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Review output contract is invalid');
        }
        return new self(SkillOutputStrategy::assert($value['strategy'] ?? null), $value['schema']);
    }

    public function protocol(): \app\Application\Gateway\NeuronOutputContract
    {
        return $this;
    }

    public function instruction(): string
    {
        return "\n\nGenerate one JSON object with the following complete schema:\n"
            . json_encode($this->schema, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function validateTyped(string $originalJson): void
    {
        try {
            $value = (new Deserializer())->fromJson($originalJson, MaterialReviewResponseSchema::class);
            if (!$value instanceof MaterialReviewResponseSchema || Validator::validate($value) !== []) {
                throw new MaterialReviewOutputRejected('SAND_AI_SCHEMA_UNSUPPORTED');
            }
        } catch (MaterialReviewOutputRejected $failure) {
            throw $failure;
        } catch (\NeuronAI\StructuredOutput\Deserializer\DeserializerException) {
            throw new MaterialReviewOutputRejected('SAND_AI_SCHEMA_UNSUPPORTED');
        }
    }
}
