<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Audio\AudioTranscriptSealLedger;
use app\Application\Audio\MeetingOutputCorrection;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\FileParseAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\WorkflowRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use think\facade\Db;

/** Explicit current-caller intent; never renews a queued context in a worker. */
final class TaskAuthorizationContinuation
{
    public const MODE = 'authorization_continue';
    public const VERSION = 'task_authorization_continuation@1';

    public static function authorizationFailure(string $code): bool
    {
        return in_array($code, [
            'SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'SAND_AI_CREDENTIAL_REVOKED',
            'SAND_AI_AUTHENTICATION_FAILED', 'SAND_AI_SERVICE_ACTION_FORBIDDEN',
            'SAND_AI_INVOCATION_SCOPE_FORBIDDEN', 'SAND_AI_SERVICE_NETWORK_FORBIDDEN',
            'SAND_AI_DATA_CLASS_FORBIDDEN', 'SAND_AI_SERVICE_GRANT_CONSTRAINT_INVALID',
        ], true);
    }

    /** Parent-step intent is separate from frozen business input/dispatch facts. */
    public static function claimSql(string $alias = 'sand_ai_task_run'): string
    {
        if (!in_array($alias, ['sand_ai_task_run', 't'], true)) {
            throw new \InvalidArgumentException('Invalid task alias');
        }
        return "NOT EXISTS (SELECT 1 FROM sand_ai_task_step authorization_step WHERE authorization_step.task_id={$alias}.id
            AND authorization_step.sequence_no=1 AND (
                authorization_step.context->'authorization_continuation'->>'required'='true'
                OR ({$alias}.input->>'_meeting_durable'='true'
                    AND pg_catalog.jsonb_exists(authorization_step.context->'authorization_continuation', 'meeting_task_attempt')
                    AND authorization_step.context->'authorization_continuation'->>'meeting_retired' IS DISTINCT FROM 'true'
                    AND authorization_step.context->'authorization_continuation'->>'meeting_claim_pending' IS DISTINCT FROM 'true')))";
    }

    public static function durableMeeting(TaskRun $task): bool
    {
        $input = self::input($task);
        return (string)$task->task_type === 'ai_completion' && ($input['_meeting_durable'] ?? false) === true
            && is_array($input['_meeting_summary'] ?? null);
    }

    /** A formally accepted same-progress intent owns a lease, not another failure allowance. */
    public static function meetingClaimSql(): string
    {
        return "task_type='ai_completion' AND input->>'_meeting_durable'='true'
            AND state IN ('queued','retrying','recovery_pending')
            AND lease_owner IS NULL AND lease_token IS NULL AND lease_expire_time IS NULL
            AND EXISTS (SELECT 1 FROM sand_ai_task_step parent
                JOIN sand_ai_audit_log intent ON intent.resource_type='task' AND intent.resource_id=parent.task_id
                    AND intent.action='task.authorization_continued'
                    AND intent.context->>'continuation_key'=parent.context->'authorization_continuation'->>'intent_key'
                    AND intent.context->>'original_task_request_id'=sand_ai_task_run.request_id
                    AND intent.context->'current_authorization_ids'=parent.context->'authorization_continuation'->'meeting_authorization_ids'
                WHERE parent.task_id=sand_ai_task_run.id AND parent.sequence_no=1 AND parent.step_code='invoke_model'
                    AND parent.state IN ('queued','retrying','failed') AND parent.status=1 AND parent.delete_time IS NULL
                    AND parent.attempt_count=sand_ai_task_run.attempt_count
                    AND parent.context->'authorization_continuation'->>'version'='task_authorization_continuation@1'
                    AND parent.context->'authorization_continuation'->'required'='false'::jsonb
                    AND parent.context->'authorization_continuation'->'meeting_claim_pending'='true'::jsonb
                    AND parent.context->'authorization_continuation'->>'meeting_retired' IS DISTINCT FROM 'true'
                    AND parent.context->'authorization_continuation'->'meeting_task_attempt'=to_jsonb(sand_ai_task_run.attempt_count)
                    AND parent.context->'authorization_continuation'->>'meeting_request_id'=sand_ai_task_run.request_id
                    AND parent.context->'authorization_continuation'->>'meeting_scope'=sand_ai_task_run.input->>'_trusted_identity_scope')";
    }

    /** Only immutable checkpoint heads and the first unfinished node; no transcript or provider bytes. */
    public static function meetingProgress(TaskRun $task): string
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT p.anchor_sha256 AS plan_anchor,p.event_sequence AS plan_sequence,p.last_event_sha256 AS plan_head,
                c.anchor_sha256 AS worker_anchor,c.event_sequence AS worker_sequence,c.last_event_sha256 AS worker_head,
                pending.node_id,pending.state AS node_state,pending.attempt_count AS node_attempt
             FROM sand_ai_task_run t
             LEFT JOIN sand_ai_meeting_plan_checkpoint p ON p.task_id=t.id
             LEFT JOIN sand_ai_meeting_worker_checkpoint c ON c.task_id=t.id
             LEFT JOIN LATERAL (SELECT n.id AS node_id,s.state,s.attempt_count FROM sand_ai_task_step s
                JOIN sand_ai_meeting_summary_node n ON n.task_step_id=s.id AND n.task_id=s.task_id
                WHERE s.task_id=t.id AND s.sequence_no>1 AND s.state<>'succeeded'
                ORDER BY s.sequence_no LIMIT 1) pending ON true WHERE t.id=?", [(int)$task->id]);
        if (count($rows) !== 1) self::invalid();
        return RequestPayloadFingerprint::fingerprint($rows[0], []);
    }

    /** @return array<string,mixed> */
    public static function acceptMeeting(TaskRun $task, array $context, array $replacement, string $key, string $scope): array
    {
        if (!self::durableMeeting($task)) self::invalid();
        $context['authorization_continuation'] = [
            'version'=>self::VERSION, 'required'=>false, 'intent_key'=>$key,
            'meeting_task_attempt'=>(int)$task->attempt_count, 'meeting_request_id'=>(string)$task->request_id,
            'meeting_scope'=>$scope, 'meeting_authorization_ids'=>self::authorizationIds($replacement),
            'meeting_claim_pending'=>true, 'meeting_progress'=>self::meetingProgress($task),
        ];
        return $context;
    }

    /** Called under the task lock, with the consumed permission committed with the lease.
     * @return array<string,mixed>
     */
    public static function claimMeeting(TaskRun $task): array
    {
        $parent = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)
            ->where('step_code', 'invoke_model')->lock(true)->find();
        if ($parent === null || Db::connect('pgsql')->query(
            'SELECT id FROM sand_ai_task_run WHERE id=? AND (' . self::meetingClaimSql() . ')', [(int)$task->id]) === []) self::invalid();
        $context = self::context($parent);
        $marker = $context['authorization_continuation'];
        if (!is_string($marker['meeting_progress'] ?? null)
            || !hash_equals($marker['meeting_progress'], self::meetingProgress($task))) self::invalid();
        $input = self::input($task);
        (new TaskInputAccessGuard())->assert($task, $marker['meeting_scope']);
        (new self())->replace($task, [
            'task_authorization'=>$input['iam_authorization'], 'audio_authorization'=>$input['_meeting_summary']['audio_authorization'],
        ]);
        AiTaskGateway::assertCurrentSourceFacts($task, AiTaskGateway::attributionFromPersistedInput($input, (int)$task->environment_id));
        // An accepted correction may have paused before its first node claim.
        // Its paid intent remains the same; authorization continuation owns this task attempt.
        $entries = $context[MeetingOutputCorrection::CONTEXT_KEY] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) self::invalid();
        foreach ($entries as $entry) {
            if (!is_array($entry)) self::invalid();
            if (isset($entry['claim_task_attempt'])) continue;
            if (Db::connect('pgsql')->query('SELECT id FROM sand_ai_task_run WHERE id=? AND ('
                . MeetingOutputCorrection::claimSql() . ')', [(int)$task->id]) === []) continue;
            $correction = MeetingOutputCorrection::claimContext($task);
            $context = $correction['context'];
            foreach ($context[MeetingOutputCorrection::CONTEXT_KEY] as $index => $claimed) {
                if (!isset($entries[$index]['claim_task_attempt']) && isset($claimed['claim_task_attempt'])) {
                    $context[MeetingOutputCorrection::CONTEXT_KEY][$index]['claim_task_attempt'] = (int)$task->attempt_count;
                }
            }
            break;
        }
        $marker['meeting_claim_pending'] = false;
        $marker['meeting_claim_fence'] = (int)$task->lease_fence + 1;
        $context['authorization_continuation'] = $marker;
        return $context;
    }

    /** This check cannot grant a new lease; it identifies the already consumed lease owner. */
    public static function ownsMeetingLease(TaskRun $task, TaskExecutionLease $lease): bool
    {
        if (!self::durableMeeting($task) || (int)$task->id !== $lease->taskId || (int)$task->lease_fence !== $lease->fence
            || (string)$task->lease_owner !== $lease->owner || (string)$task->lease_token !== $lease->token) return false;
        $parent = TaskStep::where('task_id', (int)$task->id)->where('sequence_no', 1)->where('step_code', 'invoke_model')->find();
        $marker = $parent === null ? null : (self::context($parent)['authorization_continuation'] ?? null);
        return is_array($marker) && ($marker['version'] ?? null) === self::VERSION
            && ($marker['required'] ?? null) === false && $parent !== null && (string)$parent->state === TaskState::RUNNING
            && ($marker['meeting_retired'] ?? false) !== true
            && ($marker['meeting_task_attempt'] ?? null) === (int)$task->attempt_count
            && ($marker['meeting_request_id'] ?? null) === (string)$task->request_id
            && ($marker['meeting_scope'] ?? null) === (self::input($task)['_trusted_identity_scope'] ?? null)
            && ($marker['meeting_claim_pending'] ?? null) === false
            && ($marker['meeting_claim_fence'] ?? null) === $lease->fence;
    }

    /** Only a committed successful stage may renew the same intent's next-stage permission. */
    public static function yieldMeeting(TaskRun $task, TaskExecutionLease $lease, TaskStep $parent): array
    {
        if (!self::ownsMeetingLease($task, $lease)) self::invalid();
        $context = self::context($parent);
        $marker = $context['authorization_continuation'];
        $progress = self::meetingProgress($task);
        if (!is_string($marker['meeting_progress'] ?? null) || hash_equals($marker['meeting_progress'], $progress)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting continuation did not commit a new checkpoint or completed node');
        }
        $marker['meeting_claim_pending'] = true;
        $marker['meeting_progress'] = $progress;
        unset($marker['meeting_claim_fence']);
        $context['authorization_continuation'] = $marker;
        return $context;
    }

    public function supported(TaskRun $task): bool
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || isset($input['_channel'])) return false;
        return in_array((string) $task->task_type, ['structured_workflow', 'audio_transcription', 'file_parse'], true)
            || ((string) $task->task_type === 'ai_completion' && is_array($input['_meeting_summary'] ?? null));
    }

    /** @return null|array{required:bool,available:bool,mode:string,reason:?string} */
    public function present(TaskRun $task): ?array
    {
        if (!$this->supported($task)) return null;
        $step = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        $context = $step === null ? [] : self::context($step);
        $marker = $context['authorization_continuation'] ?? null;
        $input = self::input($task);
        $authorization = $input['iam_authorization'] ?? null;
        $audioAuthorization = $input['_meeting_summary']['audio_authorization'] ?? null;
        $expired = (is_array($authorization) && is_int($authorization['expires_at'] ?? null) && $authorization['expires_at'] <= time())
            || (is_array($audioAuthorization) && is_int($audioAuthorization['expires_at'] ?? null) && $audioAuthorization['expires_at'] <= time());
        $paused = in_array((string) $task->state, [TaskState::QUEUED, TaskState::RETRYING, TaskState::RUNNING, TaskState::FAILED, TaskState::RECOVERY_PENDING], true);
        $required = $paused && ((is_array($marker) && ($marker['version'] ?? null) === self::VERSION && ($marker['required'] ?? false) === true)
            || self::authorizationFailure((string) $task->error_code)
            || ($expired && (string) $task->state !== TaskState::FAILED));
        $reason = null;
        if ($task->content_purged_time !== null || (int) $task->status !== 1 || $task->delete_time !== null || $step === null) {
            $reason = 'source_unavailable';
        } elseif (self::leaseActive($task)) {
            $reason = 'lease_active';
        } elseif ((string) $task->state === TaskState::RUNNING) {
            $reason = 'lease_active';
        } elseif (!$required) {
            $reason = 'authorization_required';
        }
        return ['required' => $required, 'available' => $required && $reason === null, 'mode' => self::MODE, 'reason' => $reason];
    }

    /** Every lifecycle timestamp uses the one trusted host clock, regardless of state. */
    public static function leaseTimezone(TaskRun $task): string
    {
        return TaskLeasePolicy::runningTimezone();
    }

    private static function leaseActive(TaskRun $task): bool
    {
        if ($task->lease_expire_time === null) return false;
        try {
            $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $task->lease_expire_time,
                new \DateTimeZone(self::leaseTimezone($task)));
            return $expiry === false || $expiry->getTimestamp() > time();
        } catch (\Throwable) { return true; }
    }

    /** Validate fresh decisions against original task/resource before saving them.
     * @param array<string,mixed> $fresh @return array<string,mixed>
     */
    public function replace(TaskRun $task, array $fresh): array
    {
        $input = self::input($task);
        if (!$this->supported($task) || !is_array($input['iam_authorization'] ?? null)) self::invalid();
        if ((string) $task->task_type === 'structured_workflow') {
            $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)
                ->where('environment_id', (int) $task->environment_id)->where('status', 1)->whereNull('delete_time')->find();
            if ($run === null || $run->content_purged_time !== null) self::invalid();
            (new WorkflowRunAccessGuard())->revalidateQueued($fresh, $run);
            $input['iam_authorization'] = $fresh;
        } elseif ((string) $task->task_type === 'audio_transcription') {
            (new AudioTaskAccessGuard())->revalidateQueued($fresh, $task);
            $input['iam_authorization'] = $fresh;
        } elseif ((string) $task->task_type === 'file_parse') {
            (new FileParseAccessGuard())->revalidateQueued($fresh, $task);
            $input['iam_authorization'] = $fresh;
        } else {
            if (!is_array($fresh['task_authorization'] ?? null) || !is_array($fresh['audio_authorization'] ?? null)) self::invalid();
            (new TaskRunAccessGuard())->revalidateQueued($fresh['task_authorization'], $task);
            $meeting = $input['_meeting_summary'];
            $identity = UsageAttribution::fromCurrentPersisted($input['_usage_attribution'] ?? null,
                (int) $task->environment_id, 'capability', 'sand_ai.audio.transcription');
            $source = (new MeetingSummarySource())->current((int) $task->environment_id, $meeting['audio_task_id'],
                $identity, $meeting['sha256'], $meeting['review_revision'] ?? 0,
                AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal'] ?? null),
                is_array($meeting['aggregation_source'] ?? null) ? $meeting['aggregation_source'] : null);
            if ($source['file_id'] !== $meeting['file_id'] || $source['data_class'] !== $meeting['data_class']
                || $source['data_egress_policy'] !== $meeting['data_egress_policy']) self::invalid();
            (new AudioTaskAccessGuard())->revalidateQueued($fresh['audio_authorization'], $source['task']);
            $input['iam_authorization'] = $fresh['task_authorization'];
            $input['_meeting_summary']['audio_authorization'] = $fresh['audio_authorization'];
        }
        unset($input['_receipt_only_review']);
        return $input;
    }

    /** Missing task projections may be restored only from the original workflow caller.
     * @return array<string,int>
     */
    public function workflowIdentityValues(TaskRun $task, string $currentScope): array
    {
        if ((string) $task->task_type !== 'structured_workflow') return [];
        $run = WorkflowRun::where('id', (int) $task->resource_id)->where('task_id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)->where('status', 1)->whereNull('delete_time')->find();
        $input = self::input($task);
        if ($run === null || $run->content_purged_time !== null
            || (string) $task->resource_type !== 'workflow_run'
            || (int) ($input['workflow_run_id'] ?? 0) !== (int) $run->id
            || !hash_equals((string) $run->snapshot_sha256, (string) ($input['snapshot_sha256'] ?? ''))
            || !hash_equals((string) $run->execution_snapshot_sha256, (string) ($input['execution_snapshot_sha256'] ?? ''))) {
            self::invalid();
        }
        $snapshot = is_object($run->attribution_snapshot) ? (array) $run->attribution_snapshot : $run->attribution_snapshot;
        try {
            $identity = UsageAttribution::fromCurrentPersisted(is_array($snapshot) ? $snapshot : null,
                (int) $run->environment_id, 'structured_workflow', 'sand_ai.workflow.run');
        } catch (\InvalidArgumentException) {
            self::invalid();
        }
        if (!hash_equals(RequestIdempotencyKey::trustedScopeForAttribution($identity), $currentScope)) self::invalid();
        $values = [];
        foreach (['organization_id' => $identity->organizationId, 'application_id' => $identity->applicationId,
            'workload_client_id' => $identity->workloadClientId] as $field => $expected) {
            if ($expected === null || (int) $run->{$field} !== $expected
                || ($task->{$field} !== null && (int) $task->{$field} !== $expected)) self::invalid();
            if ($task->{$field} === null) $values[$field] = $expected;
        }
        return $values;
    }

    /** Nonsecret immutable audit pointers; old encrypted envelopes are never exported. */
    public static function authorizationIds(array $input): array
    {
        $result = [];
        foreach (['task' => $input['iam_authorization'] ?? null, 'audio' => $input['_meeting_summary']['audio_authorization'] ?? null] as $kind => $value) {
            if (is_array($value) && is_int($value['authorization_id'] ?? null) && $value['authorization_id'] > 0) $result[$kind] = $value['authorization_id'];
        }
        return $result;
    }

    public static function context(TaskStep $step): array
    {
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        return is_array($context) ? $context : [];
    }

    public static function input(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input)) self::invalid();
        return $input;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Original task authorization facts are unavailable');
    }
}
