<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Coverage advances through database-checked immutable node pages, never a PHP cursor. */
final class PostgresMeetingDurableCoverage
{
    public const ROUND = 32;

    /** @return array<string,mixed> */
    public function verifyNextPage(TaskRun $task, TaskExecutionLease $lease): array
    {
        $ledger = new PostgresMeetingWorkerLedger();
        return $ledger->runStage($task, $lease, function (TaskRun $current) use ($ledger, $lease): array {
            $last = Db::connect('pgsql')->query(
                "SELECT sequence_no,private_payload->(entry_count-1) AS head FROM sand_ai_meeting_worker_page
                 WHERE task_id=? AND page_kind='frontier' AND round_no=32 ORDER BY sequence_no DESC LIMIT 1", [(int) $current->id]);
            $head = $last === [] ? ['node_id' => 0, 'total_nodes' => 0, 'leaf_nodes' => 0, 'root_nodes' => 0]
                : self::array($last[0]['head']);
            if ($head === null) self::invalid();
            $rows = Db::connect('pgsql')->query(
                'SELECT n.id,n.task_step_id,n.node_code,n.node_kind,n.input_sha256,n.worker_messages_sha256,n.result_sha256,
                    n.last_invocation_id,n.parent_node_code FROM sand_ai_meeting_summary_node n
                 WHERE n.task_id=? AND n.id>? ORDER BY n.id LIMIT 32', [(int) $current->id, (int) $head['node_id']]);
            if ($rows === []) {
                return ['phase' => 'coverage_ready', 'done' => true, 'total_nodes' => (int) $head['total_nodes']];
            }
            $entries = [];
            foreach ($rows as $row) {
                $head['total_nodes']++;
                if ($row['node_kind'] === 'chunk') $head['leaf_nodes']++;
                if ($row['parent_node_code'] === null) $head['root_nodes']++;
                $entries[] = ['node_id' => (int) $row['id'], 'task_step_id' => (int) $row['task_step_id'],
                    'node_code' => $row['node_code'], 'input_sha256' => $row['input_sha256'],
                    'messages_sha256' => $row['worker_messages_sha256'], 'result_sha256' => $row['result_sha256'],
                    'last_invocation_id' => (int) $row['last_invocation_id'],
                    'total_nodes' => $head['total_nodes'], 'leaf_nodes' => $head['leaf_nodes'], 'root_nodes' => $head['root_nodes']];
            }
            $ledger->appendPage($current, $lease, 'frontier', self::ROUND,
                $last === [] ? 1 : (int) $last[0]['sequence_no'] + 1, $entries);
            // Publishing is a subsequent transaction: deferred lease constraints
            // of this new page still require RUNNING at its own commit.
            return ['phase' => 'coverage_page_verified', 'done' => false, 'node_count' => count($entries)];
        });
    }

    /** Exact scalar proof and sole root read. The SQL terminal guard checks this again.
     * @return array<string,mixed>
     */
    public function candidate(TaskRun $task, TaskExecutionLease $lease): array
    {
        return (new PostgresMeetingWorkerLedger())->runStage($task, $lease,
            fn (TaskRun $current): array => $this->projection($current));
    }

    /** The caller reauthorizes current task/audio/file facts before presentation.
     * @param array<string,mixed> $value @return array<string,mixed>
     */
    public function presentSaved(TaskRun $task, array $value): array
    {
        if ((string) $task->state !== 'succeeded' || $task->content_purged_time !== null
            || (int) $task->status !== 1 || $task->delete_time !== null) self::invalid();
        $expected = $this->projection($task);
        if (!RequestPayloadFingerprint::same($expected, $value, [])) self::invalid();
        return $expected['meeting_summary'];
    }

    /** @return array<string,mixed> */
    private function projection(TaskRun $current): array
    {
            $rows = Db::connect('pgsql')->query(
                "SELECT p.private_payload->(p.entry_count-1) AS head,
                    c.anchor_snapshot #>> '{plan,leaf_count}' AS expected_leaves,
                    c.anchor_snapshot #>> '{plan,total_segments}' AS total_segments,
                    c.anchor_snapshot #>> '{plan,eligible_segments}' AS eligible_segments,
                    c.anchor_snapshot #>> '{source,review_revision}' AS review_revision
                 FROM sand_ai_meeting_worker_checkpoint c JOIN LATERAL (
                    SELECT * FROM sand_ai_meeting_worker_page WHERE task_id=c.task_id AND page_kind='frontier'
                        AND round_no=32 ORDER BY sequence_no DESC LIMIT 1) p ON true WHERE c.task_id=? AND c.state='staging'",
                [(int) $current->id]);
            if (count($rows) !== 1) self::invalid();
            $scope = $rows[0]; $head = self::array($scope['head']);
            $leaves = (int) $scope['expected_leaves'];
            if ($head === null || $leaves < 1 || (int) $head['total_nodes'] !== 2 * $leaves - 1
                || (int) $head['leaf_nodes'] !== $leaves || (int) $head['root_nodes'] !== 1
                || Db::connect('pgsql')->query('SELECT id FROM sand_ai_meeting_summary_node WHERE task_id=? AND id>? ORDER BY id LIMIT 1',
                    [(int) $current->id, (int) $head['node_id']]) !== []) self::invalid();
            $roots = Db::connect('pgsql')->query(
                'SELECT node_code,node_kind,input_snapshot,input_sha256,result_snapshot,result_sha256 FROM sand_ai_meeting_summary_node WHERE task_id=? AND parent_node_code IS NULL LIMIT 2',
                [(int) $current->id]);
            if (count($roots) !== 1) self::invalid();
            $result = self::array($roots[0]['result_snapshot']);
            if ($result === null || !is_array($result['summary'] ?? null) || !is_string($result['content'] ?? null)
                || !hash_equals($roots[0]['result_sha256'], RequestPayloadFingerprint::fingerprint($result, []))) self::invalid();
            $nodeInput = self::array($roots[0]['input_snapshot']);
            $taskInput = self::array($current->input);
            if ($nodeInput === null || !is_array($taskInput['_meeting_summary'] ?? null)
                || !hash_equals($roots[0]['input_sha256'], RequestPayloadFingerprint::fingerprint($nodeInput, []))) self::invalid();
            $evidence = new MeetingSummaryEvidence();
            $evidence->decodeObjectClaims($result['content'], MeetingSummaryOutputContract::claimLimits(
                $taskInput['_meeting_summary'], $roots[0]['node_kind'], $nodeInput));
            $evidence->assertMergeable($result['summary']);
            $summary = $result['summary'];
            $summary['schema']['version'] = '1.1.0';
            $summary['coverage'] = ['total_segments' => (int) $scope['total_segments'],
                'eligible_segments' => (int) $scope['eligible_segments'], 'covered_segments' => (int) $scope['eligible_segments'],
                'uncovered_segment_ids' => [], 'complete' => true];
            return ['output' => ['content' => $result['content']], 'meeting_summary' => $summary,
                'meeting_summary_error' => null, 'audio_review_revision' => (int) $scope['review_revision'],
                'invocation' => ['request_id' => $result['request_id']], 'review_required' => true];
    }

    private static function array(mixed $value): ?array
    {
        if (is_string($value)) $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : null;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE', '会议持久覆盖证明尚未完成');
    }
}
