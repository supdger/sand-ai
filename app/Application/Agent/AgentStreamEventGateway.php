<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelConversationPolicy;
use app\Application\Knowledge\KnowledgeConversationContextMask;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptEnvelope;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Private durable native text deltas; browser replay never dispatches a model. */
final class AgentStreamEventGateway
{
    /** The caller has revalidated current IAM/source grants immediately before this append. */
    public function append(TaskRun $task, TaskExecutionLease $lease, int $round, int $invocationId,
        int $dispatchId, string $text, array $references, bool $completed = false): int
    {
        if ($text === '' || strlen($text) > 8192 || !mb_check_encoding($text, 'UTF-8')
            || $round < 1 || $round > $this->maximumRound($task)) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent stream text is invalid');
        }
        $references = ChannelConversationPolicy::sourceReferences($references);
        return Db::connect('pgsql')->transaction(function () use ($task, $lease, $round, $invocationId, $dispatchId, $text, $references, $completed): int {
            $db = Db::connect('pgsql');
            $rows = $db->query("SELECT id FROM sand_ai_task_run WHERE id=? AND environment_id=?
                AND task_type='agent_run' AND state='running' AND input->>'_agent_stream'='true'
                AND lease_owner=? AND lease_token=? AND lease_fence=? AND lease_expire_time>?
                AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL FOR UPDATE",
                [$lease->taskId, (int) $task->environment_id, $lease->owner, $lease->token, $lease->fence, date('Y-m-d H:i:s')]);
            if (count($rows) !== 1 || $lease->taskId !== (int) $task->id) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent stream lease changed');
            }
            $call = $this->dispatch($task, $invocationId, $dispatchId, $round, $completed ? 'completed' : 'active');
            $limits = $db->query('SELECT COALESCE(MAX(event_no),0) AS last_no,COALESCE(SUM(text_bytes),0) AS total_bytes
                FROM sand_ai_agent_stream_event WHERE task_id=?', [(int) $task->id])[0];
            $eventNo = (int) $limits['last_no'] + 1;
            if ($eventNo > 4096 || (int) $limits['total_bytes'] + strlen($text) > 262144) {
                throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Agent stream persistence limit exceeded');
            }
            $plaintext = json_encode(['task_id' => (int) $task->id, 'event_no' => $eventNo, 'round' => $round,
                'text' => $text, 'source_references' => $references, 'text_policy_version' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $envelope = $this->cipher()->encrypt($plaintext, $this->context($task, $call, $eventNo));
            $encoded = ['receipt_version' => $envelope->receiptVersion, 'cipher_version' => $envelope->cipherVersion,
                'key_id' => $envelope->keyId, 'nonce' => base64_encode($envelope->nonce), 'tag' => base64_encode($envelope->tag),
                'ciphertext' => base64_encode($envelope->ciphertext), 'plaintext_bytes' => $envelope->plaintextBytes,
                'content_hmac' => base64_encode($envelope->contentHmac)];
            $db->execute('INSERT INTO sand_ai_agent_stream_event
                (task_id,event_no,round_no,invocation_id,dispatch_id,ciphertext_envelope,plaintext_bytes,text_bytes)
                VALUES (?,?,?,?,?,CAST(? AS jsonb),?,?)', [(int) $task->id, $eventNo, $round, $invocationId, $dispatchId,
                    json_encode($encoded, JSON_THROW_ON_ERROR), strlen($plaintext), strlen($text)]);
            return $eventNo;
        });
    }

    /** Returned references are internal and MUST be currently authorized before sending text to the browser. */
    public function read(TaskRun $task, int $after, int $limit = 32): array
    {
        if ($limit < 1 || $limit > 32 || $after < 0 || $after > 4096 || $task->content_purged_time !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent stream is unavailable');
        }
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_agent_stream_event
            WHERE task_id=? AND event_no>? ORDER BY event_no LIMIT ?', [(int) $task->id, $after, $limit]);
        $events = [];
        foreach ($rows as $row) {
            $call = $this->dispatch($task, (int) $row['invocation_id'], (int) $row['dispatch_id'], (int) $row['round_no'], 'any');
            $encoded = is_string($row['ciphertext_envelope']) ? json_decode($row['ciphertext_envelope'], true, 16, JSON_THROW_ON_ERROR) : $row['ciphertext_envelope'];
            if (!is_array($encoded)) self::invalid();
            foreach (['nonce', 'tag', 'ciphertext', 'content_hmac'] as $key) {
                $encoded[$key] = is_string($encoded[$key] ?? null) ? base64_decode($encoded[$key], true) : false;
                if ($encoded[$key] === false) self::invalid();
            }
            if (!is_string($encoded['receipt_version'] ?? null) || !is_string($encoded['cipher_version'] ?? null)
                || !is_string($encoded['key_id'] ?? null) || !is_int($encoded['plaintext_bytes'] ?? null)) self::invalid();
            $envelope = new OutcomeReceiptEnvelope($encoded['receipt_version'], $encoded['cipher_version'], $encoded['key_id'],
                $encoded['nonce'], $encoded['tag'], $encoded['ciphertext'], $encoded['plaintext_bytes'], $encoded['content_hmac']);
            $event = json_decode($this->cipher()->decrypt($envelope, $this->context($task, $call, (int) $row['event_no'])), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($event) || ($event['task_id'] ?? null) !== (int) $task->id
                || ($event['event_no'] ?? null) !== (int) $row['event_no'] || ($event['round'] ?? null) !== (int) $row['round_no']
                || !is_string($event['text'] ?? null) || strlen($event['text']) !== (int) $row['text_bytes']) self::invalid();
            if (($event['text_policy_version'] ?? null) !== 1) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Legacy agent text has no verified stream privacy policy');
            }
            $event['source_references'] = ChannelConversationPolicy::sourceReferences($event['source_references'] ?? []);
            $events[] = $event;
        }
        return $events;
    }

    /** Receipt recovery finishes the same masked prefix; it never duplicates existing event IDs. */
    public function finishRound(TaskRun $task, TaskExecutionLease $lease, int $round,
        int $invocationId, string $content, array $references): void
    {
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $round, $invocationId, $content, $references): void {
            Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? FOR UPDATE', [(int) $task->id]);
            $dispatches = Db::connect('pgsql')->query('SELECT id FROM sand_ai_invocation_dispatch
                WHERE invocation_id=? AND parent_task_run_id=? AND workflow_node_code=?',
                [$invocationId, (int) $task->id, 'agent_round_' . $round]);
            if (count($dispatches) !== 1) self::invalid();
            $dispatchId = (int) $dispatches[0]['id'];
            $this->dispatch($task, $invocationId, $dispatchId, $round, 'completed');
            // A continued round has its own measured invocation; its receipt must
            // finish only that invocation's prefix, retaining older failed text history.
            $ownEventNumbers = array_fill_keys(array_map(static fn (array $row): int => (int) $row['event_no'],
                Db::connect('pgsql')->query('SELECT event_no FROM sand_ai_agent_stream_event WHERE task_id=? AND invocation_id=? AND dispatch_id=?',
                    [(int) $task->id, $invocationId, $dispatchId])), true);
            $prefix = '';
            $after = 0;
            do {
                $events = $this->read($task, $after);
                foreach ($events as $event) {
                    $after = $event['event_no'];
                    if ($event['round'] === $round && isset($ownEventNumbers[$event['event_no']])) $prefix .= $event['text'];
                }
            } while (count($events) === 32);
            $masked = KnowledgeConversationContextMask::text($content);
            if (!str_starts_with($masked, $prefix)) self::invalid();
            $remaining = substr($masked, strlen($prefix));
            while ($remaining !== '') {
                $part = mb_strcut($remaining, 0, 8192, 'UTF-8');
                $this->append($task, $lease, $round, $invocationId, $dispatchId, $part, $references, true);
                $remaining = substr($remaining, strlen($part));
            }
        });
    }

    private function dispatch(TaskRun $task, int $invocationId, int $dispatchId, int $round, string $state): array
    {
        if ($round < 1 || $round > $this->maximumRound($task)) self::invalid();
        $calls = Db::connect('pgsql')->query("SELECT i.id,i.request_fingerprint,i.model_id,i.deployment_id,i.request_id,
                d.parent_task_run_id,d.parent_task_step_id,d.workflow_node_code,d.attempt_no,
                d.logical_request_id,d.logical_execution_id,d.execution_snapshot_sha256
            FROM sand_ai_invocation i JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
            JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=d.parent_task_run_id
            JOIN sand_ai_model_deployment md ON md.id=i.deployment_id AND md.model_id=i.model_id AND md.provider_id=d.provider_id
            WHERE i.id=? AND d.id=? AND d.parent_task_run_id=? AND d.execution_scope='agent' AND d.capability_code='chat'
              AND d.workflow_node_code=? AND s.step_code=d.workflow_node_code
              AND i.environment_id=? AND i.organization_id=? AND i.application_id=? AND i.workload_client_id=?
              AND i.model_id=d.model_id AND i.deployment_id=d.deployment_id AND i.status=1 AND i.delete_time IS NULL"
              . match ($state) {
                  'active' => " AND d.state='dispatching' AND i.state='running'",
                  'completed' => " AND d.state='succeeded' AND i.state='succeeded'",
                  default => '',
              },
            [$invocationId, $dispatchId, (int) $task->id, 'agent_round_' . $round,
                (int) $task->environment_id, (int) $task->organization_id,
                (int) $task->application_id, (int) $task->workload_client_id]);
        if (count($calls) !== 1) self::invalid();
        AgentGateway::assertModelDispatchIdentity($task, $calls[0]);
        return $calls[0];
    }

    private function maximumRound(TaskRun $task): int
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input)) self::invalid();
        $hasBindings = array_key_exists('_agent_readonly_tools', $input);
        $definition = $input['_agent_definition'] ?? null;
        $hasDefinitionBindings = is_array($definition) && array_key_exists('readonly_tool_bindings', $definition);
        if (!$hasBindings) {
            if (array_key_exists('_agent_readonly_protocol', $input) || $hasDefinitionBindings) self::invalid();
            return 3;
        }
        $bindings = $input['_agent_readonly_tools'];
        if (!is_array($bindings) || !is_int($input['max_output_tokens'] ?? null)
            || $input['max_output_tokens'] < 1 || $input['max_output_tokens'] > 512) self::invalid();
        AgentConversationGateway::assertReadonlyProtocol($input['_agent_readonly_protocol'] ?? null);
        $digest = AgentConversationGateway::readonlyBindingsDigest($bindings);
        if ($definition !== null) {
            if (!$hasDefinitionBindings || !is_array($definition['readonly_tool_bindings'])
                || !hash_equals($digest, AgentConversationGateway::readonlyBindingsDigest($definition['readonly_tool_bindings']))) self::invalid();
        }
        return 4;
    }

    private function context(TaskRun $task, array $call, int $eventNo): array
    {
        return ['environment_id' => (int) $task->environment_id, 'invocation_id' => (int) $call['id'],
            'request_fingerprint' => (string) $call['request_fingerprint'], 'capability_code' => 'agent_token_event:' . $eventNo,
            'model_id' => (int) $call['model_id'], 'deployment_id' => (int) $call['deployment_id'], 'version' => OutcomeReceiptCipher::RECEIPT_VERSION];
    }

    private function cipher(): OutcomeReceiptCipher
    {
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Agent stream encryption is unavailable');
        return new OutcomeReceiptCipher($config);
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent stream event binding is invalid');
    }
}
