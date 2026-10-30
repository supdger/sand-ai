<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\NeuronOutputContract;
use app\Application\Skill\SkillOutputStrategy;
use app\Application\Workflow\StructuredWorkflowPolicy;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Validation\Validator;

/** A new frozen wire protocol; an absent marker preserves legacy requests. */
final readonly class LegalOutputContract extends NeuronOutputContract
{
    public const VERSION = 'legal_output_contract@2';

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public static function forNewRun(array $profile): array
    {
        (new LegalAnalysisProfileRegistry())->validate($profile);
        $responseClass = $profile['draft_kind'] === 'complaint_draft' ? ComplaintResponseSchema::class : LegalResponseSchema::class;
        $schema = (new JsonSchema())->generate($responseClass);
        foreach (['findings' => ['supporting_source_block_ids', 'contrary_source_block_ids'],
            'risks' => ['source_block_ids'], 'unverified_items' => ['source_block_ids']] as $list => $fields) {
            foreach ($fields as $field) {
                $schema['properties'][$list]['items']['properties'][$field]['items'] = ['type' => 'integer', 'minimum' => 1];
            }
        }
        $schema['properties']['draft']['properties']['source_block_ids']['items'] = ['type' => 'integer', 'minimum' => 1];
        $schema['properties']['draft']['properties']['kind']['enum'] = [$profile['draft_kind']];
        // Existing schemas retain their exact 16000-character content limit.
        $schema['properties']['draft']['properties']['content']['maxLength'] = in_array($profile['code'], RemedyInputPolicy::PROFILE_CODES, true)
            ? $profile['max_output_characters'] : 16000;
        $schema['properties']['review_required']['enum'] = [true];
        if ($profile['draft_kind'] === 'complaint_draft') {
            foreach (['sections','element_matrix','possible_defenses','missing_evidence','pending_decisions'] as $field) {
                $schema['properties']['complaint']['properties'][$field]['items']['properties']['source_block_ids']['items'] = ['type'=>'integer','minimum'=>1];
            }
            $schema['properties']['complaint']['properties']['sections']['minItems'] = 6;
            $schema['properties']['complaint']['properties']['sections']['maxItems'] = 6;
            $schema['properties']['complaint']['properties']['sections']['items']['properties']['key']['enum'] = ComplaintDraftPolicy::SECTION_KEYS;
        }
        self::closeObjects($schema);
        return ['version' => self::VERSION, 'strategy' => SkillOutputStrategy::ZAI,
            'schema' => $schema, 'schema_sha256' => StructuredWorkflowPolicy::fingerprint($schema)];
    }

    /** @param array<string,mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): ?self
    {
        if (!array_key_exists('legal_output_contract', $snapshot)) return null;
        $value = $snapshot['legal_output_contract'];
        if (!is_array($value) || ($value['version'] ?? null) !== self::VERSION
            || !is_array($value['schema'] ?? null) || !is_string($value['schema_sha256'] ?? null)
            || array_diff(array_keys($value), ['version', 'strategy', 'schema', 'schema_sha256']) !== []
            || !hash_equals($value['schema_sha256'], StructuredWorkflowPolicy::fingerprint($value['schema']))
            || !is_array($snapshot['legal_analysis']['profile'] ?? null)
            || !hash_equals(StructuredWorkflowPolicy::fingerprint($value), StructuredWorkflowPolicy::fingerprint(self::forNewRun($snapshot['legal_analysis']['profile'])))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen legal output contract is invalid');
        }
        return new self(SkillOutputStrategy::assert($value['strategy'] ?? null), $value['schema']);
    }

    /** Extract one complete document with SDK validation, preserving original scalar types. */
    public function originalJson(string $content): string
    {
        $original = trim($content);
        if (str_starts_with($original, '```')) {
            if (substr_count($original, '```') !== 2
                || preg_match('/\A```json[ \t]*\R([\s\S]*)\R```[ \t]*\z/uD', $original, $matches) !== 1) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Legal output must contain one JSON document');
            }
            $original = $matches[1];
        }
        // Only SDK parsing of this controlled document is enabled: no prose/bracket search.
        $parsed = (new JsonExtractor())->setExtractors([static fn (string $text): array => [$text]])->getJson($original);
        if ($parsed === null) throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Legal output is not valid JSON');
        try {
            $responseClass = isset($this->schema['properties']['complaint']) ? ComplaintResponseSchema::class : LegalResponseSchema::class;
            $typed = (new Deserializer())->fromJson($original, $responseClass);
            if ((!$typed instanceof LegalResponseSchema && !$typed instanceof ComplaintResponseSchema) || Validator::validate($typed) !== []) {
                throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Legal output violates its typed schema');
            }
        } catch (\NeuronAI\StructuredOutput\Deserializer\DeserializerException) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'Legal output violates its typed schema');
        }
        // Business validation receives original JSON, never SDK-normalized/coerced data.
        return $original;
    }

    private static function closeObjects(array &$schema): void
    {
        if (($schema['type'] ?? null) === 'object') $schema['additionalProperties'] = false;
        foreach ($schema as &$child) if (is_array($child)) self::closeObjects($child);
    }
}
