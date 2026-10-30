<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelConversationPolicy;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Tool\ToolFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Generic conversations retain canonical task IDs, never Channel ownership or a second message store. */
final class AgentConversationGateway
{
    public static function conversationId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\z/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent conversation id is invalid');
        }
        return $value;
    }

    public function scope(UsageAttribution $attribution, array $definition, string $conversationId): string
    {
        // JSONB object ordering differs from the resolver. Preserve the original
        // resolver order so already frozen scopes survive persistence unchanged.
        $fields = ['definition_id', 'version_id', 'code', 'snapshot_sha256',
            'knowledge_publication_id', 'knowledge_version_id', 'knowledge_scope_sha256'];
        $hasReadonly = array_key_exists('readonly_tool_bindings', $definition);
        if (count($definition) !== count($fields) + (int) $hasReadonly) self::invalid();
        $binding = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $definition)) self::invalid();
            $binding[$field] = $definition[$field];
        }
        if ($hasReadonly) {
            // New bindings use canonical typed JSON: PostgreSQL object key order is immaterial.
            // The original seven-field scope remains byte-for-byte unchanged.
            if (!is_array($definition['readonly_tool_bindings'])) self::invalid();
            $binding['readonly_tool_bindings_sha256'] = self::readonlyBindingsDigest($definition['readonly_tool_bindings']);
        }
        return hash('sha256', json_encode([
            RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            $binding, self::conversationId($conversationId),
        ], JSON_THROW_ON_ERROR));
    }

    public static function assertReadonlyProtocol(mixed $limits): void
    {
        if (!is_array($limits) || count($limits) !== count(AgentReadonlyToolProtocol::LIMITS)) self::invalid();
        // Canonical JSON alone collapses 4.0 and 4. Preserve each immutable scalar's
        // exact PHP type while allowing PostgreSQL to reorder object keys.
        foreach (AgentReadonlyToolProtocol::LIMITS as $field => $expected) {
            if (!array_key_exists($field, $limits) || $limits[$field] !== $expected) self::invalid();
        }
    }

    /** No I/O: current catalog and source authorization remain mandatory at their existing guards. */
    public static function readonlyBindingsDigest(array $bindings): string
    {
        if (!array_is_list($bindings) || $bindings === [] || count($bindings) > 2) self::invalid();
        foreach ($bindings as $binding) {
            if (!is_array($binding) || !is_string($binding['snapshot_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $binding['snapshot_sha256']) !== 1) self::invalid();
            self::assertReadonlyProtocol($binding['execution_limits'] ?? null);
            $unsigned = $binding;
            unset($unsigned['snapshot_sha256']);
            if (!hash_equals($binding['snapshot_sha256'], ToolFingerprint::for('agent_readonly_binding', $unsigned))) self::invalid();
        }
        return ToolFingerprint::for('agent_readonly_catalog', ['bindings' => $bindings]);
    }

    /** Must run inside task creation's transaction, before the original-key lookup. */
    public function lock(string $scope): void
    {
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['agent-conversation:' . $scope]);
    }

    /** Called only for a new request after original-key replay has been resolved. */
    public function freeze(UsageAttribution $attribution, array $definition, string $conversationId, int $limit): array
    {
        if ($limit < 0 || $limit > 4) self::invalid();
        $scope = $this->scope($attribution, $definition, $conversationId);
        $busy = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_task_run WHERE environment_id=? AND task_type='agent_run'
             AND input#>>'{_agent_conversation,scope_sha256}'=?
             AND state NOT IN ('succeeded','failed','canceled') AND delete_time IS NULL LIMIT 1",
            [$attribution->environmentId, $scope]);
        if ($busy !== []) {
            throw new ApiProblem('SAND_AI_CONVERSATION_BUSY', 'An agent conversation turn is still being processed', true);
        }
        $predicate = "environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?
            AND task_type='agent_run' AND input#>>'{_agent_conversation,scope_sha256}'=?
            AND input->'_agent_definition'=CAST(? AS jsonb) AND NOT jsonb_exists(input,'_channel')
            AND state='succeeded' AND result#>>'{review_required}'='false'
            AND content_purged_time IS NULL AND status=1 AND delete_time IS NULL";
        $parameters = [$attribution->environmentId, $attribution->organizationId, $attribution->applicationId,
            $attribution->workloadClientId, $scope, json_encode($definition, JSON_THROW_ON_ERROR)];
        $count = Db::connect('pgsql')->query("SELECT count(*) AS n FROM sand_ai_task_run WHERE {$predicate}", $parameters);
        $rows = $limit === 0 ? [] : Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_task_run WHERE {$predicate} ORDER BY id DESC LIMIT ?", [...$parameters, $limit]);
        $ids = array_reverse(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        return ['scope_sha256' => $scope, 'history_task_ids' => $ids,
            'omitted_prior_turn_count' => max(0, (int) $count[0]['n'] - count($ids))];
    }

    /** Frozen IDs ensure retrying a task never silently substitutes a different earlier conversation. */
    public function history(TaskRun $task, UsageAttribution $attribution, array $input, int $limit): array
    {
        $conversation = $input['_agent_conversation'] ?? null;
        $definition = $input['_agent_definition'] ?? null;
        if (!is_array($conversation) || !is_array($definition) || isset($input['_channel'])
            || !is_string($input['conversation_id'] ?? null)
            || ($conversation['scope_sha256'] ?? null) !== $this->scope($attribution, $definition, $input['conversation_id'])
            || !is_array($conversation['history_task_ids'] ?? null) || !array_is_list($conversation['history_task_ids'])
            || count($conversation['history_task_ids']) > $limit || $limit < 0 || $limit > 4
            || !is_int($conversation['omitted_prior_turn_count'] ?? null) || $conversation['omitted_prior_turn_count'] < 0) self::invalid();
        $messages = [];
        $references = [];
        $chars = 0;
        $previous = 0;
        foreach ($conversation['history_task_ids'] as $id) {
            if (!is_int($id) || $id <= $previous || $id >= (int) $task->id) self::invalid();
            $previous = $id;
            $prior = TaskRun::where('id', $id)->where('environment_id', $attribution->environmentId)
                ->where('organization_id', $attribution->organizationId)->where('application_id', $attribution->applicationId)
                ->where('workload_client_id', $attribution->workloadClientId)->where('task_type', 'agent_run')
                ->where('state', 'succeeded')->where('status', 1)->whereNull('delete_time')->whereNull('content_purged_time')->find();
            if ($prior === null) self::invalid();
            $oldInput = is_object($prior->input) ? (array) $prior->input : $prior->input;
            $result = is_object($prior->result) ? (array) $prior->result : $prior->result;
            if (!is_array($oldInput) || !is_array($result) || isset($oldInput['_channel'])
                || ($oldInput['_trusted_identity_scope'] ?? null) !== ($input['_trusted_identity_scope'] ?? null)
                || ($oldInput['_agent_conversation']['scope_sha256'] ?? null) !== $conversation['scope_sha256']
                || !RequestPayloadFingerprint::same(['binding' => $oldInput['_agent_definition'] ?? null], ['binding' => $definition])
                || ($result['source_provenance_version'] ?? null) !== 2 || ($result['review_required'] ?? null) !== false
                || !is_string($oldInput['query'] ?? null) || trim($oldInput['query']) === ''
                || !is_string($result['output']['content'] ?? null) || trim($result['output']['content']) === '') self::invalid();
            $sources = ChannelConversationPolicy::sourceReferences($result['source_references'] ?? []);
            $chars += mb_strlen($oldInput['query'], 'UTF-8') + mb_strlen($result['output']['content'], 'UTF-8');
            if ($chars > 12000) {
                throw new ApiProblem('SAND_AI_CONVERSATION_CONTEXT_LIMIT', 'Agent conversation context exceeds its limit');
            }
            $references = ChannelConversationPolicy::sourceReferences([...$references, ...$sources]);
            $messages[] = ['role' => 'user', 'content' => $oldInput['query'], 'task_id' => $id, 'source_references' => $sources];
            $messages[] = ['role' => 'assistant', 'content' => $result['output']['content'], 'task_id' => $id, 'source_references' => $sources];
        }
        return ['messages' => $messages, 'source_groups' => array_chunk($references, 8),
            'source_references' => $references, 'covered_task_ids' => $conversation['history_task_ids'],
            'omitted_prior_turn_count' => $conversation['omitted_prior_turn_count'], 'closed_after_task_id' => 0];
    }

    /** IDs only; the controller must authorize and project every task before returning anything. */
    public function taskIds(UsageAttribution $attribution, array $definition, string $conversationId, ?int $before, int $limit): array
    {
        if ($limit < 1 || $limit > 20 || ($before !== null && $before < 1)) self::invalid();
        $rows = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_task_run WHERE environment_id=? AND organization_id=? AND application_id=? AND workload_client_id=?
             AND task_type='agent_run' AND input#>>'{_agent_conversation,scope_sha256}'=?
             AND NOT jsonb_exists(input,'_channel') AND id<? AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL
             ORDER BY id DESC LIMIT ?",
            [$attribution->environmentId, $attribution->organizationId, $attribution->applicationId,
                $attribution->workloadClientId, $this->scope($attribution, $definition, $conversationId), $before ?? PHP_INT_MAX, $limit]);
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent conversation coverage is unavailable');
    }
}
