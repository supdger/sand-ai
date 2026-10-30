<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use think\db\ConnectionInterface;
use think\db\PDOConnection;
use think\facade\Db;

/** Nonfinancial proof of an original local rejection or a claimed official sync. */
final class BillingOperationReceipt
{
    private const LOCAL_REJECTIONS = [
        'SAND_AI_BILLING_VALIDATION_ERROR', 'SAND_AI_BILLING_REVIEW_INVALID',
        'SAND_AI_BILLING_EVIDENCE_UNAVAILABLE', 'SAND_AI_BILLING_REVIEW_FINAL',
        'SAND_AI_RESOURCE_NOT_FOUND', 'SAND_AI_INVOCATION_FACTS_UNVERIFIED',
        'SAND_AI_BILLING_CANDIDATE_LIMIT_EXCEEDED', 'SAND_AI_BILLING_FACT_CONFLICT',
        'SAND_AI_BILLING_CONNECTOR_UNAVAILABLE', 'SAND_AI_BILLING_PROVIDER_UNSUPPORTED',
    ];

    /** The original financial callback is local PostgreSQL work, with no provider dispatch. */
    public function local(string $operation, array $input, int $adminId, callable $callback): array
    {
        $meta = $this->meta($operation, $input, $adminId);
        if ($meta === null) return $callback();
        $connection = Db::connect('pgsql');
        if (!$connection instanceof PDOConnection) $this->unknown();
        $prior = $connection->getPdo();
        if ($prior instanceof \PDO && $prior->inTransaction()) $this->unknown();
        $connection->startTrans();
        $pdo = $connection->getPdo();
        $committing = false;
        try {
            $this->lock($connection, $meta);
            $this->assertAvailable($this->rows($connection, $meta), $meta);
            $result = $callback();
            $committing = true;
            $connection->commit();
            return $result;
        } catch (\Throwable $error) {
            $rolledBack = false;
            try {
                $connection->rollback();
                $rolledBack = $pdo instanceof \PDO && $pdo === $connection->getPdo() && !$pdo->inTransaction();
            } catch (\Throwable) {
                // A lost/uncertain rollback cannot establish a rejected operation.
            }
            if (!$committing && $rolledBack && $error instanceof ApiProblem && $this->eligible($error)) {
                $this->recordRejected($meta, $error);
            }
            throw $error;
        }
    }

    /** Short transactions claim before fetch; no PostgreSQL transaction spans provider I/O. */
    public function sync(array $input, int $adminId, callable $callback): array
    {
        $meta = $this->meta('sync', $input, $adminId);
        if ($meta === null) return $callback(static function (): void {});
        $original = (new BillingOperationRecovery())->read('sync', [
            'provider_id' => $meta['target_id'], 'request_key' => $meta['request_key'], 'input_sha256' => $meta['input_sha256'],
        ], $adminId);
        if ($original['state'] === 'completed') return $original['sync_status'] === 'no_data'
            ? ['status' => 'no_data'] : ['idempotent_replay' => true, 'billing_import' => $original['billing_import']];
        Db::connect('pgsql')->transaction(function ($connection) use ($meta): void {
            $this->lock($connection, $meta);
            $this->assertAvailable($this->rows($connection, $meta), $meta);
        });
        try {
            // Pure input validation is known to have no financial/provider side effect.
            (new BillingFetchRequestPolicy())->normalize($input);
        } catch (ApiProblem $error) {
            if ($this->eligible($error)) $this->recordRejected($meta, $error);
            throw $error;
        }
        $token = bin2hex(random_bytes(32));
        $claim = Db::connect('pgsql')->transaction(function ($connection) use ($meta, $token): array {
            $this->lock($connection, $meta);
            $this->assertAvailable($this->rows($connection, $meta), $meta);
            if ($this->successExists($connection, $meta)) $this->unknown();
            $rows = $connection->query(
                "INSERT INTO sand_ai_billing_operation_receipt (admin_id,operation,target_id,request_key,input_sha256,state,claim_token) VALUES (?,?,?,?,?,'prepared',?) RETURNING id",
                [$meta['admin_id'], 'sync', $meta['target_id'], $meta['request_key'], $meta['input_sha256'], $token],
            );
            return ['id' => (int) $rows[0]['id'], 'token' => $token];
        });
        $started = false;
        $start = function () use ($meta, $claim, &$started): void {
            Db::connect('pgsql')->transaction(function ($connection) use ($meta, $claim): void {
                $this->lock($connection, $meta);
                $rows = $this->rows($connection, $meta);
                if (count($rows) !== 1 || (int) $rows[0]['id'] !== $claim['id']
                    || $rows[0]['state'] !== 'prepared' || !hash_equals((string) $rows[0]['claim_token'], $claim['token'])) $this->unknown();
                if ($this->successExists($connection, $meta)) $this->unknown();
                if ($connection->execute("UPDATE sand_ai_billing_operation_receipt SET state='started',update_time=CURRENT_TIMESTAMP WHERE id=? AND state='prepared' AND claim_token=?",
                    [$claim['id'], $claim['token']]) !== 1) $this->unknown();
            });
            $started = true;
        };
        try {
            return $callback($start);
        } catch (ApiProblem $error) {
            if (!$started && $this->eligible($error)) $this->recordRejected($meta, $error, $claim);
            throw $error;
        }
    }

    /** Current actor/key/body only. Prepared/started are unknown, never synthetic completion. */
    public function rejected(string $operation, int $target, string $key, string $digest, int $adminId): ?array
    {
        $meta = ['operation' => $operation, 'target_id' => $target, 'request_key' => $key,
            'input_sha256' => $digest, 'admin_id' => $adminId];
        $rows = $this->rows(Db::connect('pgsql'), $meta);
        foreach ($rows as $row) {
            if ((int) $row['admin_id'] !== $adminId || $row['operation'] !== $operation) continue;
            if (!hash_equals((string) $row['input_sha256'], $digest)) $this->conflict();
            if ($row['state'] !== 'rejected') return null;
            // A contradictory successful original record must never be hidden by rejection.
            if ($this->successExists(Db::connect('pgsql'), $meta)) return null;
            if (!is_string($row['error_code']) || !in_array($row['error_code'], self::LOCAL_REJECTIONS, true)) return null;
            return ['error_code' => $row['error_code']];
        }
        return null;
    }

    private function recordRejected(array $meta, ApiProblem $error, ?array $claim = null): void
    {
        try {
            Db::connect('pgsql')->transaction(function ($connection) use ($meta, $error, $claim): void {
                $this->lock($connection, $meta);
                // Includes a committed import or no_data owned by another actor, without revealing it.
                if ($this->successExists($connection, $meta)) return;
                $rows = $this->rows($connection, $meta);
                if ($claim !== null) {
                    if (count($rows) !== 1 || (int) $rows[0]['id'] !== $claim['id'] || $rows[0]['state'] !== 'prepared'
                        || !hash_equals((string) $rows[0]['claim_token'], $claim['token'])
                        || !hash_equals((string) $rows[0]['input_sha256'], $meta['input_sha256'])) return;
                    $connection->execute("UPDATE sand_ai_billing_operation_receipt SET state='rejected',error_code=?,update_time=CURRENT_TIMESTAMP WHERE id=? AND state='prepared' AND claim_token=?",
                        [$error->errorCode, $claim['id'], $claim['token']]);
                    return;
                }
                // Any claim/rejection from another actor/body, including an in-flight fetch, retains unknown.
                if ($rows !== []) return;
                $connection->query("INSERT INTO sand_ai_billing_operation_receipt (admin_id,operation,target_id,request_key,input_sha256,state,error_code) VALUES (?,?,?,?,?,'rejected',?)",
                    [$meta['admin_id'], $meta['operation'], $meta['target_id'], $meta['request_key'], $meta['input_sha256'], $error->errorCode]);
            });
        } catch (\Throwable) {
            // Persistence uncertainty is exposed by the original GET as unknown; never invent proof.
        }
    }

    private function assertAvailable(array $rows, array $meta): void
    {
        if ($rows === []) return;
        if (count($rows) === 1 && $rows[0]['state'] === 'rejected'
            && (int) $rows[0]['admin_id'] === $meta['admin_id'] && $rows[0]['operation'] === $meta['operation']
            && hash_equals((string) $rows[0]['input_sha256'], $meta['input_sha256'])) {
            throw new ApiProblem('SAND_AI_BILLING_OPERATION_REJECTED', '原请求已明确拒绝，请先读取并确认拒绝回执，再修改输入重新提交。');
        }
        if (count($rows) === 1 && !hash_equals((string) $rows[0]['input_sha256'], $meta['input_sha256'])) $this->conflict();
        $this->unknown();
    }

    private function lock(ConnectionInterface $connection, array $meta): void
    {
        if ($meta['operation'] === 'review') {
            $connection->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['sand_ai:billing_review:' . $meta['admin_id'] . ':' . $meta['target_id'] . ':' . $meta['request_key']]);
        } else {
            $connection->query(BillingImportAdvisoryLock::sql(), BillingImportAdvisoryLock::parameters($meta['target_id'], $meta['request_key']));
        }
    }

    private function rows(ConnectionInterface $connection, array $meta): array
    {
        if ($meta['operation'] === 'review') return $connection->query(
            "SELECT * FROM sand_ai_billing_operation_receipt WHERE operation='review' AND admin_id=? AND target_id=? AND request_key=? ORDER BY id LIMIT 2",
            [$meta['admin_id'], $meta['target_id'], $meta['request_key']],
        );
        return $connection->query(
            "SELECT * FROM sand_ai_billing_operation_receipt WHERE operation IN ('import','sync') AND target_id=? AND request_key=? ORDER BY id LIMIT 2",
            [$meta['target_id'], $meta['request_key']],
        );
    }

    private function successExists(ConnectionInterface $connection, array $meta): bool
    {
        if ($meta['operation'] === 'review') return $connection->table('sand_ai_billing_review')
            ->where('admin_id', $meta['admin_id'])->where('billing_line_id', $meta['target_id'])->where('idempotency_key', $meta['request_key'])->find() !== null;
        if ($connection->table('sand_ai_billing_import')->where('provider_id', $meta['target_id'])->where('idempotency_key', $meta['request_key'])->find() !== null) return true;
        return $connection->query("SELECT id FROM sand_ai_audit_log WHERE actor_type='admin' AND action='billing.sync.no_data' AND resource_type='provider' AND resource_id=? AND context->>'request_key'=? LIMIT 1",
            [$meta['target_id'], $meta['request_key']]) !== [];
    }

    private function meta(string $operation, array $input, int $adminId): ?array
    {
        if ($adminId < 1 || !in_array($operation, ['import', 'sync', 'review'], true)) return null;
        $key = $input['idempotency_key'] ?? null;
        if (!is_string($key) || trim($key) !== $key || $key === '' || strlen($key) > 128
            || preg_match('//u', $key) !== 1 || preg_match('/[\p{Cc}]/u', $key) === 1) return null;
        try {
            $target = BillingPositiveId::parse($input[$operation === 'review' ? 'billing_line_id' : 'provider_id'] ?? null, 'target', 'SAND_AI_BILLING_RECOVERY_INVALID');
        } catch (ApiProblem) { return null; }
        return ['operation' => $operation, 'admin_id' => $adminId, 'target_id' => $target,
            'request_key' => $key, 'input_sha256' => BillingOperationRecovery::inputSha($input)];
    }

    private function eligible(ApiProblem $error): bool { return in_array($error->errorCode, self::LOCAL_REJECTIONS, true); }
    private function unknown(): never { throw new ApiProblem('SAND_AI_BILLING_OPERATION_UNKNOWN', '原账单请求尚未确认，请保留原号读取结果；不会重新获取或提交。'); }
    private function conflict(): never { throw new ApiProblem('SAND_AI_BILLING_RECOVERY_CONFLICT', '原账单请求号已关联不同输入，请保留原号查询。'); }
}
