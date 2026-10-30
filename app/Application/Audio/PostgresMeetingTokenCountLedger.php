<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskLeasePolicy;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\TaskRun;
use PDO;
use think\facade\Db;
use Throwable;

/** Append-only tokenizer facts, separate from model Invocation and ToolRun. */
final class PostgresMeetingTokenCountLedger implements MeetingSummaryTokenCountLedger
{
    private const REQUESTS = 'sand_ai_meeting_token_count_request';
    private const OUTCOMES = 'sand_ai_meeting_token_count_outcome';
    private readonly int $taskId;

    public function __construct(TaskRun $task, private readonly TaskExecutionLease $lease, private readonly array $capacitySnapshot)
    {
        if ((int) $task->id !== $lease->taskId) $this->invalid();
        MeetingSummaryCapacity::assert($capacitySnapshot);
        $limit = $capacitySnapshot['max_tokenizer_requests'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 100000) $this->invalid();
        $this->taskId = $lease->taskId;
    }

    /** Call only after TaskGateway has reauthorized the task and private source. */
    public static function project(TaskRun $task, array $capacitySnapshot): array
    {
        $scope = self::readScope($task, $capacitySnapshot);
        $pdo = self::readyConnection();
        $query = $pdo->prepare('SELECT r.*,o.state AS outcome_state FROM sand_ai_meeting_token_count_request r '
            . 'LEFT JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id WHERE r.task_id=:task');
        $query->execute(['task' => (int) $task->id]);
        $total = 0; $succeeded = 0; $rejected = 0; $unknown = 0;
        while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ($scope as $key => $value) {
                if ((string) $row[$key] !== (string) $value) self::readInvalid();
            }
            ++$total;
            if ($total > $capacitySnapshot['max_tokenizer_requests']) self::readInvalid();
            if ($row['outcome_state'] === 'succeeded') ++$succeeded;
            elseif ($row['outcome_state'] === 'rejected') ++$rejected;
            else ++$unknown;
        }
        return ['request_count' => $total, 'succeeded_count' => $succeeded, 'rejected_count' => $rejected,
            'unknown_count' => $unknown, 'metering_unit' => 'external_request', 'metering_quantity' => (string) $total,
            'pricing_state' => 'unpriced', 'metered_amount' => null, 'currency' => null];
    }

    /** Reauthorize source before use; verifies persisted success, not a self-hashed task input. */
    public static function verifyReceipt(TaskRun $task, array $capacitySnapshot, array $receipt): void
    {
        self::verifyReceipts($task, $capacitySnapshot, [$receipt]);
    }

    /** @param list<array<string,mixed>> $receipts */
    public static function verifyReceipts(TaskRun $task, array $capacitySnapshot, array $receipts): void
    {
        $scope = self::readScope($task, $capacitySnapshot);
        $pdo = self::readyConnection();
        if (!array_is_list($receipts) || count($receipts) > $capacitySnapshot['max_tokenizer_requests']) self::readInvalid();
        $expected = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) self::readInvalid();
            $id = $receipt['external_request_event_id'] ?? null;
            if (!is_int($id) || $id < 1) self::readInvalid();
            if (isset($expected[$id]) && !hash_equals(RequestPayloadFingerprint::fingerprint($expected[$id], []), RequestPayloadFingerprint::fingerprint($receipt, []))) self::readInvalid();
            $expected[$id] = $receipt;
        }
        foreach (array_chunk(array_keys($expected), 64) as $ids) {
            $holders = implode(',', array_fill(0, count($ids), '?'));
            $query = $pdo->prepare('SELECT r.*,o.state AS outcome_state,o.http_status,o.prompt_tokens,o.capacity_receipt,o.outcome_sha256 '
                . 'FROM sand_ai_meeting_token_count_request r JOIN sand_ai_meeting_token_count_outcome o ON o.request_event_id=r.id '
                . 'WHERE r.task_id=? AND r.id IN (' . $holders . ')');
            $query->execute(array_merge([(int) $task->id], $ids));
            $found = 0;
            while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = (int) $row['id'];
                if (!isset($expected[$id])) self::readInvalid();
                self::verifyReceiptRow($scope, $expected[$id], $row);
                ++$found;
            }
            if ($found !== count($ids)) self::readInvalid();
        }
    }

    private static function verifyReceiptRow(array $scope, array $receipt, array $row): void
    {
        if ($row['outcome_state'] !== 'succeeded' || (int) $row['http_status'] !== 200) self::readInvalid();
        foreach ($scope as $key => $value) if ((string) $row[$key] !== (string) $value) self::readInvalid();
        $saved = json_decode($row['capacity_receipt'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($saved) || !hash_equals(RequestPayloadFingerprint::fingerprint($saved, []), RequestPayloadFingerprint::fingerprint($receipt, []))
            || ($saved['input_sha256'] ?? null) !== $row['input_sha256'] || ($saved['request_id'] ?? null) !== $row['request_id']
            || ($saved['remote_model'] ?? null) !== $row['remote_model'] || ($saved['external_request_event_id'] ?? null) !== (int) $row['id']
            || ($saved['prompt_tokens'] ?? null) !== (int) $row['prompt_tokens']) self::readInvalid();
        $facts = ['state' => 'succeeded', 'http_status' => 200, 'prompt_tokens' => (int) $row['prompt_tokens'], 'capacity_receipt' => $saved];
        if (!hash_equals($row['outcome_sha256'], RequestPayloadFingerprint::fingerprint($facts, []))) self::readInvalid();
    }

    private static function readScope(TaskRun $task, array $capacitySnapshot): array
    {
        MeetingSummaryCapacity::assert($capacitySnapshot);
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if (!is_array($input) || $task->content_purged_time !== null || !is_array($input['_meeting_summary'] ?? null)
            || !is_array($input['_meeting_capacity'] ?? null) || !is_array($input['_meeting_route'] ?? null)
            || ($input['_meeting_summary']['planner_version'] ?? null) !== 'meeting-summary-v3'
            || !hash_equals(RequestPayloadFingerprint::fingerprint($capacitySnapshot['route'], []), RequestPayloadFingerprint::fingerprint($input['_meeting_route'], []))
            || !hash_equals(RequestPayloadFingerprint::fingerprint($capacitySnapshot, []), RequestPayloadFingerprint::fingerprint($input['_meeting_capacity'], []))) self::readInvalid();
        $attribution = UsageAttribution::fromCurrentPersisted($input['_usage_attribution'] ?? null, (int) $task->environment_id, 'ai_task', 'sand_ai.task.read');
        $source = $input['_meeting_summary']; $route = $capacitySnapshot['route'];
        return ['task_id' => (int) $task->id, 'environment_id' => (int) $task->environment_id,
            'owner_scope_sha256' => RequestPayloadFingerprint::fingerprint($input['_usage_attribution'], []),
            'organization_id' => $attribution->organizationId, 'application_id' => $attribution->applicationId,
            'workload_client_id' => $attribution->workloadClientId, 'capacity_sha256' => $capacitySnapshot['snapshot_sha256'],
            'route_sha256' => $route['snapshot_sha256'], 'audio_task_id' => $source['audio_task_id'],
            'transcript_sha256' => $source['sha256'], 'review_revision' => $source['review_revision']];
    }

    private static function readInvalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_AUDIT_UNAVAILABLE', 'Meeting tokenizer read evidence is inconsistent');
    }

    public function find(string $inputSha256): ?array
    {
        $this->digest($inputSha256);
        $pdo = $this->connection();
        $scope = $this->scope($pdo, false);
        $request = $this->request($pdo, $inputSha256);
        return $request === null ? null : $this->receipt($pdo, $request, $scope);
    }

    public function countRequests(): int
    {
        $pdo = $this->connection();
        $this->scope($pdo, false);
        return $this->count($pdo);
    }

    public function begin(array $facts): array
    {
        $input = $facts['input_sha256'] ?? null;
        if (!is_string($input)) $this->invalid();
        $this->digest($input);
        $route = $this->capacitySnapshot['route'];
        if (($facts['counter'] ?? null) !== 'bigmodel_tokenizer@1'
            || ($facts['endpoint'] ?? null) !== 'https://open.bigmodel.cn/api/paas/v4/tokenizer'
            || ($facts['remote_model'] ?? null) !== $route['deployment_remote_model']
            || ($facts['pricing_state'] ?? null) !== 'unpriced'
            || ($facts['metered_amount'] ?? null) !== null || ($facts['currency'] ?? null) !== null
            || ($facts['metering_unit'] ?? null) !== 'external_request'
            || ($facts['metering_quantity'] ?? null) !== '1') $this->invalid();
        $pdo = $this->connection();
        // A surrounding transaction could roll back the pre-send fact after HTTP.
        if ($pdo->inTransaction()) $this->invalid();
        $pdo->beginTransaction();
        try {
            $scope = $this->scope($pdo, true);
            $old = $this->request($pdo, $input);
            if ($old !== null) {
                $receipt = $this->receipt($pdo, $old, $scope);
                $this->assertCurrentLease($pdo);
                $pdo->commit();
                return ['cached_receipt' => $receipt];
            }
            if ($this->count($pdo) >= $this->capacitySnapshot['max_tokenizer_requests']) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_LIMIT_REACHED', '本任务已达到登记的分词请求上限，未继续分词或摘要；请管理员在允许范围内调整上限，或选择输入容量更大的已登记部署后用新请求提交');
            }
            $requestId = 'msc_' . $this->taskId . '_' . substr(hash('sha256', $input . ':' . $this->capacitySnapshot['snapshot_sha256']), 0, 40);
            $values = $scope + [
                'input_sha256' => $input, 'request_id' => $requestId,
                'request_identity_sha256' => RequestPayloadFingerprint::fingerprint([
                    'model' => $route['deployment_remote_model'], 'messages_sha256' => $input,
                    'request_id' => $requestId, 'endpoint' => $facts['endpoint'],
                ], []),
                'lease_fence' => $this->lease->fence,
            ];
            $columns = array_keys($values);
            $statement = $pdo->prepare('INSERT INTO ' . self::REQUESTS . ' (' . implode(',', $columns) . ') VALUES (:'
                . implode(',:', $columns) . ') RETURNING id');
            $statement->execute($values);
            $id = (int) $statement->fetchColumn();
            if ($id < 1) $this->invalid();
            // Never commit an HTTP permission using the transaction-start timestamp.
            $this->assertCurrentLease($pdo);
            $pdo->commit();
            return ['started_event_id' => $id, 'request_id' => $requestId];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function finish(int $startedEventId, array $outcome): void
    {
        $pdo = $this->connection();
        if ($pdo->inTransaction() || $startedEventId < 1) $this->invalid();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM ' . self::REQUESTS . ' WHERE id=:id AND task_id=:task FOR UPDATE');
            $statement->execute(['id' => $startedEventId, 'task' => $this->taskId]);
            $request = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request) || !hash_equals($this->capacitySnapshot['snapshot_sha256'], $request['capacity_sha256'])) $this->invalid();
            // A lease lost after send must not suppress cost/outcome facts.
            $facts = $this->outcome($request, $outcome);
            $digest = RequestPayloadFingerprint::fingerprint($facts, []);
            $existing = $pdo->prepare('SELECT outcome_sha256 FROM ' . self::OUTCOMES . ' WHERE request_event_id=:id');
            $existing->execute(['id' => $startedEventId]);
            $old = $existing->fetchColumn();
            if ($old !== false) {
                if (!is_string($old) || !hash_equals($old, $digest)) $this->invalid();
            } else {
                $insert = $pdo->prepare('INSERT INTO ' . self::OUTCOMES
                    . ' (request_event_id,state,http_status,prompt_tokens,capacity_receipt,outcome_sha256)'
                    . ' VALUES (:id,:state,:http,:tokens,CAST(:receipt AS jsonb),:digest)');
                $insert->execute(['id' => $startedEventId, 'state' => $facts['state'], 'http' => $facts['http_status'],
                    'tokens' => $facts['prompt_tokens'], 'receipt' => $facts['capacity_receipt'] === null ? null : json_encode($facts['capacity_receipt'], JSON_THROW_ON_ERROR), 'digest' => $digest]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private function connection(): PDO
    {
        return self::readyConnection();
    }

    private static function readyConnection(): PDO
    {
        try {
            $connection = Db::connect('pgsql');
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO) $pdo = $connection->connect();
            if (!$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') self::readInvalid();
            $probe = $pdo->query("SELECT to_regclass('sand_ai_meeting_token_count_request') IS NOT NULL AND to_regclass('sand_ai_meeting_token_count_outcome') IS NOT NULL AND to_regclass('sand_ai_schema_migration') IS NOT NULL AND (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgenabled IN ('O','A') AND tgtype=27 AND tgfoid=to_regprocedure('sand_ai_meeting_token_count_immutable()') AND ((tgrelid=to_regclass('sand_ai_meeting_token_count_request') AND tgname='sand_ai_meeting_token_count_request_immutable') OR (tgrelid=to_regclass('sand_ai_meeting_token_count_outcome') AND tgname='sand_ai_meeting_token_count_outcome_immutable'))) = 2 AS ready");
            if ($probe === false || !in_array($probe->fetchColumn(), [true, 1, '1', 't'], true)) self::readInvalid();
            $ledger = $pdo->prepare('SELECT count(*) FROM sand_ai_schema_migration WHERE version=:version AND checksum=:checksum');
            $ledger->execute(['version' => '202610020102_meeting_token_count_ledger', 'checksum' => 'a938f402a8c421bc2f769182a65482064d225da62c44ee6625f4fab2bc187529']);
            if ((int) $ledger->fetchColumn() !== 1) self::readInvalid();
            return $pdo;
        } catch (\PDOException) {
            self::readInvalid();
        }
    }

    /** @return array<string,int|string> */
    private function scope(PDO $pdo, bool $lock): array
    {
        $query = $pdo->prepare('SELECT * FROM sand_ai_task_run WHERE id=:id AND state=\'running\' AND lease_owner=:owner'
            . ' AND lease_token=:token AND lease_fence=:fence AND lease_expire_time>(clock_timestamp() AT TIME ZONE :trusted_app_timezone)'
            . ' AND content_purged_time IS NULL' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute(['id' => $this->taskId, 'owner' => $this->lease->owner, 'token' => $this->lease->token, 'fence' => $this->lease->fence, 'trusted_app_timezone' => TaskLeasePolicy::runningTimezone()]);
        $task = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($task)) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting tokenizer task lease changed');
        // FOR UPDATE may wait beyond expiry; recheck with the wall clock after the lock.
        $this->assertCurrentLease($pdo);
        $input = json_decode($task['input'], true, 64, JSON_THROW_ON_ERROR);
        $source = $input['_meeting_summary'] ?? null;
        if (!is_array($source) || ($source['planner_version'] ?? null) !== 'meeting-summary-v3'
            || !is_array($input['_meeting_capacity'] ?? null) || !is_array($input['_meeting_route'] ?? null)
            || !hash_equals(RequestPayloadFingerprint::fingerprint($this->capacitySnapshot, []), RequestPayloadFingerprint::fingerprint($input['_meeting_capacity'], []))
            || !hash_equals(RequestPayloadFingerprint::fingerprint($this->capacitySnapshot['route'], []), RequestPayloadFingerprint::fingerprint($input['_meeting_route'], []))) $this->invalid();
        $attribution = UsageAttribution::fromCurrentPersisted($input['_usage_attribution'] ?? null, (int) $task['environment_id'], 'ai_task', 'sand_ai.task.read');
        if ($attribution->organizationId === null || $attribution->applicationId === null || $attribution->workloadClientId === null) $this->invalid();
        $transcript = $source['sha256'] ?? null;
        if (!is_string($transcript)) $this->invalid();
        $this->digest($transcript);
        if (!is_int($source['audio_task_id'] ?? null) || $source['audio_task_id'] < 1 || !is_int($source['review_revision'] ?? null) || $source['review_revision'] < 0) $this->invalid();
        $audio = $pdo->prepare('SELECT count(*) FROM sand_ai_task_run WHERE id=:id AND environment_id=:environment AND content_purged_time IS NULL');
        $audio->execute(['id' => $source['audio_task_id'], 'environment' => (int) $task['environment_id']]);
        if ((int) $audio->fetchColumn() !== 1) $this->invalid();
        $route = $this->capacitySnapshot['route'];
        return ['task_id' => $this->taskId, 'audio_task_id' => $source['audio_task_id'], 'environment_id' => (int) $task['environment_id'],
            'organization_id' => $attribution->organizationId, 'application_id' => $attribution->applicationId,
            'workload_client_id' => $attribution->workloadClientId, 'owner_scope_sha256' => RequestPayloadFingerprint::fingerprint($input['_usage_attribution'], []),
            'capacity_sha256' => $this->capacitySnapshot['snapshot_sha256'], 'route_sha256' => $route['snapshot_sha256'],
            'provider_id' => $route['provider_id'], 'model_id' => $route['model_id'], 'deployment_id' => $route['deployment_id'],
            'deployment_revision' => $route['deployment_revision'], 'remote_model' => $route['deployment_remote_model'],
            'transcript_sha256' => $transcript, 'review_revision' => $source['review_revision'], 'planner_version' => 'meeting-summary-v3'];
    }

    private function assertCurrentLease(PDO $pdo): void
    {
        // Compare unrounded wall time in the trusted PHP application timezone, independent of the PG session.
        $query = $pdo->prepare('SELECT count(*) FROM sand_ai_task_run WHERE id=:id AND state=\'running\''
            . ' AND lease_owner=:owner AND lease_token=:token AND lease_fence=:fence'
            . ' AND lease_expire_time>(clock_timestamp() AT TIME ZONE :trusted_app_timezone) AND content_purged_time IS NULL');
        $query->execute(['id' => $this->taskId, 'owner' => $this->lease->owner,
            'token' => $this->lease->token, 'fence' => $this->lease->fence, 'trusted_app_timezone' => TaskLeasePolicy::runningTimezone()]);
        if ((int) $query->fetchColumn() !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '会议分词任务租约已过期或变更，未授权新的分词请求');
        }
    }

    private function count(PDO $pdo): int
    {
        // Durable enrollment initializes this exact append-only request projection.
        // No repeated O(N) aggregate while preparing thousands of leaf candidates.
        $ready = $pdo->query("SELECT to_regclass('sand_ai_meeting_plan_tokenizer_counter') IS NOT NULL");
        if ($ready !== false && in_array($ready->fetchColumn(), [true, 1, '1', 't'], true)) {
            $counter = $pdo->prepare("SELECT c.request_count,(SELECT count(*) FROM (VALUES ('sand_ai_meeting_plan_tokenizer_counter','sand_ai_meeting_plan_tokenizer_counter_guard'),('sand_ai_meeting_plan_checkpoint','sand_ai_meeting_plan_tokenizer_counter_init'),('sand_ai_meeting_token_count_request','sand_ai_meeting_plan_tokenizer_counter_add')) expected(relation_name,trigger_name) JOIN pg_trigger t ON t.tgrelid=to_regclass(expected.relation_name) AND t.tgname=expected.trigger_name WHERE NOT t.tgisinternal AND t.tgenabled IN ('O','A')) AS proof_count FROM sand_ai_meeting_plan_tokenizer_counter c WHERE c.task_id=:task");
            $counter->execute(['task' => $this->taskId]);
            $projection = $counter->fetch(PDO::FETCH_ASSOC);
            if (is_array($projection)) {
                if ((int) $projection['proof_count'] !== 3) $this->invalid();
                $value = (int) $projection['request_count'];
                if ($value < 0 || $value > $this->capacitySnapshot['max_tokenizer_requests']) $this->invalid();
                return $value;
            }
        }
        $query = $pdo->prepare('SELECT count(*) FROM ' . self::REQUESTS . ' WHERE task_id=:task');
        $query->execute(['task' => $this->taskId]);
        return (int) $query->fetchColumn();
    }

    private function request(PDO $pdo, string $input): ?array
    {
        $query = $pdo->prepare('SELECT * FROM ' . self::REQUESTS . ' WHERE task_id=:task AND input_sha256=:input AND capacity_sha256=:capacity');
        $query->execute(['task' => $this->taskId, 'input' => $input, 'capacity' => $this->capacitySnapshot['snapshot_sha256']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function receipt(PDO $pdo, array $request, array $scope): array
    {
        foreach ($scope as $key => $value) if ((string) $request[$key] !== (string) $value) $this->invalid();
        $query = $pdo->prepare('SELECT * FROM ' . self::OUTCOMES . ' WHERE request_event_id=:id');
        $query->execute(['id' => $request['id']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['state'] !== 'succeeded') {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_RECOVERY_REQUIRED', '已有分词请求的结果未知或失败，禁止原键自动重发；请保留调用与费用事实，核对官方请求状态，确认后再决定是否用新请求提交');
        }
        $receipt = json_decode($row['capacity_receipt'], true, 16, JSON_THROW_ON_ERROR);
        $facts = $this->outcome($request, ['state' => 'succeeded', 'http_status' => (int) $row['http_status'], 'capacity_receipt' => $receipt]);
        if ((int) $row['prompt_tokens'] !== $facts['prompt_tokens'] || !hash_equals($row['outcome_sha256'], RequestPayloadFingerprint::fingerprint($facts, []))) $this->invalid();
        return $receipt;
    }

    private function outcome(array $request, array $outcome): array
    {
        $state = $outcome['state'] ?? null;
        $http = $outcome['http_status'] ?? null;
        if (!in_array($state, ['succeeded', 'rejected', 'ambiguous'], true) || ($http !== null && (!is_int($http) || $http < 100 || $http > 599))) $this->invalid();
        $receipt = null;
        $tokens = null;
        if ($state === 'succeeded') {
            $receipt = $outcome['capacity_receipt'] ?? null;
            $keys = ['counter','remote_model','input_sha256','prompt_tokens','request_id','provider_request_id','pricing_state','external_request_event_id'];
            if (!is_array($receipt) || array_diff(array_keys($receipt), $keys) !== [] || array_diff($keys, array_keys($receipt)) !== []
                || $http !== 200 || $receipt['counter'] !== 'bigmodel_tokenizer@1' || $receipt['remote_model'] !== $request['remote_model']
                || $receipt['input_sha256'] !== $request['input_sha256'] || $receipt['request_id'] !== $request['request_id']
                || $receipt['pricing_state'] !== 'unpriced' || $receipt['external_request_event_id'] !== (int) $request['id']
                || !is_string($receipt['provider_request_id']) || preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/', $receipt['provider_request_id']) !== 1
                || !is_int($receipt['prompt_tokens']) || $receipt['prompt_tokens'] < 1 || $receipt['prompt_tokens'] > 1000000) $this->invalid();
            $tokens = $receipt['prompt_tokens'];
        } elseif (isset($outcome['capacity_receipt'])) $this->invalid();
        return ['state' => $state, 'http_status' => $http, 'prompt_tokens' => $tokens, 'capacity_receipt' => $receipt];
    }

    private function digest(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) $this->invalid();
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_AUDIT_UNAVAILABLE', 'Meeting tokenizer evidence is unavailable or inconsistent');
    }
}
