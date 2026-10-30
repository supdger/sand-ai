<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;
use app\Application\Audio\MeetingSummaryEvidence;
use app\Application\Audio\MeetingSummaryPlan;
use app\Application\Audio\PostgresMeetingTokenCountLedger;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Verifies the sealed node tree and all covered leaves before public presentation. */
final class MeetingSummaryResultGuard
{
    private const PAGE_NODES = 32;
    private const STEP_FIELDS = 'step.id AS summary_task_step_id, step.step_code AS summary_step_code, step.state AS summary_step_state, step.attempt_count AS summary_attempt_count, node.xmin::text AS summary_node_revision, step.xmin::text AS summary_step_revision';
    private const IDENTITY_FIELDS = 'node.id,node.node_code,node.parent_node_code,node.node_kind,node.level_no,node.sequence_no,node.segment_start_index,node.segment_end_index,node.transcript_sha256,node.review_revision,node.planner_version,node.input_sha256,node.result_sha256,node.last_invocation_id';

    /** @param array<string,mixed> $value @param array<string,mixed> $prepared @param \Closure():void|null $heartbeat @return array<string,mixed> */
    public function present(TaskRun $task, array $value, array $prepared, ?\Closure $heartbeat = null): array
    {
        $taskInput = self::array($task->input);
        if (($taskInput['_meeting_durable'] ?? false) === true) {
            return (new \app\Application\Audio\PostgresMeetingDurableCoverage())->presentSaved($task, $value);
        }
        $plan = $prepared['plan'] ?? null;
        if (!is_array($plan) || !is_array($plan['leaves'] ?? null) || !is_array($plan['sources'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary plan is unavailable');
        }
        $lastHeartbeat = 0;
        $pulse = static function (bool $force = false) use ($heartbeat, &$lastHeartbeat): void {
            if ($heartbeat === null) return;
            $now = hrtime(true);
            if ($force || $now - $lastHeartbeat >= 10000000000) {
                $heartbeat();
                $lastHeartbeat = $now;
            }
        };
        $pulse(true);
        $meeting = $this->taskMeeting($task);
        if (($plan['planner_version'] ?? null) === MeetingSummaryPlan::CAPACITY_VERSION) {
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $capacity = is_array($input) ? ($input['_meeting_capacity'] ?? null) : null;
            if (!is_array($capacity)) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '会议模型容量凭据不可用');
            }
            if (\app\Application\Audio\MeetingLocalPlanning::enabled($capacity)) {
                \app\Application\Audio\MeetingLocalPlanning::assertNoCounterRequests($task);
                foreach ($plan['leaves'] as $leaf) {
                    \app\Application\Audio\MeetingLocalPlanning::verify($leaf['planning_evidence'], $leaf['messages']);
                    $pulse();
                }
            } else {
                // Bound read verification and refresh the worker lease between pages.
                $receipts = [];
                foreach ($plan['leaves'] as $leaf) {
                    $receipts[] = $leaf['token_count_receipt'] ?? [];
                    if (count($receipts) === 64) {
                        PostgresMeetingTokenCountLedger::verifyReceipts($task, $capacity, $receipts);
                        $receipts = [];
                        $pulse(true);
                    }
                }
                if ($receipts !== []) {
                    PostgresMeetingTokenCountLedger::verifyReceipts($task, $capacity, $receipts);
                    $pulse(true);
                }
            }
        }
        // A full binary tree has exactly 2L-1 nodes. Read its complete identity
        // directory in bounded statements so hydration cannot starve the lease.
        $expectedNodes = count($plan['leaves']) * 2 - 1;
        if ($expectedNodes < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
        }
        $identities = [];
        $directoryHash = hash_init('sha256');
        $directoryCount = 0;
        $rootCode = null;
        foreach ($this->directoryPages((int) $task->id, $expectedNodes, $pulse) as $directory) {
            foreach ($directory as $node) {
                $pulse();
                if (++$directoryCount > $expectedNodes) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
                }
                $code = (string) $node->node_code;
                if (isset($identities[$code])) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node identity changed');
                }
                $identity = $this->identity($node);
                $identities[$code] = $identity;
                hash_update($directoryHash, $identity);
                if ($node->parent_node_code === null) {
                    if ($rootCode !== null) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary root changed');
                    }
                    $rootCode = $code;
                }
            }
            unset($directory, $node);
        }
        $directorySha256 = hash_final($directoryHash);
        if ($directoryCount !== $expectedNodes) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
        }
        if ($rootCode === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary root changed');
        }
        // This scalar statement anchors set membership between the two sweeps.
        // Matching xmin intervals later prove every first-sweep row existed
        // here unchanged; count excludes unseen inserts below the keyset.
        $this->assertDirectoryCount((int) $task->id, $expectedNodes, $pulse);
        $leaves = 0;
        $root = null;
        foreach ($this->pages($identities) as $codes) {
            $pulse();
            $records = $this->readNodes((int) $task->id, $codes, $identities);
            $items = [];
            $related = [];
            foreach ($records as $code => $node) {
                $pulse();
                $items[$code] = $this->verifiedNode($node, $plan, $meeting);
                if ($node->parent_node_code !== null) $related[(string) $node->parent_node_code] = true;
                if ((string) $node->node_kind === 'merge') {
                    $children = $items[$code]['input']['children'] ?? null;
                    if (!is_array($children) || !array_is_list($children) || count($children) !== 2
                        || !is_string($children[0]) || !is_string($children[1]) || $children[0] === $children[1]) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge children changed');
                    }
                    $related[$children[0]] = true;
                    $related[$children[1]] = true;
                }
            }
            // At most one parent and two children per page node; fetch together
            // rather than issuing a query for every edge. No body survives a page.
            $missing = array_keys(array_diff_key($related, $records));
            if ($missing !== []) {
                foreach ($this->readNodes((int) $task->id, $missing, $identities) as $code => $node) {
                    $pulse();
                    $items[$code] = $this->verifiedNode($node, $plan, $meeting);
                }
            }
            foreach ($codes as $code) {
                $pulse();
                $item = $items[$code];
                $node = $item['node'];
                if ((string) $node->node_kind === 'chunk') {
                    ++$leaves;
                    $leaf = $plan['leaves'][(int) $node->sequence_no - 1] ?? null;
                    if (!is_array($leaf) || (string) $node->node_code !== $leaf['node_code']
                        || (int) $node->segment_start_index !== $leaf['segment_start_index']
                        || (int) $node->segment_end_index !== $leaf['segment_end_index']
                        || (int) $node->level_no !== 0 || (int) $node->sequence_no !== $leaf['sequence_no']
                        || !RequestPayloadFingerprint::same($item['input'], [
                            'messages' => $leaf['messages'], 'segment_ids' => $leaf['segment_ids'],
                        ], [])) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting leaf coverage changed');
                    }
                }
                $parent = $node->parent_node_code;
                if ($parent !== null && (!isset($items[(string) $parent])
                    || (string) $items[(string) $parent]['node']->node_kind !== 'merge'
                    || (int) $items[(string) $parent]['node']->level_no <= (int) $node->level_no
                    || !in_array($code, $items[(string) $parent]['input']['children'] ?? [], true))) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge graph changed');
                }
                if ((string) $node->node_kind === 'merge') {
                    $children = $item['input']['children'];
                    if (!isset($items[$children[0]], $items[$children[1]])
                        || (string) $items[$children[0]]['node']->parent_node_code !== $code
                        || (string) $items[$children[1]]['node']->parent_node_code !== $code) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge children changed');
                    }
                    $left = $items[$children[0]];
                    $right = $items[$children[1]];
                    $expected = (new MeetingSummaryEvidence())->mergeInput(
                        $left['result']['summary'], $right['result']['summary'], $children,
                    );
                    if ((int) $node->segment_start_index !== (int) $left['node']->segment_start_index
                        || (int) $node->segment_end_index !== (int) $right['node']->segment_end_index
                        || (int) $left['node']->segment_end_index > (int) $right['node']->segment_start_index
                        || !RequestPayloadFingerprint::same($item['input'], $expected, [])) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge source changed');
                    }
                }
                if ($code === $rootCode) $root = $item['result'];
            }
            unset($records, $items, $related, $item, $node, $left, $right, $expected);
        }
        if ($leaves !== count($plan['leaves']) || $root === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
        }
        // All row-version intervals must span the boundary between sweeps.
        // PostgreSQL xmin detects committed A->B->A edits as well as content
        // drift. Body and adjacent checks use these same first identities.
        $this->recheckDirectory((int) $task->id, $expectedNodes, $identities, $rootCode, $directorySha256, $pulse);
        $pulse(true);
        $current = TaskRun::where('id', (int) $task->id)->field('id,content_purged_time')->find();
        if ($current === null || $current->content_purged_time !== null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary content is unavailable');
        }
        $summary = $root['summary'];
        $summary['schema']['version'] = '1.1.0';
        $summary['coverage'] = [
            'total_segments' => $plan['total_segments'],
            'eligible_segments' => $plan['eligible_segments'],
            'covered_segments' => $plan['eligible_segments'],
            'uncovered_segment_ids' => [], 'complete' => true,
        ];
        if (($value['meeting_summary'] ?? null) != $summary
            || ($value['output']['content'] ?? null) !== $root['content']
            || ($value['invocation']['request_id'] ?? null) !== $root['request_id']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary result changed');
        }
        return $summary;
    }

    /** @param \Closure(bool=):void $pulse @return \Generator<int,\think\Collection> */
    private function directoryPages(int $taskId, int $expectedNodes, \Closure $pulse): \Generator
    {
        $afterId = 0;
        $count = 0;
        do {
            $pulse();
            // select() must finish before pulse() can UPDATE through the same
            // pooled connection. Do not replace this with an active cursor.
            $page = MeetingSummaryNode::alias('node')
                ->leftJoin('sand_ai_task_step step', 'step.id = node.task_step_id AND step.task_id = node.task_id AND step.delete_time IS NULL')
                ->field(self::IDENTITY_FIELDS)->fieldRaw(self::STEP_FIELDS)
                ->where('node.task_id', $taskId)->where('node.id', '>', $afterId)
                ->order('node.id')->limit(min(self::PAGE_NODES, $expectedNodes + 1 - $count))->select();
            $pulse();
            if ($page->isEmpty()) return;
            foreach ($page as $node) {
                $id = (int) $node->id;
                if ($id <= $afterId || ++$count > $expectedNodes) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
                }
                $afterId = $id;
            }
            yield $page;
            unset($page, $node);
        } while ($count <= $expectedNodes);
    }

    /** @param array<string,string> $identities @param \Closure(bool=):void $pulse */
    private function recheckDirectory(int $taskId, int $expectedNodes, array $identities, string $rootCode, string $directorySha256, \Closure $pulse): void
    {
        $hash = hash_init('sha256');
        $count = 0;
        $currentRoot = null;
        foreach ($this->directoryPages($taskId, $expectedNodes, $pulse) as $directory) {
            foreach ($directory as $node) {
                $pulse();
                ++$count;
                $code = (string) $node->node_code;
                $identity = $this->identity($node);
                if (!isset($identities[$code]) || !hash_equals($identities[$code], $identity)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node identity changed');
                }
                hash_update($hash, $identity);
                if ($node->parent_node_code === null) {
                    if ($currentRoot !== null || $code !== $rootCode) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting summary root changed');
                    }
                    $currentRoot = $code;
                }
            }
            unset($directory, $node);
        }
        if ($count !== $expectedNodes || $currentRoot !== $rootCode
            || !hash_equals($directorySha256, hash_final($hash))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
        }
        $this->assertDirectoryCount($taskId, $expectedNodes, $pulse);
    }

    /** @param \Closure(bool=):void $pulse */
    private function assertDirectoryCount(int $taskId, int $expectedNodes, \Closure $pulse): void
    {
        $pulse();
        $count = (int) MeetingSummaryNode::where('task_id', $taskId)->count();
        $pulse();
        if ($count !== $expectedNodes) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting transcript coverage changed');
        }
    }

    /** @param array<string,string> $identities @return \Generator<int,list<string>> */
    private function pages(array $identities): \Generator
    {
        $codes = [];
        foreach ($identities as $code => $identity) {
            $codes[] = $code;
            if (count($codes) === self::PAGE_NODES) {
                yield $codes;
                $codes = [];
            }
        }
        if ($codes !== []) yield $codes;
    }

    /** @param list<string> $codes @param array<string,string> $identities @return array<string,MeetingSummaryNode> */
    private function readNodes(int $taskId, array $codes, array $identities): array
    {
        foreach ($codes as $code) {
            if (!isset($identities[$code])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge graph changed');
            }
        }
        $result = [];
        foreach (MeetingSummaryNode::alias('node')
            ->leftJoin('sand_ai_task_step step', 'step.id = node.task_step_id AND step.task_id = node.task_id AND step.delete_time IS NULL')
            ->field('node.*')->fieldRaw(self::STEP_FIELDS)
            ->where('node.task_id', $taskId)->whereIn('node.node_code', $codes)->limit(count($codes) + 1)->select() as $node) {
            $code = (string) $node->node_code;
            if (isset($result[$code]) || !isset($identities[$code])
                || !hash_equals($identities[$code], $this->identity($node))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node identity changed');
            }
            $result[$code] = $node;
        }
        if (count($result) !== count($codes)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting merge graph changed');
        }
        return $result;
    }

    /** Commits every graph, content and owning-step fact used by the verifier. */
    private function identity(MeetingSummaryNode $node): string
    {
        return RequestPayloadFingerprint::fingerprint([
            'id' => (int) $node->id, 'node_code' => (string) $node->node_code,
            'parent_node_code' => $node->parent_node_code, 'node_kind' => (string) $node->node_kind,
            'level_no' => (int) $node->level_no, 'sequence_no' => (int) $node->sequence_no,
            'segment_start_index' => (int) $node->segment_start_index,
            'segment_end_index' => (int) $node->segment_end_index,
            'transcript_sha256' => (string) $node->transcript_sha256,
            'review_revision' => (int) $node->review_revision, 'planner_version' => (string) $node->planner_version,
            'input_sha256' => (string) $node->input_sha256, 'result_sha256' => (string) $node->result_sha256,
            'last_invocation_id' => (int) $node->last_invocation_id,
            'step_id' => $node->getAttr('summary_task_step_id'),
            'step_code' => (string) $node->getAttr('summary_step_code'),
            'step_state' => (string) $node->getAttr('summary_step_state'),
            'attempt_count' => (int) $node->getAttr('summary_attempt_count'),
            'node_revision' => (string) $node->getAttr('summary_node_revision'),
            'step_revision' => (string) $node->getAttr('summary_step_revision'),
        ], []);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $meeting @return array{node:MeetingSummaryNode,input:array<string,mixed>,result:array<string,mixed>} */
    private function verifiedNode(MeetingSummaryNode $node, array $plan, array $meeting): array
    {
        if ((string) $node->transcript_sha256 !== (string) ($meeting['sha256'] ?? '')
            || (int) $node->review_revision !== ($meeting['review_revision'] ?? null)
            || !in_array((string) $node->node_kind, ['chunk', 'merge'], true)
            || (string) $node->planner_version !== ($plan['planner_version'] ?? MeetingSummaryPlan::VERSION)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node identity changed');
        }
        $input = self::array($node->input_snapshot);
        $result = self::array($node->result_snapshot);
        $stepId = $node->getAttr('summary_task_step_id');
        $stepCode = (string) $node->getAttr('summary_step_code');
        $stepState = (string) $node->getAttr('summary_step_state');
        $attempt = (int) $node->getAttr('summary_attempt_count');
        if ($input === null || $result === null || $stepId === null || $stepState !== TaskState::SUCCEEDED
            || !hash_equals((string) $node->input_sha256, RequestPayloadFingerprint::fingerprint($input, []))
            || !hash_equals((string) $node->result_sha256, RequestPayloadFingerprint::fingerprint($result, []))
            || !is_string($result['content'] ?? null)
            || $stepCode !== (string) $node->node_code || $attempt < 1
            || !is_int($result['invocation_id'] ?? null) || $result['invocation_id'] < 1
            || (int) $node->last_invocation_id !== $result['invocation_id']
            || ($result['request_id'] ?? null) !== sprintf('task_%d_%s_attempt_%d',
                (int) $node->task_id, (string) $node->node_code, $attempt)
            || !is_array($input['segment_ids'] ?? null) || !array_is_list($input['segment_ids'])
            || $input['segment_ids'] === []) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Completed meeting node changed');
        }
        $allowed = [];
        foreach ($input['segment_ids'] as $id) {
            if (!is_string($id) || !isset($plan['sources'][$id]) || isset($allowed[$id])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node source changed');
            }
            $allowed[$id] = $plan['sources'][$id];
        }
        if ((new MeetingSummaryEvidence())->present($result['content'], $allowed,
            \app\Application\Audio\MeetingSummaryOutputContract::claimLimits($meeting, (string) $node->node_kind, $input)) != ($result['summary'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting node citations changed');
        }
        return ['node' => $node, 'input' => $input, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private function taskMeeting(TaskRun $task): array
    {
        $input = self::array($task->input);
        return is_array($input['_meeting_summary'] ?? null) ? $input['_meeting_summary'] : [];
    }

    /** @return array<string,mixed>|null */
    private static function array(mixed $value): ?array
    {
        $value = is_object($value) ? (array) $value : $value;
        return is_array($value) ? $value : null;
    }
}
