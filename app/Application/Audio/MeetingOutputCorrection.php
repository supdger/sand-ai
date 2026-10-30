<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Application\Task\AiTaskGateway;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** Explicit, paid correction of one rejected node; receipt recovery never generates a correction. */
final class MeetingOutputCorrection
{
    public const MODE = 'meeting_output_correction';
    public const CONTEXT_KEY = 'meeting_output_corrections';

    /** A previously accepted intent can execute once, with one fenced crash recovery.
     * The original task failure/recovery limits are not reset or enlarged.
     */
    public static function claimSql(bool $expiredRunning = false): string
    {
        $states = $expiredRunning ? "'running'" : "'queued','retrying','recovery_pending'";
        $parentStates = $expiredRunning ? "'running'" : "'queued','retrying','failed'";
        $zone = \app\Application\Task\TaskLeasePolicy::runningTimezone();
        return "task_type='ai_completion' AND input->>'_meeting_durable'='true'
            AND state IN ({$states})
            AND (lease_expire_time IS NULL OR lease_expire_time <= (clock_timestamp() AT TIME ZONE '{$zone}'))
            AND EXISTS (
                SELECT 1 FROM sand_ai_task_step parent
                CROSS JOIN LATERAL jsonb_array_elements(CASE
                    WHEN jsonb_typeof(parent.context->'meeting_output_corrections')='array'
                    THEN parent.context->'meeting_output_corrections' ELSE '[]'::jsonb END) AS entries(marker)
                JOIN sand_ai_meeting_summary_node node ON marker->'node_id'=to_jsonb(node.id)
                    AND node.task_id=parent.task_id AND marker->>'node_code'=node.node_code
                JOIN sand_ai_task_step step ON step.id=node.task_step_id AND step.task_id=node.task_id
                JOIN sand_ai_invocation_dispatch old_dispatch ON old_dispatch.parent_task_run_id=node.task_id
                    AND old_dispatch.parent_task_step_id=step.id AND old_dispatch.workflow_node_code=node.node_code
                    AND marker->'previous_attempt'=to_jsonb(old_dispatch.attempt_no)
                    AND marker->'previous_invocation_id'=to_jsonb(old_dispatch.invocation_id)
                JOIN sand_ai_invocation_outcome_receipt receipt ON receipt.invocation_id=old_dispatch.invocation_id
                    AND receipt.environment_id=sand_ai_task_run.environment_id AND receipt.capability_code='meeting_summary'
                WHERE parent.task_id=sand_ai_task_run.id AND parent.sequence_no=1 AND parent.step_code='invoke_model'
                    AND parent.state IN ({$parentStates}) AND parent.status=1 AND parent.delete_time IS NULL
                    AND marker->'version'='1'::jsonb AND marker->>'node_input_sha256'=node.input_sha256
                    AND marker->'next_attempt'=to_jsonb(old_dispatch.attempt_no+1)
                    AND old_dispatch.state='succeeded' AND receipt.state='available'
                    AND receipt.expire_time>(clock_timestamp() AT TIME ZONE 'UTC')
                    AND node.result_sha256 IS NULL AND node.result_snapshot IS NULL
                    AND NOT EXISTS (SELECT 1 FROM sand_ai_task_step earlier WHERE earlier.task_id=step.task_id
                        AND earlier.sequence_no>1 AND earlier.sequence_no<step.sequence_no AND earlier.state<>'succeeded')
                    AND (
                        (NOT (pg_catalog.jsonb_exists(marker, 'claim_task_attempt')) AND step.state='failed'
                            AND step.error_code='SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'
                            AND marker->'previous_attempt'=to_jsonb(step.attempt_count)
                            AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch later
                                WHERE later.parent_task_run_id=node.task_id AND later.parent_task_step_id=step.id
                                    AND later.attempt_no>step.attempt_count))
                        OR (marker->'claim_task_attempt'=to_jsonb(sand_ai_task_run.attempt_count)
                            AND marker->'claim_recovery_count'='0'::jsonb
                            AND step.state IN ('failed','running')
                            AND (marker->'previous_attempt'=to_jsonb(step.attempt_count)
                                OR marker->'next_attempt'=to_jsonb(step.attempt_count))
                            AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch later
                                WHERE later.parent_task_run_id=node.task_id AND later.parent_task_step_id=step.id
                                    AND to_jsonb(later.attempt_no)>marker->'next_attempt'))
                    )
            )";
    }

    /** Called under the task row lock; the returned parent context commits with its lease.
     * @return array{context:array<string,mixed>,resume:bool}
     */
    public static function claimContext(TaskRun $task): array
    {
        $parent = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)
            ->where('step_code', 'invoke_model')->lock(true)->find();
        $context = $parent === null ? null : self::array($parent->context);
        $entries = $context[self::CONTEXT_KEY] ?? null;
        if (!is_array($context) || !is_array($entries) || !array_is_list($entries) || count($entries)>256) self::conflict();
        $pending = Db::connect('pgsql')->query(
            "SELECT node.id,node.task_step_id FROM sand_ai_meeting_summary_node node JOIN sand_ai_task_step step
             ON step.id=node.task_step_id AND step.task_id=node.task_id
             WHERE node.task_id=? AND step.state<>'succeeded' ORDER BY step.sequence_no LIMIT 1", [(int)$task->id]);
        if (count($pending) !== 1) self::conflict();
        $node = MeetingSummaryNode::find((int)$pending[0]['id']);
        $step = TaskStep::find((int)$pending[0]['task_step_id']);
        if ($node === null || $step === null) self::conflict();
        $index = null;
        foreach ($entries as $entryIndex => $entry) {
            if (!is_array($entry)) self::conflict();
            if (($entry['node_id'] ?? null) !== (int)$node->id) continue;
            if (($entry['next_attempt'] ?? null) !== (int)$step->attempt_count + 1
                && !(isset($entry['claim_task_attempt']) && ($entry['next_attempt'] ?? null) === (int)$step->attempt_count)) continue;
            if ($index !== null) self::conflict();
            $index = $entryIndex;
        }
        if ($index === null) self::conflict();
        $marker = self::marker($task, $node, $entries[$index]['next_attempt']);
        if ($marker === null) self::conflict();
        $resume = isset($marker['claim_task_attempt']);
        if ($resume && (($marker['claim_task_attempt'] ?? null) !== (int)$task->attempt_count
            || ($marker['claim_recovery_count'] ?? null) !== 0)) self::conflict();
        $verified = self::evidence($task, $node, $step,
            (int)$step->attempt_count === $marker['previous_attempt'] ? null : $marker['previous_attempt']);
        foreach ($verified as $field => $value) {
            if (($marker[$field] ?? null) !== $value) self::conflict();
        }
        $later = InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->where('parent_task_step_id', (int)$step->id)->where('attempt_no', '>', $marker['previous_attempt'])->select();
        if (!$resume && count($later) !== 0) self::conflict();
        if ($resume && (count($later) > 1 || (count($later) === 1
            && (int)$later[0]->attempt_no !== $marker['next_attempt']))) self::conflict();
        $input = self::array($task->input);
        if (!is_array($input['iam_authorization'] ?? null)) self::conflict();
        (new \app\Identity\Infrastructure\TaskRunAccessGuard())->revalidateQueued($input['iam_authorization'], $task);
        AiTaskGateway::assertCurrentSourceFacts($task, AiTaskGateway::attributionFromPersistedInput($input, (int)$task->environment_id));
        $entries[$index]['claim_task_attempt'] = $resume ? (int)$task->attempt_count : (int)$task->attempt_count + 1;
        $entries[$index]['claim_recovery_count'] = $resume ? 1 : 0;
        $context[self::CONTEXT_KEY] = $entries;
        return ['context'=>$context, 'resume'=>$resume];
    }

    /** A completed rejected attempt is history, not the dispatch of an accepted next attempt. */
    public static function startsNextAttempt(TaskRun $task, MeetingSummaryNode $node): bool
    {
        $step = TaskStep::where('id', (int)$node->task_step_id)->where('task_id', (int)$task->id)->find();
        if ($step === null) return false;
        $attempt = (int)$step->attempt_count;
        $alreadyClaimed = (string)$step->state === 'running';
        $marker = self::marker($task, $node, $alreadyClaimed ? $attempt : $attempt + 1);
        if ($marker === null) return false;
        if ($alreadyClaimed && !isset($marker['claim_task_attempt'])) self::conflict();
        if (!$alreadyClaimed && (string)$step->state !== 'failed') return false;
        if ($alreadyClaimed && InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->where('parent_task_step_id', (int)$step->id)->where('attempt_no', $attempt)->find() !== null) return false;
        $verified = self::evidence($task, $node, $step, $alreadyClaimed ? $marker['previous_attempt'] : null);
        foreach ($verified as $field => $value) {
            if (($marker[$field] ?? null) !== $value) self::conflict();
        }
        if (InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->where('parent_task_step_id', (int)$step->id)
            ->where('attempt_no', '>', $marker['previous_attempt'])->find() !== null) self::conflict();
        return true;
    }

    /** Safe current capability projection; rejection details never include transcript or model text.
     * @return array<string,bool|int|string>
     */
    public static function availability(TaskRun $task): array
    {
        $unavailable = ['available'=>false, 'reason'=>'not_available'];
        if ((string)$task->state !== 'failed' || (string)$task->error_code !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'
            || (int)$task->attempt_count >= (int)$task->max_attempts || $task->content_purged_time !== null) return $unavailable;
        $pending = Db::connect('pgsql')->query(
            "SELECT n.id,n.task_step_id FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step s ON s.id=n.task_step_id
             WHERE n.task_id=? AND s.state<>'succeeded' ORDER BY s.sequence_no LIMIT 1", [(int)$task->id]);
        if (count($pending) !== 1) return $unavailable;
        $node = MeetingSummaryNode::find((int)$pending[0]['id']);
        $step = TaskStep::find((int)$pending[0]['task_step_id']);
        if ($node === null || $step === null) return $unavailable;
        try {
            $verified = self::evidence($task, $node, $step);
        } catch (ApiProblem) {
            return $unavailable;
        }
        return [
            'available'=>true, 'reason'=>$verified['reason'] ?? 'duplicate_sources',
            'reason_path'=>$verified['reason_path'], 'node_code'=>(string)$node->node_code,
            'node_kind'=>(string)$node->node_kind, 'expected_node_attempt'=>(int)$step->attempt_count,
            'expected_attempt_count'=>(int)$task->attempt_count, 'failure_code'=>'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID',
        ];
    }

    /** Read the completed original outcome, retaining its bytes and accounting unchanged.
     * @return array<string,int|string>
     */
    public static function evidence(TaskRun $task, MeetingSummaryNode $node, TaskStep $step, ?int $retainedAttempt = null): array
    {
        $input = self::array($task->input);
        $nodeInput = self::array($node->input_snapshot);
        if (!MeetingExecutionRuntime::forTask($task) || $input === null || $nodeInput === null
            || $node->result_sha256 !== null || $node->result_snapshot !== null
            || (int)$node->task_id !== (int)$task->id || (int)$node->task_step_id !== (int)$step->id
            || (int)$step->task_id !== (int)$task->id
            || ($retainedAttempt === null && ((string)$step->state !== 'failed'
                || (string)$step->error_code !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID'))
            || ($retainedAttempt !== null && ((int)$step->attempt_count !== $retainedAttempt + 1
                || !in_array((string)$step->state, ['running','failed'], true)))
            || ($retainedAttempt ?? (int)$step->attempt_count) < 1 || ($retainedAttempt ?? (int)$step->attempt_count) >= 3
            || !hash_equals((string)$node->input_sha256, RequestPayloadFingerprint::fingerprint($nodeInput, []))) {
            self::conflict();
        }
        $contract = MeetingSummaryOutputContract::forNode($input, (string)$node->node_kind, $nodeInput);
        if ($contract === null) self::conflict();
        $attempt = $retainedAttempt ?? (int)$step->attempt_count;
        $dispatches = InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->where('parent_task_step_id', (int)$step->id)->where('workflow_node_code', (string)$node->node_code)
            ->where('attempt_no', $attempt)->select();
        if (count($dispatches) !== 1 || (string)$dispatches[0]->state !== 'succeeded') self::conflict();
        $dispatch = $dispatches[0];
        if ((string)$dispatch->execution_scope !== 'ai_task' || (string)$dispatch->capability_code !== 'chat'
            || (int)$dispatch->environment_id !== (int)$task->environment_id) self::conflict();
        if ($retainedAttempt === null && InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->whereIn('state', ['dispatching','ambiguous'])->find() !== null) self::conflict();
        $invocation = Invocation::where('id', (int)$dispatch->invocation_id)->where('environment_id', (int)$task->environment_id)->find();
        $requestId = sprintf('task_%d_%s_attempt_%d', (int)$task->id, (string)$node->node_code, $attempt);
        if ($invocation === null || (string)$invocation->state !== 'succeeded'
            || (string)$invocation->request_id !== $requestId
            || !hash_equals((string)$dispatch->logical_request_id, hash('sha256', $requestId))
            || (int)$invocation->model_id !== (int)$dispatch->model_id
            || (int)$invocation->deployment_id !== (int)$dispatch->deployment_id) self::conflict();
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) self::conflict();
        $receipt = new OutcomeReceiptService(new PostgresOutcomeReceiptStore(),
            new OutcomeReceiptCipher($config), $config['ttl_seconds'] ?? 0);
        $outcome = KnowledgeProviderOutcome::decode($receipt->replay([
            'environment_id'=>(int)$task->environment_id, 'invocation_id'=>(int)$invocation->id,
            'request_fingerprint'=>(string)$invocation->request_fingerprint, 'capability_code'=>'meeting_summary',
            'model_id'=>(int)$invocation->model_id, 'deployment_id'=>(int)$invocation->deployment_id,
            'version'=>OutcomeReceiptCipher::RECEIPT_VERSION,
        ]));
        $owner = AiTaskGateway::attributionFromPersistedInput($input, (int)$task->environment_id);
        if (!RequestPayloadFingerprint::same($outcome->attribution, $owner->toPersisted() + [
            'source_kind'=>$owner->sourceKind, 'service_action'=>$owner->serviceAction,
        ], []) || ($outcome->provider['id'] ?? null) !== (int)$dispatch->provider_id
            || ($outcome->model['id'] ?? null) !== (int)$invocation->model_id
            || ($outcome->deployment['id'] ?? null) !== (int)$invocation->deployment_id) self::conflict();
        $usage = Db::connect('pgsql')->query(
            'SELECT input_tokens,output_tokens,total_tokens FROM sand_ai_usage_event WHERE invocation_id=?', [(int)$invocation->id]);
        if (count($usage) !== 1 || (int)$usage[0]['input_tokens'] !== $outcome->inputTokens
            || (int)$usage[0]['output_tokens'] !== $outcome->outputTokens
            || (int)$usage[0]['total_tokens'] !== $outcome->inputTokens + $outcome->outputTokens) self::conflict();
        // Never correct an unknown, malformed JSON or exhausted output by guessing its finish state.
        $document = $contract->jsonDocument($outcome->content);
        $evidence = new MeetingSummaryEvidence();
        $missingPath = self::missingSourcePath($document, $contract->claimLimits, $nodeInput['segment_ids']);
        $claims = $missingPath === null ? $evidence->decodeObjectClaims($document, $contract->claimLimits) : [];
        $reason = $missingPath;
        foreach ($claims as $field => $items) {
            foreach ($items as $index => $claim) {
                foreach ($claim['segment_ids'] as $id) {
                    if (!is_string($id) || !in_array($id, $nodeInput['segment_ids'], true)) self::conflict();
                }
                if (count(array_unique($claim['segment_ids'])) !== count($claim['segment_ids'])) {
                    $reason ??= $field . '[' . $index . '].segment_ids';
                }
            }
        }
        // Only these positively identified citation failures can request a new paid attempt.
        // The retained document is never repaired, published or used as a successful child.
        if ($reason === null) self::conflict();
        return [
            'node_id'=>(int)$node->id, 'node_code'=>(string)$node->node_code,
            'previous_attempt'=>$attempt, 'next_attempt'=>$attempt + 1,
            'previous_invocation_id'=>(int)$invocation->id, 'content_sha256'=>hash('sha256', $outcome->content),
            'node_input_sha256'=>(string)$node->input_sha256, 'reason_path'=>$reason,
        ] + ($missingPath === null ? [] : ['reason'=>'missing_sources']);
    }

    /** Diagnose absence only; present citations and every other field must satisfy the frozen contract.
     * This does not produce a corrected document or accept a result.
     * @param array<string,int> $limits @param list<string> $sourceIds
     */
    private static function missingSourcePath(string $document, array $limits, array $sourceIds): ?string
    {
        try {
            $decoded = json_decode($document, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$decoded instanceof \stdClass) return null;
        $fields = ['summary', 'key_points', 'decisions', 'action_items', 'unresolved'];
        if (count(get_object_vars($decoded)) !== count($fields)
            || array_diff(array_keys(get_object_vars($decoded)), $fields) !== []) return null;
        $missing = null;
        foreach ($fields as $field) {
            $items = $decoded->{$field};
            $limit = $limits[$field] ?? null;
            if (!is_int($limit) || $limit < 0 || $limit > 2 * MeetingSummaryEvidence::MAX_MERGE_CHILD_CHARS
                || !is_array($items) || count($items) > $limit || ($field === 'summary' && $items === [])) return null;
            foreach ($items as $index => $claim) {
                if (!$claim instanceof \stdClass) return null;
                $keys = array_keys(get_object_vars($claim));
                if (!isset($claim->text) || !is_string($claim->text) || trim($claim->text) === ''
                    || mb_strlen($claim->text, 'UTF-8') > MeetingSummaryEvidence::MAX_CLAIM_CHARS) return null;
                if ($keys === ['text']) {
                    $missing ??= $field . '[' . $index . '].segment_ids';
                    continue;
                }
                if (count($keys) !== 2 || array_diff($keys, ['text', 'segment_ids']) !== []
                    || !is_array($claim->segment_ids) || $claim->segment_ids === []
                    || count($claim->segment_ids) > MeetingSummaryEvidence::MAX_CLAIM_SOURCES) return null;
                $seen = [];
                foreach ($claim->segment_ids as $id) {
                    if (!is_string($id) || !in_array($id, $sourceIds, true) || isset($seen[$id])) return null;
                    $seen[$id] = true;
                }
            }
        }
        return $missing;
    }

    /** @return array<string,mixed>|null */
    public static function marker(TaskRun $task, MeetingSummaryNode $node, int $attempt): ?array
    {
        $parent = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)
            ->where('step_code', 'invoke_model')->where('status', 1)->whereNull('delete_time')->find();
        if ($parent === null) self::conflict();
        $context = self::array($parent->context) ?? [];
        $entries = $context[self::CONTEXT_KEY] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 256) self::conflict();
        $found = null;
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['version'] ?? null) !== 1
                || !is_string($entry['request_key'] ?? null)
                || preg_match('/\Ameeting_correction:[a-f0-9]{64}\z/D', $entry['request_key']) !== 1
                || !is_int($entry['node_id'] ?? null) || !is_int($entry['next_attempt'] ?? null)) self::conflict();
            if ($entry['node_id'] !== (int)$node->id || $entry['next_attempt'] !== $attempt) continue;
            if ($found !== null || ($entry['node_code'] ?? null) !== (string)$node->node_code
                || ($entry['previous_attempt'] ?? null) !== $attempt - 1
                || ($entry['node_input_sha256'] ?? null) !== (string)$node->input_sha256
                || !is_int($entry['previous_invocation_id'] ?? null) || $entry['previous_invocation_id'] < 1
                || !is_string($entry['content_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['content_sha256']) !== 1) self::conflict();
            $found = $entry;
        }
        return $found;
    }

    /** Derived wire input only. The original node input, old receipt and source partition remain sealed. */
    public static function input(array $nodeInput, array $marker): array
    {
        // evidence() derives this canonical index from claims checked against the frozen node limit.
        if (!is_string($marker['reason_path'] ?? null)
            || preg_match('/\A(summary|key_points|decisions|action_items|unresolved)\[(0|[1-9][0-9]{0,4})\]\.segment_ids\z/D', $marker['reason_path'], $matches) !== 1
            || (int) $matches[2] >= 2 * MeetingSummaryEvidence::MAX_MERGE_CHILD_CHARS) self::conflict();
        if (isset($marker['reason']) && $marker['reason'] !== 'missing_sources') self::conflict();
        if (($marker['reason'] ?? null) === 'missing_sources') {
            $nodeInput['messages'][0]['content'] .= ' 上一次本节点输出的 ' . $marker['reason_path']
                . ' 缺少必填来源引用，已被拒绝且未发布。这是明确的新纠正尝试。'
                . '重新依据下面同一批原始来源生成完整五数组摘要，每项恰含 text 和 segment_ids；'
                . '每项至少引用一个实际支持观点的有效来源编号，编号不得重复。不得编造引用或省略已有观点来规避检查；'
                . '无法从原来源核实的观点不得当作事实输出。输出前逐项核对全部字段和来源。';
            return $nodeInput;
        }
        $nodeInput['messages'][0]['content'] .= ' 上一次本节点输出的 ' . $marker['reason_path']
            . ' 含重复来源编号，已被拒绝且未发布。这是明确的新纠正尝试。'
            . '重新依据下面同一批原始来源生成完整五数组摘要，每项 segment_ids 必须只含互不重复的有效编号；'
            . '不要新增来源、不要修改原事实，也不要省略类别。输出前检查所有项的引用唯一性。';
        return $nodeInput;
    }

    private static function array(mixed $value): ?array
    {
        $value = is_object($value) ? (array)$value : $value;
        return is_array($value) ? $value : null;
    }

    private static function conflict(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Only a completed retained meeting node with a verified supported citation rejection can be explicitly corrected');
    }
}
