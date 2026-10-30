<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Usage\NumericSql;
use think\facade\Db;

final class PostgresBillingReviewStore implements BillingReviewStore
{
    public function __construct(private readonly ?BillingUsageTrace $traces = null)
    {
    }

    public function transaction(callable $callback): mixed { return Db::connect('pgsql')->transaction($callback); }
    public function lockKey(int $adminId, int $lineId, string $key): void
    {
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'sand_ai:billing_review:' . $adminId . ':' . $lineId . ':' . $key,
        ]);
    }
    public function replay(int $adminId, int $lineId, string $key): ?array
    {
        $row = Db::connect('pgsql')->table('sand_ai_billing_review')->where('admin_id', $adminId)
            ->where('billing_line_id', $lineId)->where('idempotency_key', $key)->find();
        return is_array($row) ? $row : null;
    }
    public function lockLine(int $id): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT line.*, imports.provider_id FROM sand_ai_billing_line line JOIN sand_ai_billing_import imports ON imports.id = line.billing_import_id WHERE line.id = ? FOR UPDATE OF line',
            [$id],
        );
        if ($rows === []) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Billing line not found'); }
        return $rows[0];
    }
    public function lockEvidence(int $id): array
    {
        $rows = Db::connect('pgsql')->query('SELECT * FROM sand_ai_billing_evidence WHERE id = ? FOR UPDATE', [$id]);
        if ($rows === []) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Billing evidence not found'); }
        return $rows[0];
    }
    public function lockEvent(int $id, int $environmentId): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT event.*, invocation.organization_id AS owner_organization_id, invocation.application_id AS owner_application_id, '
            . 'invocation.environment_id AS owner_environment_id, invocation.workload_client_id AS owner_workload_client_id, invocation.request_id '
            . 'FROM sand_ai_usage_event event JOIN sand_ai_invocation invocation ON invocation.id = event.invocation_id '
            . 'WHERE event.id = ? AND event.environment_id = ? FOR UPDATE OF event',
            [$id, $environmentId],
        );
        if ($rows === []) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Invocation usage event not found'); }
        return $rows[0];
    }
    public function hasFinalReview(int $lineId): bool
    {
        return Db::connect('pgsql')->table('sand_ai_billing_review')->where('billing_line_id', $lineId)
            ->whereIn('decision', ['compare', 'reject'])->find() !== null;
    }
    public function eventAllocated(int $eventId): bool
    {
        return Db::connect('pgsql')->table('sand_ai_billing_review')->where('usage_event_id', $eventId)
            ->where('decision', 'compare')->find() !== null;
    }
    public function insert(array $data, string $fingerprint, int $adminId, array $result, ?array $event): int
    {
        $eventSql = $event === null ? 'NULL, NULL' : '?, ?';
        $parameters = [$data['billing_line_id'], $data['evidence_id'], $adminId, $data['idempotency_key'], $fingerprint,
            $data['evidence_locator'], $data['granularity'], $data['decision'], $data['note'],
            $data['request_evidence_confirmed'] ? 'true' : 'false'];
        if ($event !== null) { array_push($parameters, $data['usage_event_id'], $data['environment_id']); }
        array_push($parameters, $result['comparison_state'], $result['reason'],
            ...NumericSql::binds($result['quantity_difference'], $result['amount_difference']));
        $rows = Db::connect('pgsql')->query(
            'INSERT INTO sand_ai_billing_review (billing_line_id,evidence_id,admin_id,idempotency_key,fingerprint,evidence_locator,granularity,decision,note,request_evidence_confirmed,usage_event_id,environment_id,comparison_state,reason,quantity_difference,amount_difference) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,CAST(? AS boolean),' . $eventSql . ',?,?,CAST(? AS numeric(20,6)),CAST(? AS numeric(20,6))) RETURNING id',
            $parameters,
        );
        $id = (int) $rows[0]['id'];
        $context = ['billing_line_id' => $data['billing_line_id'], 'evidence_id' => $data['evidence_id'],
            'request_key' => $data['idempotency_key'], 'input_sha256' => $data['_recovery_input_sha256'] ?? null,
            'billing_fingerprint' => $fingerprint,
            'trust_basis' => 'operator_reviewed', 'decision' => $data['decision'], 'comparison_state' => $result['comparison_state'],
            'reason' => $result['reason'], 'quantity_difference' => $result['quantity_difference'], 'amount_difference' => $result['amount_difference'],
            'evidence_sha256' => (string) $this->lockEvidence($data['evidence_id'])['sha256'], 'note' => $data['note']];
        if ($event !== null) {
            foreach (['organization_id','application_id','environment_id','workload_client_id','request_id'] as $field) {
                $context[$field] = $event[$field];
            }
            $context['usage_event_id'] = (int) $event['id'];
        }
        Db::connect('pgsql')->query(
            'INSERT INTO sand_ai_audit_log (actor_type,actor_ref,action,resource_type,resource_id,summary,context) VALUES (?,?,?,?,?,?,CAST(? AS jsonb))',
            ['admin', (string) $adminId, 'billing.review', 'billing_review', $id, '人工账单审核',
                json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        );
        return $id;
    }
    public function read(int $id): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT review.*, evidence.sha256 AS evidence_sha256, evidence.state AS evidence_state, evidence.byte_size, evidence.storage_driver, evidence.object_key FROM sand_ai_billing_review review '
            . 'JOIN sand_ai_billing_evidence evidence ON evidence.id = review.evidence_id WHERE review.id = ?', [$id],
        );
        if ($rows === []) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Review not found'); }
        return $this->dto($rows[0]);
    }
    public function index(int $lineId): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT review.*, evidence.sha256 AS evidence_sha256, evidence.state AS evidence_state, evidence.byte_size, evidence.storage_driver, evidence.object_key FROM sand_ai_billing_review review '
            . 'JOIN sand_ai_billing_evidence evidence ON evidence.id = review.evidence_id WHERE review.billing_line_id = ? ORDER BY review.id DESC LIMIT 100', [$lineId],
        );
        $availability = [];
        $out = [];
        foreach ($rows as $row) {
            // One history query can contain many reviews of the same immutable evidence.
            $key = $row['evidence_id'] . ':' . $row['evidence_state'] . ':' . $row['evidence_sha256'];
            $availability[$key] ??= $this->evidenceAvailability($row);
            $out[] = $this->dto($row, $availability[$key]);
        }
        return $out;
    }
    public function forEvent(int $eventId): ?array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT review.*, evidence.sha256 AS evidence_sha256, evidence.state AS evidence_state, evidence.byte_size, evidence.storage_driver, evidence.object_key FROM sand_ai_billing_review review '
            . "JOIN sand_ai_billing_evidence evidence ON evidence.id = review.evidence_id WHERE review.usage_event_id = ? AND review.decision = 'compare' ORDER BY review.id DESC LIMIT 1", [$eventId],
        );
        if ($rows === []) { return null; }
        $dto = $this->dto($rows[0]);
        return array_intersect_key($dto, array_flip(['trust_basis','comparison_state','reason','quantity_difference','amount_difference','evidence_state']));
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function dto(array $row, ?string $availability = null): array
    {
        $out = ['trust_basis' => 'operator_reviewed', 'locator_basis' => 'operator_declared'];
        foreach (['id','billing_line_id','evidence_id','admin_id'] as $field) { $out[$field] = (int) $row[$field]; }
        foreach (['usage_event_id','environment_id'] as $field) { $out[$field] = $row[$field] === null ? null : (int) $row[$field]; }
        foreach (['idempotency_key','evidence_locator','granularity','decision','note','comparison_state','reason','create_time','evidence_sha256'] as $field) { $out[$field] = (string) $row[$field]; }
        foreach (['quantity_difference','amount_difference'] as $field) { $out[$field] = $row[$field] === null ? null : (string) $row[$field]; }
        $out['evidence_state'] = $availability ?? $this->evidenceAvailability($row);
        $out['trace'] = $this->traces?->project(
            (int) $row['billing_line_id'],
            $row['usage_event_id'] === null ? null : (int) $row['usage_event_id'],
            $row['environment_id'] === null ? null : (int) $row['environment_id'],
        );
        return $out;
    }
    /** @param array<string,mixed> $row */
    private function evidenceAvailability(array $row): string
    {
        return (new BillingEvidenceService())->availability([
            'state'=>$row['evidence_state'],'sha256'=>$row['evidence_sha256'],'byte_size'=>$row['byte_size'],
            'storage_driver'=>$row['storage_driver'],'object_key'=>$row['object_key'],
        ]);
    }
}
