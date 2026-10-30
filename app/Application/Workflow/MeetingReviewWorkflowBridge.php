<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use plugin\sandadmin\app\model\system\SystemUser;
use plugin\sandworkflow\app\contract\ActorRef;
use plugin\sandworkflow\app\contract\WorkflowStartContext;
use plugin\sandworkflow\app\service\WorkflowDefinitionService;
use think\facade\Db;

/**
 * Explicit business handoff: a named SandAdmin administrator submits a SandAI
 * meeting summary that the output contract marked review_required for human
 * approval in the installed SandWorkflow plugin.
 *
 * SandAI stays a consumer. It publishes its own approval blueprint through the
 * public definition contract, starts the instance through the public runtime
 * contract with a verified initiator, participant and business scope, and keeps
 * only a reference row. Approval state itself is owned by SandWorkflow and is
 * always read back from it; this bridge never mirrors a decision, never
 * approves, and never reacts to review_required on its own.
 */
final class MeetingReviewWorkflowBridge
{
    private const SUBMIT_FIELDS = ['environment_id', 'task_id', 'reviewer_admin_id', 'definition_id', 'group_id'];
    private const READ_FIELDS = ['environment_id', 'task_id'];
    private const CLAIM_FIELDS = ['summary', 'key_points', 'decisions', 'action_items', 'unresolved'];
    private const CLAIM_LABELS = [
        'summary' => '纪要', 'key_points' => '要点', 'decisions' => '决议',
        'action_items' => '待办', 'unresolved' => '存疑',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function submit(array $input, int $adminId): array
    {
        $input = $this->mutationInput($input);
        $owner = (new HostSandIamEnvironmentFacts())->resolve($input['environment_id']);
        $task = $this->reviewRequiredMeetingTask($owner, $input['task_id']);
        $initiator = $this->administrator($adminId, '当前管理员');
        $reviewer = $this->administrator($input['reviewer_admin_id'], '复核人');
        $requestKey = $this->requestKey($input['task_id'], $task['audio_review_revision']);

        $existing = $this->reference($owner['environment_id'], $input['task_id'], $task['audio_review_revision']);
        if ($existing !== null) {
            // The original key finds the original approval instance back; a
            // repeated submit never starts a second one.
            return $this->projection($existing, true, $initiator);
        }

        $reference = Db::connect('pgsql')->transaction(function () use (
            $owner, $input, $task, $initiator, $reviewer, $requestKey
        ): array {
            // One blueprint publication per environment, even under concurrency.
            Db::connect('pgsql')->query(
                'SELECT pg_advisory_xact_lock(hashtext(?), ?)',
                ['sand_ai_meeting_review_workflow', $owner['environment_id']],
            );
            $concurrent = $this->reference($owner['environment_id'], $input['task_id'], $task['audio_review_revision']);
            if ($concurrent !== null) {
                return $concurrent;
            }
            $blueprintSha = MeetingReviewWorkflowDefinition::sha256();
            $definitionId = $this->definitionId($owner['environment_id'], $blueprintSha, $input);
            $participants = [
                MeetingReviewWorkflowDefinition::REVIEWER_PARTICIPANT_KEY => $reviewer->toArray(),
            ];
            $context = new WorkflowStartContext(
                MeetingReviewWorkflowDefinition::TENANT_ID,
                MeetingReviewWorkflowDefinition::OWNER_SCOPE,
                MeetingReviewWorkflowDefinition::BUSINESS_TYPE,
                (string) $input['task_id'],
                $initiator,
                $participants,
                $requestKey,
            );
            $instance = (new SandWorkflowConsumer())->start(
                $definitionId,
                $this->instanceName($input['task_id'], $task),
                $this->formValue($owner, $input['task_id'], $task),
                $context,
            );
            $instanceId = trim((string) ($instance['id'] ?? ''));
            if ($instanceId === '') {
                throw new ApiProblem('SAND_AI_MEETING_REVIEW_HANDOFF_FAILED', 'SandWorkflow 未返回审批实例标识');
            }
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_meeting_review_workflow (organization_id,application_id,environment_id,'
                . 'task_run_id,audio_review_revision,request_key,workflow_definition_id,workflow_instance_id,'
                . 'definition_sha256,tenant_id,owner_scope,business_type,business_id,initiator_account_space,'
                . 'initiator_account_id,participants) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $owner['organization_id'], $owner['application_id'], $owner['environment_id'],
                    $input['task_id'], $task['audio_review_revision'], $requestKey, $definitionId, $instanceId,
                    $blueprintSha, MeetingReviewWorkflowDefinition::TENANT_ID,
                    MeetingReviewWorkflowDefinition::OWNER_SCOPE, MeetingReviewWorkflowDefinition::BUSINESS_TYPE,
                    (string) $input['task_id'], $initiator->accountSpace, $initiator->accountId,
                    json_encode($participants, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            );
            $stored = $this->reference($owner['environment_id'], $input['task_id'], $task['audio_review_revision']);
            if ($stored === null) {
                throw new ApiProblem('SAND_AI_MEETING_REVIEW_HANDOFF_FAILED', '会议复核审批引用未能持久化');
            }
            return $stored;
        });

        return $this->projection($reference, false, $initiator);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function read(array $query, int $adminId): array
    {
        $query = $this->readInput($query);
        $owner = (new HostSandIamEnvironmentFacts())->resolve($query['environment_id']);
        $reference = $this->reference($owner['environment_id'], $query['task_id'], null);
        if ($reference === null) {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_NOT_STARTED',
                '该会议摘要任务尚未提交人工复核审批',
            );
        }
        return $this->projection($reference, true, $this->administrator($adminId, '当前管理员'));
    }

    /**
     * Only a succeeded meeting summary whose own output contract asked for human
     * review may be handed off. Anything else is refused here, so no approval
     * instance can appear for a task that did not require review.
     * @param array{organization_id:int,application_id:int,environment_id:int} $owner
     * @return array<string,mixed>
     */
    private function reviewRequiredMeetingTask(array $owner, int $taskId): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id,state,task_type,content_purged_time,'
            . "input#>>'{_meeting_summary,audio_task_id}' AS audio_task_id,"
            . "input#>>'{_meeting_summary,review_revision}' AS audio_review_revision,"
            // jsonb_exists keeps the ? operator out of the prepared statement.
            . "jsonb_exists(input,'_meeting_summary') AS meeting,"
            . "result->>'review_required' AS review_required,"
            . "result->'meeting_summary' AS meeting_summary "
            . 'FROM sand_ai_task_run WHERE id=? AND environment_id=? AND delete_time IS NULL',
            [$taskId, $owner['environment_id']],
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new ApiProblem('SAND_AI_MEETING_REVIEW_TASK_NOT_FOUND', '当前环境没有该会议摘要任务');
        }
        if ((string) $row['task_type'] !== 'ai_completion'
            || !$this->truth($row['meeting'])
            || (string) $row['state'] !== 'succeeded') {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_NOT_REQUIRED',
                '只有已成功的会议摘要任务才能提交人工复核',
            );
        }
        if ($row['content_purged_time'] !== null) {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_CONTENT_PURGED',
                '该会议摘要成果已按保留策略清理，不能再发起人工复核',
            );
        }
        if ((string) $row['review_required'] !== 'true') {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_NOT_REQUIRED',
                '该会议摘要未标记需要人工复核，不创建审批实例',
            );
        }
        $summary = $this->summary($row['meeting_summary']);
        $revision = $row['audio_review_revision'];
        if (!is_string($revision) || !ctype_digit($revision)) {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_NOT_REQUIRED',
                '该会议摘要缺少冻结的转写复核版本，不能提交人工复核',
            );
        }
        return [
            'audio_task_id' => is_string($row['audio_task_id']) && ctype_digit($row['audio_task_id'])
                ? (int) $row['audio_task_id'] : null,
            'audio_review_revision' => (int) $revision,
            'summary' => $summary,
        ];
    }

    /** @return array<string,mixed> */
    private function summary(mixed $raw): array
    {
        $summary = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($summary) || !is_array($summary['schema'] ?? null)) {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_NOT_REQUIRED',
                '该任务没有可复核的会议摘要成果',
            );
        }
        return $summary;
    }

    /**
     * @param array{organization_id:int,application_id:int,environment_id:int} $owner
     * @param array<string,mixed> $task
     * @return array<string,string>
     */
    private function formValue(array $owner, int $taskId, array $task): array
    {
        $summary = $task['summary'];
        $schema = is_array($summary['schema'] ?? null) ? $summary['schema'] : [];
        $coverage = is_array($summary['coverage'] ?? null) ? $summary['coverage'] : [];
        $claims = [];
        foreach (self::CLAIM_FIELDS as $field) {
            $claims[] = self::CLAIM_LABELS[$field] . ' '
                . (is_array($summary[$field] ?? null) ? count($summary[$field]) : 0) . ' 条';
        }
        // References only. The reviewer reads the summary text, citations and
        // source blocks through the existing SandAI task API, which keeps the
        // private storage, identity and cost rules in force.
        return [
            'sand_ai_meeting_task' => (string) $taskId,
            'sand_ai_audio_task' => $task['audio_task_id'] === null ? '未记录' : (string) $task['audio_task_id'],
            'sand_ai_audio_review_revision' => (string) $task['audio_review_revision'],
            'sand_ai_summary_contract' => trim((string) ($schema['code'] ?? '')) . '@'
                . trim((string) ($schema['version'] ?? '')),
            'sand_ai_summary_claims' => implode('｜', $claims),
            'sand_ai_summary_coverage' => ($coverage['complete'] ?? null) === true ? '完整覆盖' : '覆盖未完整',
            'sand_ai_owner' => 'organization ' . $owner['organization_id']
                . ' / application ' . $owner['application_id']
                . ' / environment ' . $owner['environment_id'],
            'sand_ai_result_entry' => 'GET /api/sand-ai/v1/tasks/' . $taskId,
        ];
    }

    /** @param array<string,mixed> $task */
    private function instanceName(int $taskId, array $task): string
    {
        return MeetingReviewWorkflowDefinition::NAME . '：任务 ' . $taskId
            . '（转写复核版本 ' . $task['audio_review_revision'] . '）';
    }

    private function requestKey(int $taskId, int $revision): string
    {
        return 'sand_ai_meeting_review_' . $taskId . '_r' . $revision;
    }

    /**
     * An explicit definition wins; otherwise the environment reuses the
     * definition published from the current blueprint, and only publishes when
     * none exists yet.
     * @param array<string,mixed> $input
     */
    private function definitionId(int $environmentId, string $blueprintSha, array $input): int
    {
        if ($input['definition_id'] !== null) {
            return $input['definition_id'];
        }
        $rows = Db::connect('pgsql')->query(
            'SELECT workflow_definition_id FROM sand_ai_meeting_review_workflow '
            . 'WHERE environment_id=? AND definition_sha256=? ORDER BY id LIMIT 1',
            [$environmentId, $blueprintSha],
        );
        if (isset($rows[0]['workflow_definition_id'])) {
            return (int) $rows[0]['workflow_definition_id'];
        }
        if (!class_exists(WorkflowDefinitionService::class)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', '未安装可选的 SandWorkflow 插件');
        }
        $definitionId = (int) (new WorkflowDefinitionService())
            ->publish(MeetingReviewWorkflowDefinition::blueprint($input['group_id']));
        if ($definitionId < 1) {
            throw new ApiProblem('SAND_AI_MEETING_REVIEW_HANDOFF_FAILED', 'SandWorkflow 未返回流程定义标识');
        }
        return $definitionId;
    }

    /** @return array<string,mixed>|null */
    private function reference(int $environmentId, int $taskId, ?int $revision): ?array
    {
        $sql = 'SELECT * FROM sand_ai_meeting_review_workflow WHERE environment_id=? AND task_run_id=?';
        $bind = [$environmentId, $taskId];
        if ($revision !== null) {
            $sql .= ' AND audio_review_revision=?';
            $bind[] = $revision;
        }
        $rows = Db::connect('pgsql')->query($sql . ' ORDER BY audio_review_revision DESC, id DESC LIMIT 1', $bind);
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /**
     * Identity and business scope travel with the instance and are always read
     * back from SandWorkflow under the full stored scope.
     * @param array<string,mixed> $reference
     * @return array<string,mixed>
     */
    private function projection(array $reference, bool $replayed, ActorRef $reader): array
    {
        $scope = [
            'tenant_id' => (string) $reference['tenant_id'],
            'owner_scope' => (string) $reference['owner_scope'],
            'business_type' => (string) $reference['business_type'],
            'business_id' => (string) $reference['business_id'],
        ];
        $detail = (new SandWorkflowConsumer())
            ->detail((string) $reference['workflow_instance_id'], $reader, $scope);
        $participants = json_decode((string) $reference['participants'], true);
        return [
            'environment_id' => (int) $reference['environment_id'],
            'task_run_id' => (int) $reference['task_run_id'],
            'audio_review_revision' => (int) $reference['audio_review_revision'],
            'request_key' => (string) $reference['request_key'],
            'replayed' => $replayed,
            'workflow' => [
                'definition_id' => (int) $reference['workflow_definition_id'],
                'definition_sha256' => (string) $reference['definition_sha256'],
                'instance_id' => (string) $reference['workflow_instance_id'],
            ] + $scope,
            'initiator' => [
                'account_space' => (string) $reference['initiator_account_space'],
                'account_id' => (string) $reference['initiator_account_id'],
            ],
            'participants' => is_array($participants) ? $this->participantView($participants) : [],
            'instance_state' => $this->instanceState($detail),
        ];
    }

    /**
     * @param array<string,mixed> $participants
     * @return array<string,array<string,string>>
     */
    private function participantView(array $participants): array
    {
        $view = [];
        foreach ($participants as $key => $participant) {
            if (!is_array($participant)) {
                continue;
            }
            $snapshot = is_array($participant['snapshot'] ?? null) ? $participant['snapshot'] : [];
            $view[(string) $key] = [
                'account_space' => (string) ($participant['account_space'] ?? ''),
                'account_id' => (string) ($participant['account_id'] ?? ''),
                'name' => (string) ($snapshot['name'] ?? ''),
            ];
        }
        return $view;
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private function instanceState(array $detail): array
    {
        $pending = [];
        foreach (is_array($detail['tasks'] ?? null) ? $detail['tasks'] : [] as $task) {
            if (!is_array($task) || (int) ($task['task_status'] ?? -1) !== 0) {
                continue;
            }
            $assignees = [];
            foreach (is_array($task['assignees'] ?? null) ? $task['assignees'] : [] as $assignee) {
                if (is_array($assignee)) {
                    $assignees[] = [
                        'account_space' => (string) ($assignee['account_space'] ?? ''),
                        'account_id' => (string) ($assignee['account_id'] ?? ''),
                    ];
                }
            }
            $pending[] = [
                'task_id' => (string) ($task['id'] ?? ''),
                'node_id' => (string) ($task['node_id'] ?? ''),
                'node_name' => (string) ($task['node_name'] ?? ''),
                'assignees' => $assignees,
            ];
        }
        return [
            'flow_status' => (int) ($detail['flow_status'] ?? 0),
            'act_node_id' => (string) ($detail['act_node_id'] ?? ''),
            'ended_time' => $detail['ended_time'] === null ? null : (string) $detail['ended_time'],
            'pending_tasks' => $pending,
        ];
    }

    /** A workflow actor is always a real, active SandAdmin person, never a SandAI workload. */
    private function administrator(int $adminId, string $role): ActorRef
    {
        if ($adminId < 1) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', $role . '必须是有效的后台用户');
        }
        if (!class_exists(SystemUser::class)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_UNSUPPORTED', '宿主未提供后台用户上下文');
        }
        $user = SystemUser::where('id', $adminId)->where('status', 1)->find();
        if ($user === null) {
            throw new ApiProblem(
                'SAND_AI_MEETING_REVIEW_ACTOR_UNAVAILABLE',
                $role . '不存在或已停用，不能参与审批',
            );
        }
        $name = trim((string) ($user->realname ?? '')) !== ''
            ? (string) $user->realname
            : (string) ($user->username ?? $adminId);
        return new ActorRef('sandadmin', (string) $adminId, ['name' => $name]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{environment_id:int,task_id:int,reviewer_admin_id:int,definition_id:int|null,group_id:int|null}
     */
    private function mutationInput(array $input): array
    {
        $this->assertFields($input, self::SUBMIT_FIELDS);
        return [
            'environment_id' => $this->positive($input['environment_id'] ?? null, 'environment_id'),
            'task_id' => $this->positive($input['task_id'] ?? null, 'task_id'),
            'reviewer_admin_id' => $this->positive($input['reviewer_admin_id'] ?? null, 'reviewer_admin_id'),
            'definition_id' => $this->optionalPositive($input['definition_id'] ?? null, 'definition_id'),
            'group_id' => $this->optionalPositive($input['group_id'] ?? null, 'group_id'),
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @return array{environment_id:int,task_id:int}
     */
    private function readInput(array $query): array
    {
        $this->assertFields($query, self::READ_FIELDS);
        return [
            'environment_id' => $this->positive($query['environment_id'] ?? null, 'environment_id'),
            'task_id' => $this->positive($query['task_id'] ?? null, 'task_id'),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $allowed
     */
    private function assertFields(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw new ApiProblem(
                'SAND_AI_SCHEMA_UNSUPPORTED',
                '不支持的请求字段：' . implode(',', $unknown),
            );
        }
    }

    private function positive(mixed $value, string $field): int
    {
        $parsed = $this->optionalPositive($value, $field);
        if ($parsed === null) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', $field . ' 必须是正整数');
        }
        return $parsed;
    }

    private function optionalPositive(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', $field . ' 必须是正整数');
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($parsed)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', $field . ' 必须是正整数');
        }
        return $parsed;
    }

    private function truth(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
