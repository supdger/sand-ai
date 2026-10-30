<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use think\facade\Db;

final class PostgresProviderBillingSyncAuditStore implements ProviderBillingSyncAuditStore
{
    public function recordNoData(int $adminId, array $request): void
    {
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        Db::connect('pgsql')->transaction(static function ($connection) use ($adminId, $request): void {
            $connection->query(
                'INSERT INTO sand_ai_audit_log (actor_type, actor_ref, action, resource_type, resource_id, summary, context) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
                [
                    'admin',
                    (string) $adminId,
                    'billing.sync.no_data',
                    'provider',
                    (int) $request['provider_id'],
                    'Provider billing sync returned no billable rows',
                    json_encode([
                        'provider_id' => (int) $request['provider_id'],
                        'period_start' => (string) $request['period_start'],
                        'period_end' => (string) $request['period_end'],
                        'request_key' => (string) $request['idempotency_key'],
                        'input_sha256' => BillingOperationRecovery::inputSha(BillingOperationRecovery::syncInput($request)),
                    ], JSON_THROW_ON_ERROR),
                ],
            );
        });
    }
}
