<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\NeuronOutputContract;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Skill\SkillOutputStrategy;
use NeuronAI\StructuredOutput\JsonExtractor;
use NeuronAI\StructuredOutput\JsonSchema;

/** Explicit new-task protocol. No parser, transport, automatic retry or historical input rewrite. */
final readonly class MeetingSummaryOutputContract extends NeuronOutputContract
{
    public const VERSION = 'meeting_output_contract@1';
    public const BOUNDED_VERSION = 'meeting_output_contract@2';

    public function __construct(
        string $strategy,
        array $schema,
        public string $stage,
        public string $nodeInputSha256,
        public string $segmentIdsSha256,
        public array $claimLimits,
        public string $contractVersion = self::VERSION,
    ) {
        parent::__construct($strategy, $schema);
    }

    /** Initial task creation only; an explicit structured configuration is required.
     * @param array<string,mixed> $config @param array<string,mixed> $capacity
     * @return array<string,mixed>
     */
    public static function forNewTask(array $config, string $adapter, array $capacity): array
    {
        if ($adapter !== 'openai_compatible' || !MeetingLocalPlanning::enabled($capacity)
            || !SkillOutputStrategy::isStructured($config['skill_output_strategy'] ?? null)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Meeting structured output requires an explicit local-planning protocol');
        }
        return self::marker(SkillOutputStrategy::assert($config['skill_output_strategy']), self::BOUNDED_VERSION);
    }

    /** Reconstruct from immutable task and node inputs, also during receipt recovery.
     * Absence alone means legacy plain wire; malformed or null markers never fall back.
     * @param array<string,mixed> $input @param array<string,mixed> $nodeInput
     */
    public static function forNode(array $input, string $kind, array $nodeInput): ?self
    {
        $meeting = $input['_meeting_summary'] ?? null;
        if (!is_array($meeting)) self::invalid();
        if (!array_key_exists('output_contract', $meeting)) return null;
        $value = self::assertMarker($meeting['output_contract']);
        if (!is_array($input['_meeting_capacity'] ?? null)
            || !MeetingLocalPlanning::enabled($input['_meeting_capacity'])
            || ($meeting['output_contract_revision'] ?? null) !== 2) self::invalid();
        if (!in_array($kind, ['chunk', 'merge'], true)
            || array_diff(array_keys($nodeInput), $kind === 'merge'
                ? ['messages', 'segment_ids', 'children'] : ['messages', 'segment_ids']) !== []
            || !is_array($nodeInput['segment_ids'] ?? null) || !array_is_list($nodeInput['segment_ids'])
            || $nodeInput['segment_ids'] === [] || count($nodeInput['segment_ids']) > 10000
            || !is_array($nodeInput['messages'] ?? null) || !array_is_list($nodeInput['messages'])
            || count($nodeInput['messages']) !== 2) self::invalid();
        $seen = [];
        foreach ($nodeInput['segment_ids'] as $id) {
            if (!is_string($id) || preg_match('/\AS[1-9][0-9]{0,4}\z/D', $id) !== 1
                || (int) substr($id, 1) > 10000 || isset($seen[$id])) self::invalid();
            $seen[$id] = true;
        }
        foreach ($nodeInput['messages'] as $index => $message) {
            if (!is_array($message) || count($message) !== 2
                || ($message['role'] ?? null) !== ($index === 0 ? 'system' : 'user')
                || !is_string($message['content'] ?? null) || $message['content'] === ''
                || preg_match('//u', $message['content']) !== 1) self::invalid();
        }
        // Merge children remain the verified adjacent nodes, never free-form supplied summaries.
        if ($kind === 'merge' && (!is_array($nodeInput['children'] ?? null)
            || !array_is_list($nodeInput['children']) || count($nodeInput['children']) !== 2
            || !is_string($nodeInput['children'][0]) || !is_string($nodeInput['children'][1])
            || $nodeInput['children'][0] === '' || $nodeInput['children'][1] === ''
            || $nodeInput['children'][0] === $nodeInput['children'][1])) self::invalid();
        $limits = self::claimLimits($meeting, $kind, $nodeInput);
        $schema = $value['schema'];
        foreach ($limits as $field => $limit) $schema['properties'][$field]['maxItems'] = $limit;
        return new self($value['strategy'], $schema, $kind === 'chunk' ? 'map' : 'merge',
            RequestPayloadFingerprint::fingerprint($nodeInput, []),
            RequestPayloadFingerprint::fingerprint($nodeInput['segment_ids'], []), $limits, $value['version']);
    }

    /** Bind protocol selection to the actual stage and sealed source input, not just a schema label. */
    public function fingerprint(): array
    {
        return [...parent::fingerprint(), 'version' => $this->contractVersion, 'stage' => $this->stage,
            'node_input_sha256' => $this->nodeInputSha256, 'segment_ids_sha256' => $this->segmentIdsSha256];
    }

    /** Shared submission/recovery normalization must preserve only this exact marker.
     * @return array<string,mixed>
     */
    public static function assertMarker(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['version'] ?? null) || !is_string($value['strategy'] ?? null)
            || !SkillOutputStrategy::isStructured($value['strategy'])
            || !RequestPayloadFingerprint::same($value, self::marker($value['strategy'], $value['version'] ?? ''), [])) self::invalid();
        return $value;
    }

    /** Complete-document extraction only: no prose search, fence stripping or scalar casting.
     * The SDK re-encodes parsed JSON; business validation consumes it while the receipt retains original bytes.
     */
    public function jsonDocument(string $content): string
    {
        $parsed = (new JsonExtractor())
            ->setExtractors([static fn (string $text): array => [$text]])->getJson($content);
        if ($parsed === null) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_RESULT_INVALID', 'The meeting summary is not one complete JSON document');
        }
        return $parsed;
    }

    /** SDK schema generation only. Original model JSON still goes to MeetingSummaryEvidence.
     * @return array<string,mixed>
     */
    private static function marker(string $strategy, string $version = self::VERSION): array
    {
        if (!in_array($version, [self::VERSION, self::BOUNDED_VERSION], true)) self::invalid();
        $schema = (new JsonSchema())->generate(MeetingResponseSchema::class);
        $schema['additionalProperties'] = false;
        foreach (['summary', 'key_points', 'decisions', 'action_items', 'unresolved'] as $field) {
            $schema['properties'][$field]['items']['additionalProperties'] = false;
            $schema['properties'][$field]['items']['properties']['segment_ids']['items'] = [
                'type' => 'string', 'pattern' => '^S[1-9][0-9]{0,4}$',
            ];
            $schema['properties'][$field]['items']['properties']['segment_ids']['uniqueItems'] = true;
        }
        return ['version' => $version, 'strategy' => $strategy, 'schema' => $schema,
            'schema_sha256' => RequestPayloadFingerprint::fingerprint($schema, [])];
    }

    /**
     * The node input is sealed before dispatch. Merge callers also reconstruct
     * it from the two verified child results before accepting or sending it.
     * Concrete limits are part of the generated schema and its dispatch fingerprint.
     * @param array<string,mixed> $meeting @param array<string,mixed> $nodeInput
     * @return array<string,int>
     */
    public static function claimLimits(array $meeting, string $kind, array $nodeInput): array
    {
        if (!in_array($kind, ['chunk', 'merge'], true)
            || !is_array($nodeInput['messages'] ?? null) || count($nodeInput['messages']) !== 2
            || !is_array($nodeInput['segment_ids'] ?? null) || $nodeInput['segment_ids'] === []) self::invalid();
        $limits = array_fill_keys(['summary', 'key_points', 'decisions', 'action_items', 'unresolved'],
            MeetingSummaryEvidence::MAX_CLAIMS);
        if (!array_key_exists('output_contract', $meeting)) return $limits;
        $marker = self::assertMarker($meeting['output_contract']);
        if ($marker['version'] === self::VERSION || $kind === 'chunk') return $limits;
        if ($kind !== 'merge') self::invalid();
        $content = $nodeInput['messages'][1]['content'] ?? null;
        $prefix = "合并相邻会议摘要：\n";
        if (!is_string($content) || !str_starts_with($content, $prefix)
            || mb_strlen($content, 'UTF-8') > 30000) self::invalid();
        try {
            $children = json_decode(substr($content, strlen($prefix)), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!is_array($children) || !array_is_list($children) || count($children) !== 2) self::invalid();
        foreach ($children as $child) {
            if (!is_array($child) || count($child) !== count($limits)
                || array_diff(array_keys($child), array_keys($limits)) !== []
                || mb_strlen(json_encode($child, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'UTF-8') > MeetingSummaryEvidence::MAX_MERGE_CHILD_CHARS) self::invalid();
        }
        // Each child is at most 10000 characters, so two bounded counts cannot overflow.
        foreach ($limits as $field => $_) {
            $count = 0;
            foreach ($children as $child) {
                if (!is_array($child) || !is_array($child[$field] ?? null)
                    || !array_is_list($child[$field]) || count($child[$field]) > MeetingSummaryEvidence::MAX_MERGE_CHILD_CHARS) self::invalid();
                $count += count($child[$field]);
            }
            $limits[$field] = $count;
        }
        if ($limits['summary'] < 1) self::invalid();
        return $limits;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The frozen meeting output contract or stage input is invalid');
    }
}
