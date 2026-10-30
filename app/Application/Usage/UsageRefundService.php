<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingEvidenceService;
use app\Application\Budget\BudgetDecimal;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\InvocationReadFacts;
use app\Infrastructure\Persistence\Model\UsageEvent;
use think\facade\Db;

/** Appends an operator-reviewed refund; original usage and bill facts never change. */
final class UsageRefundService
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function refund(array $input, int $adminId): array
    {
        if ($adminId < 1) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', '需要已登录的平台管理员');
        }
        $policy = new UsageRefundPolicy();
        $data = $policy->normalize($input);
        $fingerprint = $policy->fingerprint($data);
        return Db::connect('pgsql')->transaction(function () use ($data, $fingerprint, $adminId): array {
            $isolation = Db::connect('pgsql')->query('SHOW transaction_isolation');
            if (($isolation[0]['transaction_isolation'] ?? null) !== 'read committed') {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_ISOLATION_UNSUPPORTED', '退款累计核验需要 PostgreSQL READ COMMITTED 事务');
            }
            // Every key/admin/refund of this event takes the same lock. It is
            // also acquired by the INSERT trigger to serialize direct writers.
            $event = $this->event($data['usage_event_id'], $data['environment_id'], true);
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                'sand_ai:usage_refund_reference:' . (string) $event['provider_id'] . ':' . $data['provider_refund_reference'],
            ]);
            $existing = Db::connect('pgsql')->table('sand_ai_usage_adjustment')
                ->where('usage_event_id', $data['usage_event_id'])->where('environment_id', $data['environment_id'])
                ->where('idempotency_key', $data['idempotency_key'])->find();
            if (is_array($existing)) {
                if (!hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_CONFLICT', '原请求键已关联不同退款内容，请读取原调整记录');
                }
                // A later evidence clear never invalidates an already recorded
                // refund or permits another refund under the original key.
                return ['idempotent_replay' => true, 'adjustment' => $this->dto($existing),
                    'totals' => $this->totals($event)];
            }
            $bill = $this->reconciledBill($event);
            if ($bill === null) {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_BILL_UNAVAILABLE', '原调用尚无可信已核账金额，暂不能确定退款上限');
            }
            if ($data['currency'] !== $bill['currency']) {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_CURRENCY_MISMATCH', '退款币种必须与原已核账金额一致');
            }
            $evidenceRows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_billing_evidence WHERE id=? FOR UPDATE', [$data['evidence_id']],
            );
            $evidence = $evidenceRows[0] ?? null;
            if (!is_array($evidence) || (string) $evidence['provider_id'] !== (string) $event['provider_id']
                || (new BillingEvidenceService())->availability($evidence) !== 'available') {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE', '退款凭证须属于原服务商且私有原件完整可读取');
            }
            $reference = Db::connect('pgsql')->table('sand_ai_usage_adjustment')
                ->where('provider_id', $event['provider_id'])
                ->where('provider_refund_reference', $data['provider_refund_reference'])->find();
            if ($reference !== null) {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_CONFLICT', '该服务商退款凭证引用已记录，请读取原调整记录');
            }
            $refunded = $this->refunded((int) $event['id']);
            try {
                $combined = BudgetDecimal::add($refunded, $data['refund_amount']);
            } catch (\InvalidArgumentException) {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_EXCEEDS_BILL', '累计退款金额超过原已核账金额');
            }
            if (BudgetDecimal::compare($combined, (string) $bill['billed_amount']) > 0) {
                throw new ApiProblem('SAND_AI_USAGE_ADJUSTMENT_EXCEEDS_BILL', '累计退款金额不得超过原已核账金额');
            }
            $rows = Db::connect('pgsql')->query(
                "INSERT INTO sand_ai_usage_adjustment
                 (usage_event_id,reconciliation_id,organization_id,application_id,environment_id,workload_client_id,
                  provider_id,adjustment_kind,amount_basis,original_billed_amount,refund_amount,currency,
                  evidence_id,evidence_sha256,evidence_locator,provider_refund_reference,trust_basis,
                  provider_refund_confirmed,admin_id,idempotency_key,fingerprint,note,create_time)
                 VALUES (?,?,?,?,?,?,?,'refund','reconciled_bill',CAST(? AS numeric(20,6)),
                         CAST(? AS numeric(20,6)),?,?,?,?,?,'operator_reviewed',true,?,?,?,?,?)
                 RETURNING *",
                [(int) $event['id'], (int) $bill['id'], $event['organization_id'], $event['application_id'],
                    $event['environment_id'], $event['workload_client_id'], $event['provider_id'],
                    ...NumericSql::binds((string) $bill['billed_amount'], $data['refund_amount']),
                    $data['currency'], $data['evidence_id'], $evidence['sha256'], $data['evidence_locator'],
                    $data['provider_refund_reference'], $adminId, $data['idempotency_key'], $fingerprint,
                    $data['note'], gmdate('Y-m-d H:i:s')],
            );
            $row = $rows[0];
            $audit = [
                'usage_event_id' => (int) $event['id'], 'reconciliation_id' => (int) $bill['id'],
                'organization_id' => (int) $event['organization_id'], 'application_id' => (int) $event['application_id'],
                'environment_id' => (int) $event['environment_id'], 'workload_client_id' => (int) $event['workload_client_id'],
                'provider_id' => (int) $event['provider_id'], 'refund_amount' => $data['refund_amount'],
                'currency' => $data['currency'], 'amount_basis' => 'reconciled_bill', 'trust_basis' => 'operator_reviewed',
                'evidence_id' => $data['evidence_id'], 'evidence_sha256' => $evidence['sha256'],
                'provider_refund_reference' => $data['provider_refund_reference'],
            ];
            Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_audit_log (actor_type,actor_ref,action,resource_type,resource_id,summary,context,create_time)
                 VALUES (?,?,?,?,?,?,CAST(? AS jsonb),?)',
                ['admin', (string) $adminId, 'usage.refund.record', 'usage_adjustment', (int) $row['id'],
                    '人工审核退款凭证并记录调整', json_encode($audit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    gmdate('Y-m-d H:i:s')],
            );
            return ['idempotent_replay' => false, 'adjustment' => $this->dto($row), 'totals' => $this->totals($event)];
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function index(array $input): array
    {
        $query = (new UsageRefundPolicy())->listQuery($input);
        if ($query['page'] - 1 > intdiv(PHP_INT_MAX, $query['limit'])) {
            throw new ApiProblem(UsageRefundPolicy::INVALID, '页码超出支持范围');
        }
        $event = $this->event($query['usage_event_id'], $query['environment_id']);
        $filter = Db::connect('pgsql')->table('sand_ai_usage_adjustment')
            ->where('usage_event_id', $query['usage_event_id'])->where('environment_id', $query['environment_id']);
        $count = (int) (clone $filter)->count();
        $rows = $filter->order('id', 'desc')->limit(($query['page'] - 1) * $query['limit'], $query['limit'])->select()->toArray();
        return ['total' => $count, 'per_page' => $query['limit'], 'current_page' => $query['page'],
            'data' => array_map($this->dto(...), $rows), 'totals' => $this->totals($event)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function read(array $input): array
    {
        $query = (new UsageRefundPolicy())->readQuery($input);
        $row = Db::connect('pgsql')->table('sand_ai_usage_adjustment')
            ->where('id', $query['id'])->where('environment_id', $query['environment_id'])->find();
        if (!is_array($row)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '退款调整记录不存在');
        }
        $event = $this->event((int) $row['usage_event_id'], $query['environment_id']);
        return ['adjustment' => $this->dto($row), 'totals' => $this->totals($event)];
    }

    /** Safe summary for an already resource-authorized cost read. @return array<string,mixed> */
    public function summaryForEvent(int $eventId, int $environmentId): array
    {
        return $this->totals($this->event($eventId, $environmentId));
    }

    /**
     * Uses the billing facts and refund aggregate from the cost event's single
     * SELECT. Re-reading either here could mix two concurrent billing states.
     * Current invocation attribution is still resolved before returning totals.
     *
     * @return array<string,mixed>
     */
    public function summaryForCostEvent(UsageEvent $usageEvent): array
    {
        $event = $usageEvent->getData();
        $this->assertEventFacts($event);
        foreach (['billing_reconciliation_id', 'billing_reconciliation_state', 'billing_reconciliation_currency',
            'billing_reconciliation_quantity_difference', 'billing_reconciliation_difference_amount',
            'billing_reconciliation_billed_amount', 'billing_line_amount', 'billing_line_currency',
            'billing_provider_id', 'billing_adjustment_count', 'billing_refund_amount'] as $field) {
            if (!array_key_exists($field, $event)) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '费用核账与退款快照不完整');
            }
        }
        $bill = $this->validatedBill($event, [
            'id' => $event['billing_reconciliation_id'],
            'state' => $event['billing_reconciliation_state'],
            'currency' => $event['billing_reconciliation_currency'],
            'quantity_difference' => $event['billing_reconciliation_quantity_difference'],
            'amount_difference' => $event['billing_reconciliation_difference_amount'],
            'billed_amount' => $event['billing_reconciliation_billed_amount'],
            'line_amount' => $event['billing_line_amount'],
            'line_currency' => $event['billing_line_currency'],
            'billing_provider_id' => $event['billing_provider_id'],
        ]);
        return $this->totalsFromFacts($bill, (int) $event['billing_adjustment_count'],
            (string) $event['billing_refund_amount']);
    }

    /** @return array<string,mixed> */
    private function event(int $id, int $environmentId, bool $lock = false): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_usage_event WHERE id=? AND environment_id=?' . ($lock ? ' FOR UPDATE' : ''),
            [$id, $environmentId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '用量事件不存在');
        }
        $event = $rows[0];
        $this->assertEventFacts($event);
        return $event;
    }

    /** @param array<string,mixed> $event */
    private function assertEventFacts(array $event): void
    {
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            (new PricingValuePolicy())->positiveId($event[$field] ?? null, $field, 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        }
        if ($event['provider_id'] !== null) {
            (new PricingValuePolicy())->positiveId($event['provider_id'], 'provider_id', 'SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        }
        if ($event['invocation_id'] !== null) {
            try {
                $facts = (new InvocationReadFacts())->resolve('sand_ai', 'sand_ai.usage.read', (string) $event['invocation_id']);
            } catch (IdentityContextException) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '原调用归属无法核实');
            }
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ((string) $event[$field] !== (string) $facts[$field]) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '用量归属与原调用不一致');
                }
            }
        }
        // Non-token usage carries the same immutable trusted identity snapshot,
        // with its task/speech/OCR anchor already enforced by the event schema.
        $identity = is_string($event['identity_snapshot'])
            ? json_decode($event['identity_snapshot'], true, 32, JSON_THROW_ON_ERROR) : $event['identity_snapshot'];
        if (!is_array($identity)) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '用量身份快照无法核实');
        }
        foreach (['organization_ref' => 'organization_id', 'application_ref' => 'application_id',
            'workload_client_ref' => 'workload_client_id'] as $reference => $field) {
            if (($identity[$reference] ?? null) !== (string) $event[$field]) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', '用量身份快照与归属不一致');
            }
        }
    }

    /** @param array<string,mixed> $event @return array<string,mixed>|null */
    private function reconciledBill(array $event): ?array
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT reconciliation.*, line.amount AS line_amount, line.currency AS line_currency, imports.provider_id AS billing_provider_id
             FROM sand_ai_usage_reconciliation reconciliation
             JOIN sand_ai_billing_line line ON line.id=reconciliation.billing_line_id
             JOIN sand_ai_billing_import imports ON imports.id=line.billing_import_id
             WHERE reconciliation.usage_event_id=? AND reconciliation.state='reconciled'",
            [(int) $event['id']],
        );
        return $this->validatedBill($event, count($rows) === 1 ? $rows[0] : null);
    }

    /** @param array<string,mixed> $event @param array<string,mixed>|null $bill @return array<string,mixed>|null */
    private function validatedBill(array $event, ?array $bill): ?array
    {
        if ($bill === null || $bill['state'] !== 'reconciled'
            || $event['pricing_state'] !== 'priced' || $event['currency'] === null) {
            return null;
        }
        if ((string) $bill['billing_provider_id'] !== (string) $event['provider_id']
            || $bill['currency'] !== $event['currency'] || $bill['line_currency'] !== $bill['currency']
            || $bill['quantity_difference'] === null || $bill['amount_difference'] === null
            || BudgetDecimal::compare((string) $bill['quantity_difference'], '0.000000') !== 0
            || BudgetDecimal::compare((string) $bill['amount_difference'], '0.000000') !== 0
            || BudgetDecimal::compare((string) $bill['billed_amount'], (string) $bill['line_amount']) !== 0) {
            return null;
        }
        return $bill;
    }

    private function refunded(int $eventId): string
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT COALESCE(SUM(refund_amount),0)::text AS amount FROM sand_ai_usage_adjustment WHERE usage_event_id=?',
            [$eventId],
        );
        return BudgetDecimal::normalize((string) $rows[0]['amount'], false);
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function totals(array $event): array
    {
        $bill = $this->reconciledBill($event);
        // One statement observes count and amount from the same snapshot.
        $aggregates = Db::connect('pgsql')->query(
            'SELECT COUNT(*) AS count, COALESCE(SUM(refund_amount),0)::text AS amount
             FROM sand_ai_usage_adjustment WHERE usage_event_id=?', [(int) $event['id']],
        );
        return $this->totalsFromFacts($bill, (int) $aggregates[0]['count'], (string) $aggregates[0]['amount']);
    }

    /** @param array<string,mixed>|null $bill @return array<string,mixed> */
    private function totalsFromFacts(?array $bill, int $count, string $refundAmount): array
    {
        if ($bill === null) {
            return ['state' => 'bill_unavailable', 'amount_basis' => 'reconciled_bill', 'trust_basis' => 'operator_reviewed',
                'adjustment_count' => $count, 'gross_billed_amount' => null, 'refund_amount' => null,
                'net_billed_amount' => null, 'currency' => null];
        }
        $gross = BudgetDecimal::normalize((string) $bill['billed_amount'], false);
        $refund = BudgetDecimal::normalize($refundAmount, false);
        return ['state' => $count === 0 ? 'no_recorded_refund' : 'recorded_refunds',
            'amount_basis' => 'reconciled_bill', 'trust_basis' => 'operator_reviewed', 'adjustment_count' => $count,
            'gross_billed_amount' => $gross, 'refund_amount' => $refund,
            'net_billed_amount' => BudgetDecimal::subtract($gross, $refund), 'currency' => (string) $bill['currency']];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dto(array $row): array
    {
        $out = [];
        foreach (['id', 'usage_event_id', 'reconciliation_id', 'organization_id', 'application_id',
            'environment_id', 'workload_client_id', 'provider_id', 'evidence_id', 'admin_id'] as $field) {
            $out[$field] = (int) $row[$field];
        }
        foreach (['adjustment_kind', 'amount_basis', 'currency', 'evidence_sha256', 'evidence_locator',
            'provider_refund_reference', 'trust_basis', 'idempotency_key', 'note', 'create_time'] as $field) {
            $out[$field] = (string) $row[$field];
        }
        foreach (['original_billed_amount', 'refund_amount'] as $field) {
            $out[$field] = BudgetDecimal::normalize((string) $row[$field], false);
        }
        $evidence = Db::connect('pgsql')->table('sand_ai_billing_evidence')->where('id', $row['evidence_id'])->find();
        $out['evidence_state'] = is_array($evidence) ? (new BillingEvidenceService())->availability($evidence) : 'evidence_unavailable';
        return $out;
    }
}
