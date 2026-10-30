<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Task\TaskExecutionLease;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/**
 * One durable transition per task claim: one source/part page, one leaf, one
 * model node, one deterministic pair, or one <=32-node coverage page.
 * No elapsed-time budget or mutable PHP cursor can skip a required transition.
 */
final class PostgresMeetingDurableWorker
{
    public function __construct(private readonly ?ChatGateway $chat = null)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function tick(TaskRun $task, TaskExecutionLease $lease, array $input,
        UsageAttribution $attribution, \Closure $authorize): array
    {
        $authorize(0);
        if (!MeetingExecutionRuntime::forTask($task)) self::invalid();
        $ledger = new PostgresMeetingWorkerLedger();
        // Check the complete execution schema before any tokenizer intent.
        $ledger->assertReady();
        $persisted = self::array($task->input);
        $meeting = $persisted['_meeting_summary'] ?? null;
        if (!is_array($meeting)) self::invalid();
        $planner = new PostgresMeetingDurablePlanner();
        if (!is_array($persisted['_meeting_plan']['prepared_plan_ref'] ?? null)) {
            $progress = $planner->prepareTick($task, $lease);
            return self::continuation($progress['phase'] ?? 'planning');
        }
        $planner->planSnapshot($task, $lease);
        $ledger->enroll($task, $lease);
        $action = $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease, $planner): array {
            $plan = $planner->planSnapshot($current, $lease);
            if (!is_array($plan) || !is_int($plan['leaf_count'] ?? null) || $plan['leaf_count'] < 1
                || $plan['leaf_count'] > 100000 || !is_int($plan['total_segments'] ?? null)
                || $plan['total_segments'] < 1 || $plan['total_segments'] > 10000) self::invalid();
            $sourcePages = (int) ceil($plan['total_segments'] / 32);
            $tail = self::tail((int) $current->id, 'source', 0);
            if ($tail < $sourcePages) return ['kind' => 'source', 'page_no' => $tail + 1];
            if ($tail !== $sourcePages) self::invalid();
            // The partial index covers only unfinished owning steps. Completed
            // history is never rehydrated to select the next node.
            $pending = Db::connect('pgsql')->query(
                "SELECT n.node_code,s.state,s.attempt_count,n.task_step_id FROM sand_ai_task_step s
                 JOIN sand_ai_meeting_summary_node n ON n.task_step_id=s.id AND n.task_id=s.task_id
                 WHERE s.task_id=? AND s.sequence_no>1 AND s.state<>'succeeded' ORDER BY s.sequence_no LIMIT 1",
                [(int) $current->id]);
            if ($pending !== []) return ['kind' => 'execute', 'node_code' => $pending[0]['node_code']];
            $leafTail = self::tail((int) $current->id, 'frontier', 0);
            if ($leafTail < $plan['leaf_count']) {
                $leaf = $leafTail + 1;
                $spec = $planner->readLeaf($current, $lease, $leaf);
                if (!is_array($spec['parts'] ?? null) || count($spec['parts']) < 1 || count($spec['parts']) > 512) self::invalid();
                $parts = Db::connect('pgsql')->query(
                    'SELECT part_page_no FROM sand_ai_meeting_leaf_part_page WHERE task_id=? AND leaf_sequence_no=? ORDER BY part_page_no DESC LIMIT 1',
                    [(int) $current->id, $leaf]);
                $partTail = $parts === [] ? 0 : (int) $parts[0]['part_page_no'];
                $partPages = (int) ceil(count($spec['parts']) / 32);
                if ($partTail < $partPages) return ['kind' => 'part', 'leaf' => $leaf, 'page' => $partTail + 1];
                if ($partTail !== $partPages) self::invalid();
                return ['kind' => 'leaf', 'leaf' => $leaf];
            }
            if ($leafTail !== $plan['leaf_count']) self::invalid();
            $previousCount = $plan['leaf_count'];
            for ($round = 1; $round < 32 && $previousCount > 1; $round++) {
                $count = (int) ceil($previousCount / 2);
                $roundTail = self::tail((int) $current->id, 'frontier', $round);
                if ($roundTail > $count) self::invalid();
                if ($roundTail === $count) {
                    $previousCount = $count;
                    continue;
                }
                $sequence = $roundTail + 1;
                $left = $ledger->readPage($current, $lease, 'frontier', $round - 1, 2 * $sequence - 1);
                if (count($left) !== 1 || !is_string($left[0]['node_code'] ?? null)) self::invalid();
                if (2 * $sequence > $previousCount) {
                    return ['kind' => 'frontier', 'round' => $round, 'sequence' => $sequence, 'entry' => $left[0]];
                }
                $right = $ledger->readPage($current, $lease, 'frontier', $round - 1, 2 * $sequence);
                if (count($right) !== 1 || !is_string($right[0]['node_code'] ?? null)) self::invalid();
                $children = Db::connect('pgsql')->query(
                    'SELECT node_code,level_no FROM sand_ai_meeting_summary_node WHERE task_id=? AND node_code IN (?,?)',
                    [(int) $current->id, $left[0]['node_code'], $right[0]['node_code']]);
                if (count($children) !== 2) self::invalid();
                $level = max((int) $children[0]['level_no'], (int) $children[1]['level_no']) + 1;
                $code = sprintf('merge_%02d_%05d', $level, $sequence);
                $node = Db::connect('pgsql')->query(
                    'SELECT id,input_sha256,result_sha256 FROM sand_ai_meeting_summary_node WHERE task_id=? AND node_code=?',
                    [(int) $current->id, $code]);
                if ($node === []) return ['kind' => 'merge', 'left' => $left[0]['node_code'], 'right' => $right[0]['node_code'],
                    'code' => $code, 'level' => $level, 'sequence' => $sequence];
                if (count($node) !== 1 || $node[0]['result_sha256'] === null) self::invalid();
                return ['kind' => 'frontier', 'round' => $round, 'sequence' => $sequence,
                    'entry' => ['node_code' => $code, 'node_id' => (int) $node[0]['id'],
                        'input_sha256' => $node[0]['input_sha256'], 'state' => 'pending']];
            }
            return ['kind' => 'coverage'];
        });
        return (new \app\Application\Audio\Workflow\MeetingStageWorkflow())->run($action,
            new \app\Application\Audio\Workflow\MeetingStageResources(
                $task, $lease, $meeting, $input, $attribution, $authorize,
                fn (array $codes): array => $this->sources($task, $lease, $codes),
                new MeetingSummaryRunner($this->chat), $ledger, new PostgresMeetingLeafMaterialization(),
            ));
    }

    /** Sources for one node/pair and its two children; a fixed 10000-position
     * ceiling, read from already persisted private plan pages, no raw re-scan.
     * @param list<string> $codes @return array<string,array<string,mixed>>
     */
    private function sources(TaskRun $task, TaskExecutionLease $lease, array $codes): array
    {
        $positions = []; $readCodes = [];
        foreach ($codes as $code) {
            $node = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $code)->find();
            $snapshot = $node === null ? null : self::array($node->input_snapshot);
            if ($snapshot === null) self::invalid();
            $readCodes[$code] = $snapshot;
            foreach ($snapshot['children'] ?? [] as $childCode) {
                if (!is_string($childCode) || count($readCodes) > 6) self::invalid();
                $child = MeetingSummaryNode::where('task_id', (int) $task->id)->where('node_code', $childCode)->find();
                $childInput = $child === null ? null : self::array($child->input_snapshot);
                if ($childInput === null) self::invalid();
                $readCodes[$childCode] = $childInput;
            }
        }
        foreach ($readCodes as $snapshot) {
            if (!is_array($snapshot['segment_ids'] ?? null) || count($snapshot['segment_ids']) > 10000) self::invalid();
            foreach ($snapshot['segment_ids'] as $id) {
                if (!is_string($id) || preg_match('/\AS([1-9][0-9]{0,4})\z/D', $id, $match) !== 1
                    || (int) $match[1] > 10000) self::invalid();
                $positions[(int) $match[1] - 1] = true;
            }
        }
        $indices = array_keys($positions); sort($indices, SORT_NUMERIC);
        if ($indices === [] || count($indices) > 10000) self::invalid();
        $sources = []; $planner = new PostgresMeetingDurablePlanner();
        foreach (array_chunk($indices, 32) as $page) {
            foreach ($planner->readSourceReferences($task, $lease, $page) as $entry) {
                if (!is_int($entry['segment_index'] ?? null) || !isset($positions[$entry['segment_index']])
                    || !is_array($entry['source_reference'] ?? null)) self::invalid();
                $sources['S' . ($entry['segment_index'] + 1)] = $entry['source_reference'];
            }
        }
        if (count($sources) !== count($indices)) self::invalid();
        return $sources;
    }

    private static function tail(int $taskId, string $kind, int $round): int
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT sequence_no FROM sand_ai_meeting_worker_page WHERE task_id=? AND page_kind=? AND round_no=? ORDER BY sequence_no DESC LIMIT 1',
            [$taskId, $kind, $round]);
        return $rows === [] ? 0 : (int) $rows[0]['sequence_no'];
    }

    /** @return array<string,mixed> */
    private static function continuation(string $phase): array
    {
        return ['_meeting_durable_continue' => true, 'phase' => $phase];
    }

    private static function array(mixed $value): ?array
    {
        if (is_object($value)) $value = (array) $value;
        if (is_string($value)) $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : null;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', '会议持久执行阶段与固定计划不一致');
    }
}
