<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;

final class ChannelConversationPolicy
{
    private const MAX_SOURCE_REFERENCES = 64;

    /**
     * @param list<array<string,mixed>> $rows Newest first, from the trusted scoped query.
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $agent
     * @return array{messages:list<array{role:string,content:string}>,source_groups:list<list<array<string,mixed>>>,source_references:list<array<string,mixed>>}
     */
    public static function history(array $rows, array $channel, array $agent, string $identityScope, int $turnLimit = 2): array
    {
        if ($turnLimit < 0 || $turnLimit > 4 || count($rows) > $turnLimit
            || preg_match('/^[a-f0-9]{64}$/D', $identityScope) !== 1) self::invalid();
        $messages = [];
        $coveredTaskIds = [];
        $references = [];
        $chars = 0;
        foreach (array_reverse($rows) as $row) {
            if (($row['state'] ?? null) !== 'succeeded') self::invalid();
            $input = self::object($row['input'] ?? null);
            $result = self::object($row['result'] ?? null);
            $frozenChannel = $input['_channel'] ?? null;
            $frozenAgent = $input['_agent_definition'] ?? null;
            if (!is_array($frozenChannel) || !is_array($frozenAgent)
                || ($input['_trusted_identity_scope'] ?? null) !== $identityScope
                || ($frozenChannel['delivery_platform'] ?? null) !== ($channel['delivery_platform'] ?? null)
                || !self::same($channel, $frozenChannel, ['channel_binding_id','code','revision','agent_definition_id','conversation_scope'])
                || !self::same($agent, $frozenAgent, [
                    'definition_id','version_id','code','snapshot_sha256',
                    'knowledge_publication_id','knowledge_version_id','knowledge_scope_sha256',
                ])) self::invalid();
            $query = $input['query'] ?? null;
            $answer = $result['output']['content'] ?? null;
            if (($result['source_provenance_version'] ?? null) !== 2) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel conversation source coverage is unavailable; close it before continuing (bot command: /结束对话)');
            }
            // A retained answer may depend on sources inherited from earlier turns.
            $sources = $result['source_references'] ?? null;
            if (!is_string($query) || trim($query) === '' || mb_strlen($query, 'UTF-8') > 500
                || !is_string($answer) || trim($answer) === '' || !is_array($sources) || !array_is_list($sources)
                || ($result['review_required'] ?? null) !== false) self::invalid();
            $chars += mb_strlen($query, 'UTF-8') + mb_strlen($answer, 'UTF-8');
            if ($chars > 12000) throw new ApiProblem('SAND_AI_CONVERSATION_CONTEXT_LIMIT', 'Conversation context is too long; close it before continuing (bot command: /结束对话)');
            $references = self::sourceReferences([...$references, ...$sources]);
            $taskId = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($taskId === false) self::invalid();
            $coveredTaskIds[] = $taskId;
            $messages[] = ['role' => 'user', 'content' => $query, 'task_id' => $taskId, 'source_references' => $sources];
            $messages[] = ['role' => 'assistant', 'content' => $answer, 'task_id' => $taskId, 'source_references' => $sources];
        }
        // The existing source authorizer accepts at most eight blocks per batch.
        return ['messages' => $messages, 'source_groups' => array_chunk($references, 8), 'source_references' => $references,
            'covered_task_ids' => $coveredTaskIds, 'context_turn_limit' => $turnLimit];
    }

    /** @param list<array<string,mixed>> $sources @return list<array{source_block_id:int,file_id:int,locator_type:string,locator:array}> */
    public static function sourceReferences(array $sources): array
    {
        if (!array_is_list($sources)) self::invalid();
        $references = [];
        foreach ($sources as $source) {
            if (!is_array($source) || !is_int($source['source_block_id'] ?? null) || $source['source_block_id'] < 1
                || !is_int($source['file_id'] ?? null) || $source['file_id'] < 1
                || !is_string($source['locator_type'] ?? null) || trim($source['locator_type']) === ''
                || !is_array($source['locator'] ?? null)) self::invalid();
            $reference = [
                'source_block_id' => $source['source_block_id'], 'file_id' => $source['file_id'],
                'locator_type' => $source['locator_type'], 'locator' => $source['locator'],
            ];
            $blockId = $reference['source_block_id'];
            if (isset($references[$blockId]) && !RequestPayloadFingerprint::same($references[$blockId], $reference, [])) {
                self::invalid();
            }
            $references[$blockId] = $reference;
            if (count($references) > self::MAX_SOURCE_REFERENCES) {
                throw new ApiProblem('SAND_AI_CONVERSATION_CONTEXT_LIMIT', 'Conversation has too many retained sources; close it before continuing (bot command: /结束对话)');
            }
        }
        return array_values($references);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right @param list<string> $fields */
    private static function same(array $left, array $right, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $left) || !array_key_exists($field, $right) || $left[$field] !== $right[$field]) return false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true, 32);
        if (is_object($value)) $value = (array) $value;
        if (!is_array($value) || array_is_list($value)) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel conversation facts are invalid');
    }
}
