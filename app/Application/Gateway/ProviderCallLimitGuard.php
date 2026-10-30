<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\Invocation;
use think\facade\Db;

/** Trusted, immutable caller-window limits; never accepts a pool from API input. */
final class ProviderCallLimitGuard
{
    /** Called inside the existing prepared -> dispatching transaction. */
    public function claim(Invocation $invocation, int $dispatchId): void
    {
        $caller = (int) $invocation->workload_client_id;
        if ($caller < 1) {
            return;
        }
        self::lockCaller($caller);
        $db = Db::connect('pgsql');
        // Look up the caller first: a mismatching scope must not become unbound.
        $binding = $db->table('sand_ai_provider_call_limit_binding')
            ->where('workload_client_id', $caller)->find();
        if ($binding === null) {
            return;
        }
        foreach (['organization_id', 'application_id', 'environment_id'] as $field) {
            if ((int) $binding[$field] !== (int) $invocation->getAttr($field)) {
                throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_SCOPE_INVALID', '调用方的共享调用次数限制归属不匹配，未发送模型请求');
            }
        }
        $pool = $db->table('sand_ai_provider_call_limit_pool')
            ->where('id', (int) $binding['pool_id'])->lock(true)->find();
        if ($pool === null || (string) $pool['state'] !== 'active'
            || (string) $pool['start_time'] > gmdate('Y-m-d H:i:s')
            || (string) $pool['expire_time'] <= gmdate('Y-m-d H:i:s')) {
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_CLOSED', '共享调用次数窗口未开放、已关闭或存在未知调用，请查看原调用；未发送新请求');
        }
        $claims = $db->table('sand_ai_provider_call_limit_claim');
        if ($claims->where('dispatch_id', $dispatchId)->find() !== null) {
            // A claim is evidence of the original send permission, never permission
            // to send again. Existing receipt recovery happens before this method.
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_ALREADY_CLAIMED', '原派发已领取调用次数，请按原请求读取结果，不得再次发送');
        }
        // Do not lock another dispatch here: its worker can be waiting for this
        // pool while holding that dispatch row. The pool serializes all claims.
        $unresolved = $db->table('sand_ai_provider_call_limit_claim')->alias('c')
            ->join('sand_ai_invocation_dispatch d', 'd.id = c.dispatch_id')
            ->where('c.pool_id', (int) $pool['id'])
            ->whereNotIn('d.state', ['succeeded', 'failed', 'not_dispatched'])->find();
        if ($unresolved !== null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_PENDING', '共享窗口存在未完成或结果不明的派发，请先查看原请求；未发送新请求');
        }
        $used = $db->table('sand_ai_provider_call_limit_claim')
            ->where('pool_id', (int) $pool['id'])->count();
        if ($used >= (int) $pool['max_calls']) {
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_EXHAUSTED', '共享窗口的模型调用次数已用尽，未发送新请求');
        }
        $db->table('sand_ai_provider_call_limit_claim')->insert([
            'pool_id' => (int) $pool['id'],
            'dispatch_id' => $dispatchId,
            'create_time' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Also used by the trusted CLI so first binding cannot race an unbound send. */
    public static function lockCaller(int $caller): void
    {
        $db = Db::connect('pgsql');
        $pdo = $db instanceof \think\db\PDOConnection ? $db->getPdo() : false;
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_PROVIDER_CALL_LIMIT_TRANSACTION_REQUIRED', '调用次数领取必须处于派发事务内');
        }
        $db->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'sand_ai_provider_call_limit:' . $caller,
        ]);
    }
}
