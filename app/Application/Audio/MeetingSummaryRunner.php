<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\OutcomeReceiptRequest;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\AiTaskGateway;
use app\Application\Task\TaskState;
use app\Application\Task\TaskGateway;
use app\Application\Task\MeetingSummaryResultGuard;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use think\facade\Db;

/** A bounded meeting pipeline. Each persisted node is one fenced TaskStep and one model call. */
final class MeetingSummaryRunner
{
    private const MAX_RESULT_CHARS = 10000;
    private const PAGE_NODES = 32;

    public function __construct(private readonly ?ChatGateway $chat = null)
    {
    }

    /** One selected durable node. No leaf directory or whole-round scan.
     * @param array<string,array<string,mixed>> $sources
     * @param array<string,mixed> $meeting @param array<string,mixed> $input
     */
    public function executePersistedNode(TaskRun $task, TaskExecutionLease $lease, string $code, array $sources,
        array $meeting, array $input, UsageAttribution $attribution, \Closure $authorize, bool $recover = false): array
    {
        $node = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $code)->find();
        if ($node === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议持久节点不存在');
        $authorize(0);
        (new TaskGateway())->heartbeat($lease);
        $this->executeNode($task, $lease, $node, $sources, $meeting, $input, $attribution, $authorize, $recover);
        $authorize(0);
        (new TaskGateway())->heartbeat($lease);
        return ['phase' => 'node_sealed', 'node_code' => $code];
    }

    /** Reuse the existing deterministic pair algorithm in one materialization.
     * @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting
     */
    public function materializePersistedPair(TaskRun $task, TaskExecutionLease $lease, string $leftCode,
        string $rightCode, string $code, int $level, int $sequence, array $sources, array $meeting): array
    {
        return (new PostgresMeetingWorkerLedger())->runStage($task, $lease,
            function (TaskRun $current) use ($lease, $leftCode, $rightCode, $code, $level, $sequence, $sources, $meeting): array {
                $specs = [];
                foreach ([$leftCode, $rightCode] as $childCode) {
                    $child = MeetingSummaryNode::where('task_id', (int) $current->id)->where('node_code', $childCode)->find();
                    if ($child === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议合并来源节点不存在');
                    $specs[] = ['node_code' => $childCode, 'level_no' => (int) $child->level_no,
                        'segment_start_index' => (int) $child->segment_start_index, 'segment_end_index' => (int) $child->segment_end_index];
                }
                $node = $this->ensurePair($current, $lease, $specs[0], $specs[1], $code, $level, $sequence, $sources, $meeting);
                return ['node_id' => (int) $node->id, 'node_code' => (string) $node->node_code, 'phase' => 'merge_materialized'];
            });
    }

    /** Used by page verification and final publication; checks one sealed node.
     * @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting
     * @return array<string,mixed>
     */
    public function verifyPersistedNode(TaskRun $task, string $code, array $sources, array $meeting): array
    {
        $node = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $code)->find();
        $step = $node === null ? null : TaskStep::where('id', (int) $node->task_step_id)->where('task_id', (int) $task->id)->find();
        if ($node === null || $step === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议节点封存不可用');
        return $this->verifiedResult($node, $step, $sources, $meeting);
    }

    /**
     * Asynchronous planning. Successful exact-message counts survive a crash in
     * the append-only ledger; unknown requests stop here rather than resend.
     * No generation step exists until all original-text offsets are sealed.
     *
     * @param array<string,mixed> $transcript @param array<string,mixed> $meeting
     */
    public function prepare(TaskRun $task, TaskExecutionLease $lease, array $transcript, array $meeting, \Closure $authorize): array
    {
        $input = self::array($task->input);
        $snapshot = $input['_meeting_capacity'] ?? null;
        if (!is_array($snapshot) || ($meeting['planner_version'] ?? null) !== MeetingSummaryPlan::CAPACITY_VERSION) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '会议模型容量尚未冻结，未发送分词或摘要调用');
        }
        MeetingSummaryRoute::assertSame($snapshot['route'], $input['_meeting_route'] ?? null);
        $capacity = new MeetingSummaryCapacity($snapshot, static function () use ($lease, $authorize): void {
            $authorize(0);
            (new TaskGateway())->heartbeat($lease);
        });
        $capacity->assertOutputAllowance(is_int($input['max_output_tokens'] ?? null) ? $input['max_output_tokens'] : 4096);
        if (MeetingLocalPlanning::enabled($snapshot)) MeetingLocalPlanning::assertNoCounterRequests($task);
        else $capacity->bindLedger(new PostgresMeetingTokenCountLedger($task, $lease, $snapshot));
        $plan = (new MeetingSummaryPlan())->withCapacity($transcript, $capacity);
        $authorize(0);
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $input, $plan, $snapshot): void {
            $this->assertLease($lease);
            $current = TaskRun::where('id', (int) $task->id)->lock(true)->find();
            $persisted = self::array($current?->input);
            if ($current === null || $persisted === null
                || !RequestPayloadFingerprint::same($persisted['_meeting_capacity'] ?? [], $snapshot, [])
                || !RequestPayloadFingerprint::same($persisted['_meeting_summary'] ?? [], $input['_meeting_summary'], [])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议来源或容量在规划时发生变化，未发送摘要调用');
            }
            if (isset($persisted['_meeting_plan'])
                && !RequestPayloadFingerprint::same($persisted['_meeting_plan'], $plan['manifest'], [])) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE', '会议冻结规划在封存前发生变化');
            }
            $persisted['_meeting_plan'] = $plan['manifest'];
            $current->save(['input' => $persisted]);
            $task->input = $persisted;
        });
        return ['messages' => $plan['leaves'][0]['messages'], 'sources' => $plan['sources'], 'plan' => $plan];
    }

    /** @param array<string,mixed> $prepared @param array<string,mixed> $meeting @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(TaskRun $task, TaskExecutionLease $lease, array $prepared, array $meeting, array $input, UsageAttribution $attribution, \Closure $authorize): array
    {
        $plan = $prepared['plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['leaves'] ?? null) || !is_array($plan['sources'] ?? null)) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The meeting plan is invalid');
        }
        if (TaskLeasePolicy::seconds() < 180) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_CONFIG_INVALID', 'Meeting summary requires a lease longer than one provider call');
        }
        (new TaskGateway())->heartbeat($lease);
        $this->ensureLeaves($task, $lease, $plan, $meeting);
        $frontier = [];
        $page = [];
        foreach ($plan['leaves'] as $leaf) {
            $frontier[] = [
                'node_code' => $leaf['node_code'], 'level_no' => 0,
                'segment_start_index' => $leaf['segment_start_index'],
                'segment_end_index' => $leaf['segment_end_index'],
            ];
            $page[] = $leaf['node_code'];
            if (count($page) === self::PAGE_NODES) {
                $this->executePage($task, $lease, $page, $plan['sources'], $meeting, $input, $attribution, $authorize);
                $page = [];
            }
        }
        if ($page !== []) $this->executePage($task, $lease, $page, $plan['sources'], $meeting, $input, $attribution, $authorize);
        // Reconstruct the same complete-round topology from the frozen leaves.
        // A partially persisted round resumes its existing node codes, rather
        // than regrouping the currently visible mixed-level frontier.
        while (count($frontier) > 1) {
            $next = [];
            for ($index = 0; $index + 1 < count($frontier); $index += 2) {
                (new TaskGateway())->heartbeat($lease);
                $level = max($frontier[$index]['level_no'], $frontier[$index + 1]['level_no']) + 1;
                $sequence = intdiv($index, 2) + 1;
                $code = sprintf('merge_%02d_%05d', $level, $sequence);
                $node = $this->ensurePair($task, $lease, $frontier[$index], $frontier[$index + 1], $code, $level, $sequence, $plan['sources'], $meeting);
                $this->executeNode($task, $lease, $node, $plan['sources'], $meeting, $input, $attribution, $authorize);
                $next[] = [
                    'node_code' => $code, 'level_no' => $level,
                    'segment_start_index' => $frontier[$index]['segment_start_index'],
                    'segment_end_index' => $frontier[$index + 1]['segment_end_index'],
                ];
            }
            if (count($frontier) % 2 !== 0) $next[] = $frontier[count($frontier) - 1];
            $frontier = $next;
        }
        $root = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $frontier[0]['node_code'])->find();
        if ($root === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting summary has no root');
        return $this->finish($task, $lease, $root, $plan, $meeting);
    }

    /** Receipt-only recovery. The existing invocation must be present before ChatGateway is called. */
    public function recoverOutcome(TaskRun $task, TaskExecutionLease $lease): void
    {
        $this->recoveryHeartbeat($lease);
        $persisted = self::array($task->input);
        $meeting = is_array($persisted) ? ($persisted['_meeting_summary'] ?? null) : null;
        if (!is_array($meeting) || !is_array($persisted['iam_authorization'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting recovery source is unavailable');
        }
        $attribution = AiTaskGateway::attributionFromPersistedInput($persisted, (int) $task->environment_id);
        $audioIdentity = UsageAttribution::fromCurrentPersisted(
            $persisted['_usage_attribution'], (int) $task->environment_id,
            'capability', 'sand_ai.audio.transcription',
        );
        (new TaskRunAccessGuard())->revalidateQueued($persisted['iam_authorization'], $task);
        AiTaskGateway::assertCurrentSourceFacts($task, $attribution);
        $source = (new MeetingSummarySource())->load(
            (int) $task->environment_id, (int) $meeting['audio_task_id'], $audioIdentity,
            (string) $meeting['sha256'], (int) $meeting['review_revision'],
            false, $persisted['_meeting_plan'] ?? null, $persisted['_meeting_capacity'] ?? null,
        );
        if ((int) $source['file_id'] !== (int) $meeting['file_id']) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'Meeting recovery source changed');
        }
        (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $source['task']);
        $plan = $source['prepared']['plan'] ?? null;
        if (!is_array($plan)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting recovery plan is missing');
        }
        $pending = [];
        foreach ($this->nodePages((int) $task->id) as $nodes) {
            $this->recoveryHeartbeat($lease);
            foreach ($nodes as $node) {
                $this->recoveryHeartbeat($lease);
                $step = TaskStep::where('id', (int) $node->task_step_id)->where('task_id', (int) $task->id)->find();
                if ($step !== null && (string) $step->state === TaskState::SUCCEEDED) {
                    $this->verifiedResult($node, $step, $plan['sources'], $meeting);
                    continue;
                }
                if ($node->result_snapshot !== null) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A sealed meeting recovery step changed');
                }
                if ($step === null || !in_array((string) $step->state, [TaskState::RUNNING, TaskState::FAILED], true)) continue;
                $dispatch = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('parent_task_step_id', (int) $step->id)->where('workflow_node_code', (string) $node->node_code)
                    ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])->find();
                if ($dispatch !== null) $pending[] = $node;
                if (count($pending) > 1) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting recovery has multiple unresolved nodes');
                }
            }
        }
        if ($pending === [] && !$this->sealedContinuationReady($task, $lease)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting recovery has no sealed continuation');
        }
        if ($pending !== []) {
        $authorize = static function (int $invocationId) use ($task, $persisted, $attribution, $audioIdentity, $meeting): void {
            (new TaskRunAccessGuard())->revalidateQueued($persisted['iam_authorization'], $task);
            AiTaskGateway::assertCurrentSourceFacts($task, $attribution);
            $source = (new MeetingSummarySource())->current(
                (int) $task->environment_id, (int) $meeting['audio_task_id'], $audioIdentity,
                (string) $meeting['sha256'], (int) $meeting['review_revision'],
                AudioTranscriptSealLedger::normalizeReference($meeting['transcript_seal'] ?? null),
            );
            (new AudioTaskAccessGuard())->revalidateQueued($meeting['audio_authorization'], $source['task']);
        };
        $this->executeNode($task, $lease, $pending[0], $plan['sources'], $meeting, [
            'model' => $persisted['model'], 'max_output_tokens' => $persisted['max_output_tokens'] ?? null,
        ], $attribution, $authorize, true);
        }
        Db::connect('pgsql')->transaction(function () use ($task, $lease): void {
            $this->assertRecoveryLease($lease);
            $updated = TaskRun::where('id', (int) $task->id)->where('state', TaskState::RECOVERY_PENDING)
                ->where('lease_owner', $lease->owner)->where('lease_token', $lease->token)
                ->where('lease_fence', $lease->fence)->update([
                    'state' => TaskState::QUEUED, 'available_at' => date('Y-m-d H:i:s'),
                    'max_attempts' => Db::raw('GREATEST(max_attempts, attempt_count + 1)'),
                    'locked_at' => null, 'locked_by' => null, 'lease_owner' => null,
                    'lease_token' => null, 'lease_expire_time' => null, 'heartbeat_time' => null,
                    'lease_fence' => Db::raw('lease_fence + 1'), 'version' => Db::raw('version + 1'),
                    'error_code' => null, 'error_summary' => null,
                ]);
            $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)
                ->where('state', TaskState::FAILED)->update([
                    'state' => TaskState::QUEUED, 'completed_at' => null,
                    'error_code' => null, 'error_summary' => null,
                ]);
            if ($updated !== 1 || $parent !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting recovery changed before continuation');
            }
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $meeting */
    private function ensureLeaves(TaskRun $task, TaskExecutionLease $lease, array $plan, array $meeting): void
    {
        $page = [];
        foreach ($plan['leaves'] as $index => $leaf) {
            $page[] = ['leaf' => $leaf, 'step_sequence' => 2 + $index];
            if (count($page) === self::PAGE_NODES) {
                $this->ensureLeafPage($task, $lease, $page, $meeting);
                $page = [];
            }
        }
        if ($page !== []) $this->ensureLeafPage($task, $lease, $page, $meeting);
        if ((int) MeetingSummaryNode::where('task_id', (int) $task->id)->where('level_no', 0)->count() !== count($plan['leaves'])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting chunk plan changed');
        }
    }

    /** @param list<array{leaf:array<string,mixed>,step_sequence:int}> $page @param array<string,mixed> $meeting */
    private function ensureLeafPage(TaskRun $task, TaskExecutionLease $lease, array $page, array $meeting): void
    {
        (new TaskGateway())->heartbeat($lease);
        Db::connect('pgsql')->transaction(function () use ($task, $lease, $page, $meeting): void {
            $this->assertLease($lease);
            $codes = [];
            foreach ($page as $item) $codes[] = $item['leaf']['node_code'];
            $existing = [];
            foreach (MeetingSummaryNode::where('task_id', (int) $task->id)->whereIn('node_code', $codes)->lock(true)->select() as $node) {
                $existing[(string) $node->node_code] = $node;
            }
            foreach ($page as $item) {
                $leaf = $item['leaf'];
                if (isset($existing[$leaf['node_code']])) {
                    $this->assertNode($existing[$leaf['node_code']], $leaf, $meeting);
                } else {
                    $this->createNode($task, $leaf, $item['step_sequence'], $meeting);
                }
            }
        });
        (new TaskGateway())->heartbeat($lease);
    }

    /** @param list<string> $codes @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting @param array<string,mixed> $input */
    private function executePage(TaskRun $task, TaskExecutionLease $lease, array $codes, array $sources, array $meeting, array $input, UsageAttribution $attribution, \Closure $authorize): void
    {
        (new TaskGateway())->heartbeat($lease);
        $nodes = [];
        foreach (MeetingSummaryNode::where('task_id', (int) $task->id)->whereIn('node_code', $codes)->select() as $node) {
            $nodes[(string) $node->node_code] = $node;
        }
        foreach ($codes as $code) {
            (new TaskGateway())->heartbeat($lease);
            if (!isset($nodes[$code])) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A meeting task node is missing');
            $this->executeNode($task, $lease, $nodes[$code], $sources, $meeting, $input, $attribution, $authorize);
        }
    }

    /** @return \Generator<int,iterable<MeetingSummaryNode>> */
    private function nodePages(int $taskId): \Generator
    {
        $lastId = 0;
        while (true) {
            $nodes = MeetingSummaryNode::where('task_id', $taskId)->where('id', '>', $lastId)
                ->order('id')->limit(self::PAGE_NODES)->select();
            if (count($nodes) === 0) return;
            foreach ($nodes as $node) $lastId = (int) $node->id;
            yield $nodes;
        }
    }

    /** @param array<string,mixed> $spec @param array<string,mixed> $meeting */
    private function createNode(TaskRun $task, array $spec, int $stepSequence, array $meeting): void
    {
        $step = TaskStep::create([
            'task_id' => (int) $task->id, 'step_code' => $spec['node_code'],
            'sequence_no' => $stepSequence, 'state' => TaskState::QUEUED,
            'attempt_count' => 0, 'context' => ['node_kind' => $spec['node_kind'], 'level_no' => $spec['level_no']],
            'status' => 1,
        ]);
        $input = ['messages' => $spec['messages'], 'segment_ids' => $spec['segment_ids']];
        if (isset($spec['children'])) {
            $input['children'] = $spec['children'];
        }
        $attributes = [
            'task_id' => (int) $task->id, 'task_step_id' => (int) $step->id,
            'node_code' => $spec['node_code'], 'parent_node_code' => null,
            'node_kind' => $spec['node_kind'], 'level_no' => $spec['level_no'],
            'sequence_no' => $spec['sequence_no'],
            'segment_start_index' => $spec['segment_start_index'],
            'segment_end_index' => $spec['segment_end_index'],
            'transcript_sha256' => $meeting['sha256'],
            'review_revision' => $meeting['review_revision'],
            'planner_version' => $meeting['planner_version'] ?? MeetingSummaryPlan::VERSION,
            'input_sha256' => self::hash($input), 'input_snapshot' => $input,
        ];
        if (MeetingExecutionRuntime::forTask($task)
            && Db::connect('pgsql')->query('SELECT task_id FROM sand_ai_meeting_worker_checkpoint WHERE task_id=?', [(int) $task->id]) !== []) {
            $attributes['worker_input_text'] = self::canonicalText($input);
            $attributes['worker_messages_text'] = self::canonicalText($input['messages']);
        }
        MeetingSummaryNode::create($attributes);
    }

    /** @param array<string,mixed> $spec @param array<string,mixed> $meeting */
    private function assertNode(MeetingSummaryNode $node, array $spec, array $meeting): void
    {
        $input = self::array($node->input_snapshot);
        $expected = ['messages' => $spec['messages'], 'segment_ids' => $spec['segment_ids']];
        if (isset($spec['children'])) $expected['children'] = $spec['children'];
        if ($input === null || !hash_equals((string) $node->input_sha256, self::hash($input))
            || (string) $node->node_code !== $spec['node_code']
            || (string) $node->node_kind !== $spec['node_kind']
            || (int) $node->level_no !== $spec['level_no']
            || (int) $node->sequence_no !== $spec['sequence_no']
            || (int) $node->segment_start_index !== $spec['segment_start_index']
            || (int) $node->segment_end_index !== $spec['segment_end_index']
            || (string) $node->transcript_sha256 !== $meeting['sha256']
            || (int) $node->review_revision !== $meeting['review_revision']
            || (string) $node->planner_version !== ($meeting['planner_version'] ?? MeetingSummaryPlan::VERSION)
            || !RequestPayloadFingerprint::same($input, $expected, [])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A frozen meeting node changed');
        }
    }

    /** @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting @param array<string,mixed> $input */
    private function executeNode(TaskRun $task, TaskExecutionLease $lease, MeetingSummaryNode $node, array $sources, array $meeting, array $input, UsageAttribution $attribution, \Closure $authorize, bool $recover = false): void
    {
        $step = TaskStep::where('id', (int) $node->task_step_id)->where('task_id', (int) $task->id)->find();
        if ($step === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A meeting task step is missing');
        }
        if ((string) $step->state === TaskState::SUCCEEDED) {
            $this->verifiedResult($node, $step, $sources, $meeting);
            return;
        }
        $nodeInput = self::array($node->input_snapshot);
        if ($nodeInput === null || !hash_equals((string) $node->input_sha256, self::hash($nodeInput))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node input changed');
        }
        $preparedCorrection = !$recover && InvocationDispatch::where('parent_task_run_id', (int)$task->id)
            ->where('parent_task_step_id', (int)$step->id)->where('attempt_no', (int)$step->attempt_count)
            ->where('state', 'prepared')->find() !== null;
        $correctionAttempt = $recover || $preparedCorrection || (string)$step->state === TaskState::RUNNING
            ? (int)$step->attempt_count : (int)$step->attempt_count + 1;
        $correction = MeetingOutputCorrection::marker($task, $node, $correctionAttempt);
        $claimedCorrection = !$recover && !$preparedCorrection && $correction !== null && (string)$step->state === TaskState::RUNNING
            && isset($correction['claim_task_attempt']) && $correction['next_attempt'] === (int)$step->attempt_count;
        if ($correction !== null && !$recover && !$preparedCorrection && (string)$step->state === TaskState::FAILED) {
            $verifiedCorrection = MeetingOutputCorrection::evidence($task, $node, $step);
            foreach ($verifiedCorrection as $field => $value) {
                if (($correction[$field] ?? null) !== $value) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The retained meeting correction evidence changed');
                }
            }
        }
        $wireInput = $correction === null ? $nodeInput : MeetingOutputCorrection::input($nodeInput, $correction);
        if ((string) $node->node_kind === 'merge') {
            $this->assertMergeInput($node, $sources, $meeting);
        }
        $meetingOutput = MeetingSummaryOutputContract::forNode(
            self::array($task->input) ?? [], (string) $node->node_kind, $wireInput,
        );
        if (!$recover) {
            // A missing historical capacity never authorizes another external
            // model send. Existing sent calls keep their receipt-only recovery.
            $persisted = self::array($task->input);
            $snapshot = $persisted['_meeting_capacity'] ?? null;
            if (!is_array($snapshot) || !is_array($persisted['_meeting_plan'] ?? null)
                || ($meeting['planner_version'] ?? null) !== MeetingSummaryPlan::CAPACITY_VERSION) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '此旧会议任务未冻结可核验容量，请保留已发生费用并用新请求键提交摘要；已发送节点仅恢复原回执');
            }
            $capacity = new MeetingSummaryCapacity($snapshot);
            $capacity->assertOutputAllowance(is_int($input['max_output_tokens'] ?? null) ? $input['max_output_tokens'] : 4096);
            if (!MeetingLocalPlanning::enabled($snapshot)) $capacity->bindLedger(new PostgresMeetingTokenCountLedger($task, $lease, $snapshot));
            MeetingSummaryRoute::assertSame($snapshot['route'], $persisted['_meeting_route'] ?? null);
            $authorize(0);
            (new TaskGateway())->heartbeat($lease);
            if (MeetingLocalPlanning::enabled($snapshot)) {
                MeetingLocalPlanning::assertNoCounterRequests($task);
                MeetingLocalPlanning::assertMessages($wireInput['messages']);
            } else {
                $receipt = $capacity->measure($wireInput['messages']);
                $capacity->assertFits($receipt, $wireInput['messages']);
            }
            (new TaskGateway())->heartbeat($lease);
        }
        if ($recover) {
            // Keep transaction-local trigger lease settings alive for the check.
            // Receipt replay and any Provider work run after this short transaction.
            Db::connect('pgsql')->transaction(function () use ($lease): void {
                $this->assertRecoveryLease($lease);
            });
            if (!in_array((string) $step->state, [TaskState::RUNNING, TaskState::FAILED], true)
                || (int) $step->attempt_count < 1) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting node cannot be recovered');
            }
            $attempt = (int) $step->attempt_count;
        } else {
        $attempt = Db::connect('pgsql')->transaction(function () use ($node, $lease, $correction, $preparedCorrection, $claimedCorrection): int {
            $this->assertLease($lease);
            $step = TaskStep::where('id', (int) $node->task_step_id)->lock(true)->find();
            $claimStates = [TaskState::QUEUED, TaskState::RETRYING, TaskState::FAILED];
            if ($claimedCorrection || $preparedCorrection) $claimStates[] = TaskState::RUNNING;
            if (MeetingExecutionRuntime::forTaskId($lease->taskId)
                && Db::connect('pgsql')->query('SELECT task_id FROM sand_ai_meeting_worker_checkpoint WHERE task_id=?', [$lease->taskId]) !== []) {
                $claimStates[] = TaskState::RUNNING;
            }
            if ($step === null || !in_array((string) $step->state, $claimStates, true)) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting node is not claimable');
            }
            if ((string) $step->state === TaskState::FAILED) {
                $visible = InvocationDispatch::where('parent_task_step_id', (int) $step->id)
                    ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])->find();
                if ($visible !== null && $correction === null) {
                    throw new ApiProblem('SAND_AI_DISPATCH_RETRY_FORBIDDEN', 'The meeting node requires receipt recovery or explicit correction');
                }
                if ($correction !== null) {
                    $currentMarker = MeetingOutputCorrection::marker(TaskRun::where('id', $lease->taskId)->find(), $node, (int)$step->attempt_count + ($preparedCorrection ? 0 : 1));
                    if ($currentMarker !== $correction || (int)$step->attempt_count !== ($preparedCorrection ? $correction['next_attempt'] : $correction['previous_attempt'])) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The original correction claim changed');
                    }
                    $later = InvocationDispatch::where('parent_task_run_id', $lease->taskId)
                        ->where('parent_task_step_id', (int)$step->id)->where('attempt_no', '>', (int)$step->attempt_count)->find();
                    if ($later !== null) throw new ApiProblem('SAND_AI_DISPATCH_RETRY_FORBIDDEN', 'The correction already has an invocation identity');
                }
            }
            if ($claimedCorrection || ($preparedCorrection && $correction !== null)) {
                $currentTask = TaskRun::where('id', $lease->taskId)->find();
                $currentMarker = $currentTask === null ? null : MeetingOutputCorrection::marker($currentTask, $node, (int)$step->attempt_count);
                if ($currentMarker !== $correction || (int)$step->attempt_count !== $correction['next_attempt']
                    || ($claimedCorrection && InvocationDispatch::where('parent_task_run_id', $lease->taskId)->where('parent_task_step_id', (int)$step->id)
                        ->where('attempt_no', (int)$step->attempt_count)->find() !== null)) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The claimed correction identity changed');
                }
            }
            // A prepared/no-start node already owns its invocation and budget.
            // Continue that identity; a new attempt would orphan the old reserve.
            $prepared = InvocationDispatch::where('parent_task_run_id', $lease->taskId)
                ->where('parent_task_step_id', (int) $step->id)
                ->where('workflow_node_code', (string) $node->node_code)
                ->where('state', 'prepared')->select();
            if (count($prepared) > 1 || (count($prepared) === 1
                && ($prepared[0]->dispatch_start_time !== null
                    || (int) $prepared[0]->attempt_no !== (int) $step->attempt_count
                    || (int) $step->attempt_count < 1))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting prepared identity is inconsistent');
            }
            $currentTask = TaskRun::where('id', $lease->taskId)->find();
            $authorizationClaimed = $currentTask !== null
                && \app\Application\Task\TaskAuthorizationContinuation::ownsMeetingLease($currentTask, $lease)
                && (string)$step->state === TaskState::RUNNING && (int)$step->attempt_count > 0;
            if ($authorizationClaimed && count($prepared) === 0
                && InvocationDispatch::where('parent_task_run_id', $lease->taskId)
                    ->where('parent_task_step_id', (int)$step->id)->where('attempt_no', (int)$step->attempt_count)->find() !== null) {
                throw new ApiProblem('SAND_AI_DISPATCH_RETRY_FORBIDDEN', 'The original meeting attempt requires receipt recovery');
            }
            $attempt = count($prepared) === 1 || $claimedCorrection || $authorizationClaimed
                ? (int) $step->attempt_count : (int) $step->attempt_count + 1;
            $changed = TaskStep::where('id', (int) $step->id)->where('state', (string) $step->state)
                ->where('attempt_count', (int) $step->attempt_count)->update([
                    'state' => TaskState::RUNNING, 'attempt_count' => $attempt,
                    'started_at' => date('Y-m-d H:i:s'), 'completed_at' => null,
                    'error_code' => null, 'error_summary' => null,
                ]);
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting node changed before claim');
            }
            return $attempt;
        });
        }
        if (!$recover) (new TaskGateway())->heartbeat($lease);
        $requestId = sprintf('task_%d_%s_attempt_%d', (int) $task->id, (string) $node->node_code, $attempt);
        if ($recover && Invocation::where('request_id', $requestId)->find() === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting node has no invocation to recover');
        }
        $completion = function (array $messages) use ($task, $input, $requestId, $attribution,
            $step, $node, $attempt, $lease, $authorize, $meetingOutput): array {
            return ($this->chat ?? new ChatGateway())->complete(
                (int) $task->environment_id, $input['model'], $messages, $requestId,
                $attribution, min(4096, is_int($input['max_output_tokens'] ?? null) ? $input['max_output_tokens'] : 4096), capabilityCode: 'chat',
                outcomeReceiptRequest: new OutcomeReceiptRequest(262144),
                executionScope: DispatchExecutionScope::task('ai_task', (int) $task->id,
                    (int) $step->id, (string) $node->node_code, $attempt, $lease),
                authorizePublicInvocation: $authorize,
                meetingOutput: $meetingOutput,
            );
        };
        // The existing gateway resolves the original invocation first during recovery.
        // An unknown receipt exits here; the SDK graph never receives a recovery send callback.
        $recoveredOutcome = $recover ? $completion($wireInput['messages']) : null;
        $result = (new NeuronMeetingSummaryWorkflow(new NeuronMeetingSummaryResources(
            $wireInput, $sources, $meetingOutput, (int) $node->id, (int) $step->id,
            $requestId, self::MAX_RESULT_CHARS, $recover ? null : $completion, $recoveredOutcome,
        )))->summarize();
        Db::connect('pgsql')->transaction(function () use ($node, $lease, $step, $result, $recover): void {
            if ($recover) $this->assertRecoveryLease($lease); else $this->assertLease($lease);
            $attributes = [
                'result_snapshot' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result_sha256' => self::hash($result), 'last_invocation_id' => $result['invocation_id'],
            ];
            if (MeetingExecutionRuntime::forTaskId($lease->taskId)
                && Db::connect('pgsql')->query('SELECT task_id FROM sand_ai_meeting_worker_checkpoint WHERE task_id=?', [$lease->taskId]) !== []) {
                $attributes['worker_result_text'] = self::canonicalText($result);
            }
            $updated = MeetingSummaryNode::where('id', (int) $node->id)->whereNull('result_sha256')->update($attributes);
            $stepUpdated = TaskStep::where('id', (int) $step->id)->whereIn('state', $recover ? [TaskState::RUNNING, TaskState::FAILED] : [TaskState::RUNNING])->update([
                'state' => TaskState::SUCCEEDED, 'completed_at' => date('Y-m-d H:i:s'),
            ]);
            if ($updated !== 1 || $stepUpdated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting node changed before result persistence');
            }
        });
    }

    /** @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting @return array<string,mixed> */
    private function verifiedResult(MeetingSummaryNode $node, TaskStep $step, array $sources, array $meeting): array
    {
        if ((string) $node->node_kind === 'merge') {
            $this->assertMergeInput($node, $sources, $meeting);
        }
        return $this->sealedResult($node, $step, $sources, $meeting);
    }

    /** @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting @return array<string,mixed> */
    private function sealedResult(MeetingSummaryNode $node, TaskStep $step, array $sources, array $meeting): array
    {
        $result = self::array($node->result_snapshot);
        $input = self::array($node->input_snapshot);
        if ((int) $step->task_id !== (int) $node->task_id || (int) $step->id !== (int) $node->task_step_id
            || (string) $step->step_code !== (string) $node->node_code
            || (string) $step->state !== TaskState::SUCCEEDED || (int) $step->attempt_count < 1
            || $input === null || !hash_equals((string) $node->input_sha256, self::hash($input))
            || (string) $node->transcript_sha256 !== $meeting['sha256']
            || (int) $node->review_revision !== $meeting['review_revision']
            || (string) $node->planner_version !== ($meeting['planner_version'] ?? MeetingSummaryPlan::VERSION)
            || !is_array($input['segment_ids'] ?? null) || !array_is_list($input['segment_ids'])
            || $input['segment_ids'] === [] || $result === null
            || !is_string($result['content'] ?? null)
            || !hash_equals((string) $node->result_sha256, self::hash($result))
            || !is_int($result['invocation_id'] ?? null) || $result['invocation_id'] < 1
            || (int) $node->last_invocation_id !== $result['invocation_id']
            || ($result['request_id'] ?? null) !== sprintf('task_%d_%s_attempt_%d',
                (int) $node->task_id, (string) $node->node_code, (int) $step->attempt_count)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A completed meeting node changed');
        }
        $allowed = [];
        foreach ($input['segment_ids'] as $id) {
            if (!is_string($id) || !isset($sources[$id]) || isset($allowed[$id])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A meeting node source changed');
            }
            $allowed[$id] = $sources[$id];
        }
        if ((new MeetingSummaryEvidence())->present($result['content'], $allowed,
            MeetingSummaryOutputContract::claimLimits($meeting, (string) $node->node_kind, $input)) != ($result['summary'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A meeting node citation changed');
        }
        return $result;
    }

    /** @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting */
    private function assertMergeInput(MeetingSummaryNode $node, array $sources, array $meeting): void
    {
        $input = self::array($node->input_snapshot);
        $children = $input['children'] ?? null;
        if (!is_array($children) || !array_is_list($children) || count($children) !== 2
            || !is_string($children[0]) || !is_string($children[1]) || $children[0] === $children[1]) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge children changed');
        }
        $results = [];
        $childNodes = [];
        foreach ($children as $code) {
            $child = MeetingSummaryNode::where('task_id', (int) $node->task_id)->where('node_code', $code)->find();
            $step = $child === null ? null : TaskStep::where('id', (int) $child->task_step_id)
                ->where('task_id', (int) $node->task_id)->find();
            if ($child === null || $step === null || (string) $child->parent_node_code !== (string) $node->node_code
                || (int) $child->level_no >= (int) $node->level_no) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge graph changed');
            }
            $childNodes[] = $child;
            $results[] = $this->sealedResult($child, $step, $sources, $meeting);
        }
        $expected = (new MeetingSummaryEvidence())->mergeInput($results[0]['summary'], $results[1]['summary'], $children, $meeting['output_contract_revision'] ?? 1);
        if ((int) $node->segment_start_index !== (int) $childNodes[0]->segment_start_index
            || (int) $node->segment_end_index !== (int) $childNodes[1]->segment_end_index
            || (int) $childNodes[0]->segment_end_index > (int) $childNodes[1]->segment_start_index
            || !RequestPayloadFingerprint::same($input, $expected, [])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen meeting merge input changed');
        }
    }

    /** @param array<string,mixed> $leftSpec @param array<string,mixed> $rightSpec @param array<string,array<string,mixed>> $sources @param array<string,mixed> $meeting */
    private function ensurePair(TaskRun $task, TaskExecutionLease $lease, array $leftSpec, array $rightSpec, string $code, int $level, int $sequence, array $sources, array $meeting): MeetingSummaryNode
    {
        $children = [$leftSpec['node_code'], $rightSpec['node_code']];
        $nodes = [];
        foreach (MeetingSummaryNode::where('task_id', (int) $task->id)->whereIn('node_code', $children)->select() as $child) {
            $nodes[(string) $child->node_code] = $child;
        }
        if (!isset($nodes[$children[0]], $nodes[$children[1]])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A merge child is missing');
        }
        $left = $nodes[$children[0]];
        $right = $nodes[$children[1]];
        if ((int) $left->segment_start_index !== $leftSpec['segment_start_index']
            || (int) $left->segment_end_index !== $leftSpec['segment_end_index']
            || (int) $right->segment_start_index !== $rightSpec['segment_start_index']
            || (int) $right->segment_end_index !== $rightSpec['segment_end_index']
            || (int) $left->level_no !== $leftSpec['level_no'] || (int) $right->level_no !== $rightSpec['level_no']
            || (int) $left->segment_end_index > (int) $right->segment_start_index) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge ranges changed');
        }
        $leftStep = TaskStep::where('id', (int) $left->task_step_id)->where('task_id', (int) $task->id)->find();
        $rightStep = TaskStep::where('id', (int) $right->task_step_id)->where('task_id', (int) $task->id)->find();
        if ($leftStep === null || $rightStep === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A merge child is missing');
        }
        $leftResult = $this->verifiedResult($left, $leftStep, $sources, $meeting);
        $rightResult = $this->verifiedResult($right, $rightStep, $sources, $meeting);
        $mergeInput = (new MeetingSummaryEvidence())->mergeInput($leftResult['summary'], $rightResult['summary'], $children, $meeting['output_contract_revision'] ?? 1);
        $spec = [
            'node_code' => $code, 'node_kind' => 'merge', 'level_no' => $level, 'sequence_no' => $sequence,
            'segment_start_index' => (int) $left->segment_start_index,
            'segment_end_index' => (int) $right->segment_end_index,
            'segment_ids' => $mergeInput['segment_ids'], 'children' => $mergeInput['children'], 'messages' => $mergeInput['messages'],
        ];
        // Each pair commits independently. Reuse a committed node on restart,
        // checking its exact original input before any receipt or dispatch path.
        return Db::connect('pgsql')->transaction(function () use ($task, $lease, $left, $right, $spec, $meeting): MeetingSummaryNode {
            $this->assertLease($lease);
            $parent = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $spec['node_code'])->lock(true)->find();
            $lockedLeft = MeetingSummaryNode::where('id', (int) $left->id)->lock(true)->find();
            $lockedRight = MeetingSummaryNode::where('id', (int) $right->id)->lock(true)->find();
            if ($lockedLeft === null || $lockedRight === null
                || (string) $lockedLeft->input_sha256 !== (string) $left->input_sha256
                || (string) $lockedRight->input_sha256 !== (string) $right->input_sha256
                || (string) $lockedLeft->result_sha256 !== (string) $left->result_sha256
                || (string) $lockedRight->result_sha256 !== (string) $right->result_sha256) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting frontier changed');
            }
            if ($parent !== null) {
                $this->assertNode($parent, $spec, $meeting);
                if ((string) $lockedLeft->parent_node_code !== $spec['node_code']
                    || (string) $lockedRight->parent_node_code !== $spec['node_code']) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting frontier changed');
                }
                return $parent;
            }
            if ($lockedLeft->parent_node_code !== null || $lockedRight->parent_node_code !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The meeting frontier changed');
            }
            $nextStep = (int) TaskStep::where('task_id', (int) $task->id)->max('sequence_no') + 1;
            $this->createNode($task, $spec, $nextStep, $meeting);
            MeetingSummaryNode::whereIn('id', [(int) $lockedLeft->id, (int) $lockedRight->id])
                ->update(['parent_node_code' => $spec['node_code']]);
            $created = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $spec['node_code'])->find();
            if ($created === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'A merge node is missing');
            return $created;
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $meeting @return array<string,mixed> */
    private function finish(TaskRun $task, TaskExecutionLease $lease, MeetingSummaryNode $root, array $plan, array $meeting): array
    {
        $step = TaskStep::where('id', (int) $root->task_step_id)->find();
        if ($step === null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting root step is missing');
        $result = $this->verifiedResult($root, $step, $plan['sources'], $meeting);
        $summary = $result['summary'];
        $summary['schema']['version'] = '1.1.0';
        $summary['coverage'] = [
            'total_segments' => $plan['total_segments'],
            'eligible_segments' => $plan['eligible_segments'],
            'covered_segments' => $plan['eligible_segments'],
            'uncovered_segment_ids' => [], 'complete' => true,
        ];
        $candidate = [
            'output' => ['content' => $result['content']],
            'meeting_summary' => $summary, 'meeting_summary_error' => null,
            'audio_review_revision' => $meeting['review_revision'],
            'invocation' => ['request_id' => $result['request_id']],
            'review_required' => true,
        ];
        // Every sealed leaf and merge must match the full frozen plan before
        // the parent task can persist a successful coverage claim.
        (new MeetingSummaryResultGuard())->present($task, $candidate, ['plan' => $plan],
            static function () use ($lease): void { (new TaskGateway())->heartbeat($lease); },
        );

        return $candidate;
    }

    private function sealedContinuationReady(TaskRun $task, TaskExecutionLease $lease): bool
    {
        $parent = TaskStep::where('task_id', (int) $task->id)->where('sequence_no', 1)->find();
        if ($parent === null || (string) $parent->state !== TaskState::FAILED
            || (string) $task->error_code !== 'SAND_AI_LOCAL_RECOVERY_PENDING') {
            return false;
        }
        $sealed = 0;
        foreach ($this->nodePages((int) $task->id) as $nodes) {
            $this->recoveryHeartbeat($lease);
            foreach ($nodes as $node) {
                $this->recoveryHeartbeat($lease);
                $step = TaskStep::where('id', (int) $node->task_step_id)->find();
                if ($node->result_snapshot !== null) {
                    if ($step === null || (string) $step->state !== TaskState::SUCCEEDED) return false;
                    ++$sealed;
                }
                $visible = InvocationDispatch::where('parent_task_run_id', (int) $task->id)
                    ->where('parent_task_step_id', (int) $node->task_step_id)
                    ->whereIn('state', ['dispatching', 'ambiguous', 'succeeded', 'failed'])->find();
                if ($visible !== null && $node->result_snapshot === null) return false;
            }
        }
        return $sealed > 0;
    }

    private function recoveryHeartbeat(TaskExecutionLease $lease): void
    {
        // RECOVERY_PENDING and RUNNING leases share the one trusted host clock
        // (app.default_timezone), the same basis as create_time/update_time.
        $timezone = TaskLeasePolicy::runningTimezone();
        $updated = Db::connect('pgsql')->execute(
            'WITH locked AS MATERIALIZED (SELECT id,lease_expire_time FROM sand_ai_task_run '
            . 'WHERE id=:task AND state=:state AND lease_owner=:owner AND lease_token=:token '
            . 'AND lease_fence=:fence AND status=1 AND delete_time IS NULL AND content_purged_time IS NULL FOR UPDATE) '
            . 'UPDATE sand_ai_task_run task SET heartbeat_time=(clock_timestamp() AT TIME ZONE :heartbeat_timezone), '
            . 'lease_expire_time=(clock_timestamp() AT TIME ZONE :write_timezone)+make_interval(secs => :lease_seconds),version=task.version+1 '
            . 'FROM locked WHERE task.id=locked.id AND locked.lease_expire_time>(clock_timestamp() AT TIME ZONE :check_timezone)',
            ['task' => $lease->taskId, 'state' => TaskState::RECOVERY_PENDING, 'owner' => $lease->owner,
                'token' => $lease->token, 'fence' => $lease->fence, 'lease_seconds' => TaskLeasePolicy::seconds(),
                'heartbeat_timezone' => $timezone, 'write_timezone' => $timezone, 'check_timezone' => $timezone],
        );
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting recovery lease changed');
        }
    }

    private function assertRecoveryLease(TaskExecutionLease $lease): void
    {
        // Durable tasks reclaim a RUNNING lease for exactly the old invocation.
        // Its node/step writes keep the same 109 lease boundary as new sends.
        if (MeetingExecutionRuntime::forTaskId($lease->taskId)
            && Db::connect('pgsql')->query('SELECT task_id FROM sand_ai_meeting_worker_checkpoint WHERE task_id=?', [$lease->taskId]) !== []) {
            $this->assertLease($lease);
            return;
        }
        $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
        if ($task === null || (string) $task->state !== TaskState::RECOVERY_PENDING
            || (string) $task->lease_owner !== $lease->owner
            || !hash_equals($lease->token, (string) $task->lease_token)
            || (int) $task->lease_fence !== $lease->fence
            || $task->content_purged_time !== null || (int) $task->status !== 1 || $task->delete_time !== null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting recovery lease changed');
        }
        if (TaskRun::where('id', $lease->taskId)
            ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])->value('id') === null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '会议回执恢复租约已过期，旧Worker未续期或保存节点');
        }
    }

    private function assertLease(TaskExecutionLease $lease): void
    {
        (new PostgresMeetingWorkerLedger())->bindEnrolledLease($lease);
        $task = TaskRun::where('id', $lease->taskId)->lock(true)->find();
        if ($task === null || (string) $task->state !== TaskState::RUNNING
            || (string) $task->lease_owner !== $lease->owner
            || !hash_equals($lease->token, (string) $task->lease_token)
            || (int) $task->lease_fence !== $lease->fence || $task->content_purged_time !== null
            || (int) $task->status !== 1 || $task->delete_time !== null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting task lease changed');
        }
        // Read the wall clock after FOR UPDATE has returned, not transaction start.
        if (TaskRun::where('id', $lease->taskId)->whereNull('content_purged_time')
            ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [TaskLeasePolicy::runningTimezone()])->value('id') === null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '会议任务租约已过期，未保存新的私有规划或节点');
        }
    }

    private static function hash(array $value): string
    {
        return RequestPayloadFingerprint::fingerprint($value, []);
    }

    private static function canonicalText(mixed $value): string
    {
        return json_encode(self::canonicalValue($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议节点输入不可封存');
        }
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalValue($item);
        return $value;
    }

    /** @return array<string,mixed>|null */
    private static function array(mixed $value): ?array
    {
        $value = is_object($value) ? (array) $value : $value;
        return is_array($value) ? $value : null;
    }
}
