<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Usage\NumericSql;
use app\Application\Gateway\RequestPayloadFingerprint;
use think\db\ConnectionInterface;
use think\facade\Db;

final class PostgresBillingImportStore implements BillingImportStore
{
    private ?ConnectionInterface $transactionConnection = null;

    public function __construct(private readonly ?BillingUsageTrace $traces = null)
    {
    }

    public function transaction(callable $callback): mixed
    {
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($callback): mixed {
            if ($this->transactionConnection !== null) {
                throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'nested billing import transactions are not supported');
            }
            $this->transactionConnection = $connection;
            try {
                $backend = $connection->query('SELECT pg_backend_pid() AS backend_pid');
                $backendPid = (int) ($backend[0]['backend_pid'] ?? 0);
                if ($backendPid <= 0) {
                    throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'billing transaction backend is unavailable');
                }
                $workerPid = getmypid();
                if ($workerPid <= 0) {
                    throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'billing transaction worker is unavailable');
                }
                $connection->query("SELECT set_config('application_name', ?, true)", [
                    'sand_ai_billing_import_' . $backendPid . '_worker_' . $workerPid,
                ]);
                return $callback();
            } finally {
                $this->transactionConnection = null;
            }
        });
    }

    public function lockIdempotency(int $providerId, string $idempotencyKey): void
    {
        // A 64-bit hash collision can only add serialization. The database
        // unique claim remains the correctness boundary for key equality.
        $this->connection()->query(
            BillingImportAdvisoryLock::sql(),
            BillingImportAdvisoryLock::parameters($providerId, $idempotencyKey),
        );
    }

    public function findImport(int $providerId, string $idempotencyKey): ?array
    {
        $import = $this->connection()->table('sand_ai_billing_import')
            ->where('provider_id', $providerId)->where('idempotency_key', $idempotencyKey)->find();
        return is_array($import) ? $import : null;
    }

    public function providerExists(int $providerId): bool
    {
        return $this->connection()->query(
            'SELECT id FROM sand_ai_provider WHERE id = ? AND status = ? AND delete_time IS NULL FOR SHARE',
            [$providerId, 1],
        ) !== [];
    }

    public function claimImport(array $data, string $fingerprint): array
    {
        $connection = $this->connection();
        $rows = $connection->query(
            'INSERT INTO sand_ai_billing_import (provider_id, period_start, period_end, idempotency_key, fingerprint, source_kind, reference, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (provider_id, idempotency_key) DO NOTHING RETURNING id, fingerprint',
            [$data['provider_id'], $data['period_start'], $data['period_end'], $data['idempotency_key'], $fingerprint, $data['source_kind'], $data['reference'], 'imported']
        );
        if ($rows !== []) {
            return ['id' => (int) $rows[0]['id'], 'created' => true, 'fingerprint' => (string) $rows[0]['fingerprint']];
        }
        $existing = $this->findImport((int) $data['provider_id'], (string) $data['idempotency_key']);
        if ($existing === null) {
            throw new ApiProblem('SAND_AI_BILLING_CLAIM_FAILED', 'billing import claim did not return a row');
        }
        return ['id' => (int) $existing['id'], 'created' => false, 'fingerprint' => (string) $existing['fingerprint']];
    }

    public function candidateIds(int $providerId, array $line): array
    {
        if ($line['meter_code'] !== 'total_tokens') { return []; }
        if (($line['identity_namespace'] ?? null) === 'openrouter_generation_v1') {
            $event = $this->generationCandidate($providerId, $line);
            return $event === null ? [] : [(int) $event['id']];
        }
        return array_map('intval', $this->connection()->table('sand_ai_usage_event')
            ->where('provider_id', $providerId)->where('capability_code', $line['capability_code'])
            ->where('currency', $line['currency'])->where('create_time', '>=', $line['occurred_start'])->where('create_time', '<', $line['occurred_end'])
            ->whereRaw("model_snapshot->>'code' = ?", [$line['model_code']])->order('id')->limit(BillingImportStore::MAX_CANDIDATES_PER_LINE + 1)->column('id'));
    }

    public function lockUsageEvent(int $usageEventId): void
    {
        $rows = $this->connection()->query('SELECT id FROM sand_ai_usage_event WHERE id = ? FOR UPDATE', [$usageEventId]);
        if ($rows === []) {
            throw new ApiProblem('SAND_AI_BILLING_CANDIDATE_CHANGED', 'billing usage candidate disappeared before locking');
        }
    }

    public function availableCandidate(int $usageEventId, int $providerId, array $line): ?array
    {
        if ($line['meter_code'] !== 'total_tokens') { return null; }
        if (($line['identity_namespace'] ?? null) === 'openrouter_generation_v1') {
            $event = $this->generationCandidate($providerId, $line);
            if ($event === null || (int) $event['id'] !== $usageEventId) return null;
            return [
                'id' => (int) $event['id'], 'meter_code' => 'total_tokens',
                'quantity' => $event['usage_state'] === 'measured' ? (string) $event['total_tokens'] : null,
                'amount' => $event['pricing_state'] === 'priced' ? $event['metered_amount'] : null,
                'native_tokens_match' => $event['usage_state'] === 'measured'
                    && (int) $event['input_tokens'] === $line['_request_fact']['native_input_tokens']
                    && (int) $event['output_tokens'] === $line['_request_fact']['native_output_tokens'],
            ];
        }
        $event = $this->connection()->table('sand_ai_usage_event')->alias('usage_event')
            ->leftJoin('sand_ai_usage_reconciliation reconciliation', 'reconciliation.usage_event_id = usage_event.id')
            // A left join also has an `id` column. Select the immutable usage
            // event fields explicitly so an unmatched reconciliation cannot
            // replace usage_event.id with NULL (and later 0 after casting).
            ->field('usage_event.id AS id, usage_event.total_tokens AS total_tokens, usage_event.metered_amount AS metered_amount, usage_event.usage_state AS usage_state, usage_event.pricing_state AS pricing_state')
            ->where('usage_event.id', $usageEventId)->where('usage_event.provider_id', $providerId)
            ->where('usage_event.capability_code', $line['capability_code'])->where('usage_event.currency', $line['currency'])
            ->where('usage_event.create_time', '>=', $line['occurred_start'])->where('usage_event.create_time', '<', $line['occurred_end'])
            ->whereRaw("usage_event.model_snapshot->>'code' = ?", [$line['model_code']])->whereNull('reconciliation.id')->find();
        if (!is_array($event)) { return null; }
        return [
            'id' => (int) $event['id'],
            'meter_code' => 'total_tokens',
            'quantity' => ($event['usage_state'] ?? null) === 'measured' && $event['total_tokens'] !== null
                ? (string) $event['total_tokens'] : null,
            'amount' => ($event['pricing_state'] ?? null) === 'priced' && $event['metered_amount'] !== null
                ? (string) $event['metered_amount'] : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function generationCandidate(int $providerId, array $line): ?array
    {
        $fact = $line['_request_fact'] ?? null;
        if (!is_array($fact) || ($fact['generation_id'] ?? null) !== ($line['stable_key'] ?? null)
            || $fact['generation_id'] !== $line['external_line_id']) {
            throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation evidence is not bound to this bill line');
        }
        foreach (['usage_event_id','invocation_id','dispatch_id'] as $field) {
            if (!is_int($fact[$field] ?? null) || $fact[$field] < 1) throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation evidence has invalid identifiers');
        }
        foreach (['native_input_tokens','native_output_tokens'] as $field) {
            if (!is_int($fact[$field] ?? null) || $fact[$field] < 0) throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation native usage is unavailable');
        }
        foreach (['request_body_digest','execution_snapshot_sha256','provider_config_sha256'] as $field) {
            if (!is_string($fact[$field] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $fact[$field]) !== 1) throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation evidence has invalid digests');
        }
        // Hold the same provider connection through commit. A key/config change
        // between the metadata read and import cannot inherit verified trust.
        $providers = $this->connection()->query(
            'SELECT encrypted_config FROM sand_ai_provider WHERE id=? AND status=1 AND delete_time IS NULL FOR SHARE',
            [$providerId],
        );
        $encrypted = $providers[0]['encrypted_config'] ?? null;
        if (is_string($encrypted)) {
            try { $encrypted = json_decode($encrypted, true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { $encrypted = null; }
        }
        if (!is_array($encrypted) || !hash_equals($fact['provider_config_sha256'], RequestPayloadFingerprint::fingerprint($encrypted, []))) {
            throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Original provider connection changed before import');
        }
        $rows = $this->connection()->query(
            "SELECT e.id,e.total_tokens,e.input_tokens,e.output_tokens,e.metered_amount,e.usage_state,e.pricing_state
             FROM sand_ai_usage_event e JOIN sand_ai_invocation i ON i.id=e.invocation_id
             JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
             JOIN sand_ai_provider_response_identity r ON r.dispatch_id=d.id AND r.invocation_id=i.id
             WHERE e.id=? AND i.id=? AND d.id=? AND e.provider_id=? AND d.provider_id=e.provider_id
               AND r.provider_id=e.provider_id AND r.identifier=? AND r.source='body.id' AND r.identity_kind='response_id'
               AND r.request_body_digest=? AND d.request_body_digest=r.request_body_digest
               AND r.execution_snapshot_sha256=? AND d.execution_snapshot_sha256=r.execution_snapshot_sha256
               AND r.attempt_no=d.attempt_no AND r.model_id=d.model_id AND r.model_id=e.model_id
               AND r.deployment_id=d.deployment_id AND r.deployment_id=e.deployment_id
               AND e.organization_id=i.organization_id AND e.application_id=i.application_id
               AND e.environment_id=i.environment_id AND e.workload_client_id=i.workload_client_id
               AND r.organization_id=i.organization_id AND r.application_id=i.application_id
               AND r.environment_id=i.environment_id AND r.workload_client_id=i.workload_client_id
               AND d.state IN ('succeeded','failed') AND d.dispatch_start_time IS NOT NULL
               AND e.capability_code=? AND e.currency=? AND e.model_snapshot->>'code'=?
               AND e.metering_unit IS NULL
               AND NOT EXISTS (SELECT 1 FROM sand_ai_usage_reconciliation rec WHERE rec.usage_event_id=e.id)
               AND NOT EXISTS (SELECT 1 FROM sand_ai_provider_response_identity other
                               WHERE other.dispatch_id=d.id AND other.identity_kind='response_id'
                                 AND other.identifier<>r.identifier)",
            [$fact['usage_event_id'],$fact['invocation_id'],$fact['dispatch_id'],$providerId,$fact['generation_id'],
                $fact['request_body_digest'],$fact['execution_snapshot_sha256'],$line['capability_code'],$line['currency'],$line['model_code']],
        );
        return count($rows) === 1 ? $rows[0] : null;
    }

    public function createLine(int $billingImportId, array $line): array
    {
        $source = $line['_manual_source'] ?? null;
        if (is_array($source)) {
            if ($line['identity_state'] !== 'identity_unverified') {
                throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'Manual source cannot supply verified provider identity');
            }
            $identity = ['manual_evidence_v1', $source['provider_id'], $source['sha256'], $source['evidence_locator']];
            $this->connection()->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $existing = $this->connection()->query(
                'SELECT line.id, line.content_digest FROM sand_ai_billing_line_evidence source '
                    . 'JOIN sand_ai_billing_line line ON line.id=source.billing_line_id '
                    . 'WHERE source.namespace=? AND source.provider_id=? AND source.evidence_sha256=? AND source.evidence_locator=? FOR UPDATE OF line',
                $identity,
            );
            if ($existing !== []) {
                if (count($existing) !== 1 || !hash_equals((string) $existing[0]['content_digest'], $line['content_digest'])) {
                    throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'Original evidence locator already has different billing facts');
                }
                $id = (int) $existing[0]['id'];
                $this->linkLine($billingImportId, $id, $line['external_line_id']);
                return ['id' => $id, 'created' => false];
            }
        }
        $verified = $line['identity_state'] === 'verified';
        $identitySql = $verified ? '?, ?, ?' : 'NULL, NULL, NULL';
        $parameters = [$billingImportId, $line['external_line_id'], $line['model_code'], $line['capability_code'], $line['meter_code'],
            ...NumericSql::binds($line['quantity'], $line['amount']), $line['currency'], $line['occurred_start'], $line['occurred_end']];
        if ($verified) {
            array_push($parameters, $line['identity_namespace'], $line['account_scope'], $line['stable_key']);
        }
        array_push($parameters, $line['content_digest'], $line['identity_state']);
        $rows = $this->connection()->query(
            'INSERT INTO sand_ai_billing_line (billing_import_id, external_line_id, model_code, capability_code, meter_code, quantity, amount, currency, occurred_start, occurred_end, identity_namespace, account_scope, stable_key, content_digest, identity_state) VALUES (?, ?, ?, ?, ?, CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), ?, ?, ?, '
                . $identitySql . ', ?, ?) '
                . ($verified ? 'ON CONFLICT (identity_namespace, account_scope, stable_key) WHERE identity_state = \'verified\' DO NOTHING ' : '')
                . 'RETURNING id',
            $parameters
        );
        $created = $rows !== [];
        if ($created) {
            $id = (int) $rows[0]['id'];
        } else {
            $existing = $this->connection()->query(
                'SELECT id, content_digest FROM sand_ai_billing_line WHERE identity_namespace = ? AND account_scope = ? AND stable_key = ? AND identity_state = ?',
                [$line['identity_namespace'], $line['account_scope'], $line['stable_key'], 'verified']
            );
            if ($existing === [] || !hash_equals((string) $existing[0]['content_digest'], $line['content_digest'])) {
                throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'billing fact content differs from an existing fact');
            }
            $id = (int) $existing[0]['id'];
        }
        if (is_array($source)) {
            $this->connection()->query(
                'INSERT INTO sand_ai_billing_line_evidence (namespace,billing_line_id,provider_id,evidence_id,evidence_sha256,evidence_locator,locator_basis) VALUES (?,?,?,?,?,?,?)',
                ['manual_evidence_v1', $id, $source['provider_id'], $source['evidence_id'],
                    $source['sha256'], $source['evidence_locator'], 'operator_declared'],
            );
        }
        $this->linkLine($billingImportId, $id, $line['external_line_id']);
        return ['id' => $id, 'created' => $created];
    }

    private function linkLine(int $billingImportId, int $id, string $externalLineId): void
    {
        $this->connection()->query(
            'INSERT INTO sand_ai_billing_import_line (billing_import_id, billing_line_id, external_line_id) VALUES (?, ?, ?)',
            [$billingImportId, $id, $externalLineId],
        );
    }
    public function createReconciliation(int $billingLineId, array $match, array $line): void
    {
        // ThinkORM binds a null positional integer as 0 for this PostgreSQL
        // query path. The reconciliation FK is intentionally nullable for
        // unmatched, ambiguous and unsupported provider lines, so preserve
        // that semantic in SQL rather than emitting an invalid event id.
        if ($match['usage_event_id'] === null) {
            $this->connection()->query(
                'INSERT INTO sand_ai_usage_reconciliation (billing_line_id, usage_event_id, state, reason, quantity_difference, amount_difference, billed_amount, currency) VALUES (?, NULL, ?, ?, CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), ?)',
                [$billingLineId, $match['state'], $match['reason'], ...NumericSql::binds($match['quantity_difference'], $match['amount_difference'], $line['amount']), $line['currency']]
            );
            return;
        }
        $this->connection()->query(
            'INSERT INTO sand_ai_usage_reconciliation (billing_line_id, usage_event_id, state, reason, quantity_difference, amount_difference, billed_amount, currency) VALUES (?, ?, ?, ?, CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), ?)',
            [$billingLineId, $match['usage_event_id'], $match['state'], $match['reason'], ...NumericSql::binds($match['quantity_difference'], $match['amount_difference'], $line['amount']), $line['currency']]
        );
    }

    public function createAudit(int $adminId, int $billingImportId, int $lineCount, BillingImportAuditContext $audit): void
    {
        $context = $audit->context + ['line_count' => $lineCount];
        $this->connection()->query(
            'INSERT INTO sand_ai_audit_log (actor_type, actor_ref, action, resource_type, resource_id, summary, context) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
            ['admin', (string) $adminId, $audit->action, 'billing_import', $billingImportId,
                $audit->summary, json_encode($context, JSON_THROW_ON_ERROR)]
        );
    }

    public function read(int $id): array
    {
        $connection = $this->connection();
        $import = $connection->table('sand_ai_billing_import')->where('id', $id)->find();
        if (!is_array($import)) { throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'billing import not found'); }
        $lines = $connection->query(
            'SELECT line.*, import_line.external_line_id AS import_external_line_id, original_import.provider_id AS original_provider_id, '
                . 'COUNT(*) OVER (PARTITION BY import_line.billing_line_id) AS canonical_reference_count, '
                . 'latest_review.comparison_state AS operator_review_state, '
                . 'source.provider_id AS source_provider_id, source.evidence_id AS source_evidence_id, source.evidence_sha256 AS source_sha256, '
                . 'source.evidence_locator AS source_locator, source.locator_basis AS source_locator_basis '
                . 'FROM sand_ai_billing_import_line import_line '
                . 'JOIN sand_ai_billing_line line ON line.id = import_line.billing_line_id '
                . 'JOIN sand_ai_billing_import original_import ON original_import.id = line.billing_import_id '
                . 'LEFT JOIN sand_ai_billing_line_evidence source ON source.billing_line_id=line.id '
                . 'LEFT JOIN LATERAL (SELECT review.comparison_state FROM sand_ai_billing_review review '
                . 'WHERE review.billing_line_id = line.id ORDER BY review.id DESC LIMIT 1) latest_review ON TRUE '
                . 'WHERE import_line.billing_import_id = ? ORDER BY import_line.external_line_id',
            [$id]
        );
        $lineIds = [];
        foreach ($lines as $line) {
            if ((int) $line['original_provider_id'] !== (int) $import['provider_id']) {
                throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'Billing fact provider differs from this import');
            }
            if ($line['source_provider_id'] !== null && (int) $line['source_provider_id'] !== (int) $import['provider_id']) {
                throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'Original evidence provider differs from this import');
            }
            $lineIds[] = (int) $line['id'];
        }
        $reconciliations = $lineIds === [] ? [] : $connection->table('sand_ai_usage_reconciliation')->whereIn('billing_line_id', $lineIds)->select()->toArray();
        $byLine = []; foreach ($reconciliations as $result) { $byLine[(int) $result['billing_line_id']] = $result; }
        $lineDtos = [];
        foreach ($lines as $line) {
            $result = $byLine[(int) $line['id']] ?? null;
            $lineDtos[] = ['billing_line_id' => (int) $line['id'], 'external_line_id' => (string) $line['import_external_line_id'],
                'is_duplicate' => (int) $line['billing_import_id'] !== $id || (int) $line['canonical_reference_count'] > 1,
                'operator_review_state' => $line['operator_review_state'] === null ? null : (string) $line['operator_review_state'],
                'identity_state' => (string) $line['identity_state'], 'model_code' => (string) $line['model_code'],
                'source_evidence' => $line['source_evidence_id'] === null ? null : [
                    'evidence_id' => (int) $line['source_evidence_id'], 'sha256' => (string) $line['source_sha256'],
                    'evidence_locator' => (string) $line['source_locator'], 'locator_basis' => (string) $line['source_locator_basis']],
                'capability_code' => (string) $line['capability_code'], 'meter_code' => (string) $line['meter_code'], 'quantity' => (string) $line['quantity'],
                'amount' => (string) $line['amount'], 'currency' => (string) $line['currency'], 'occurred_start' => (string) $line['occurred_start'],
                'occurred_end' => (string) $line['occurred_end'],
                'trace' => $this->traces?->project((int) $line['id'], $result === null || $result['usage_event_id'] === null ? null : (int) $result['usage_event_id']),
                'reconciliation' => $result === null ? null : ['state' => (string) $result['state'],
                    'usage_event_id' => $result['usage_event_id'] === null ? null : (int) $result['usage_event_id'],
                    'reason' => (string) $result['reason'],
                    'quantity_difference' => $result['quantity_difference'] === null ? null : (string) $result['quantity_difference'],
                    'amount_difference' => $result['amount_difference'] === null ? null : (string) $result['amount_difference'],
                    'billed_amount' => (string) $result['billed_amount'], 'currency' => (string) $result['currency']]];
        }
        return ['id' => (int) $import['id'], 'provider_id' => (int) $import['provider_id'], 'period_start' => (string) $import['period_start'],
            'period_end' => (string) $import['period_end'], 'source_kind' => (string) $import['source_kind'], 'reference' => (string) $import['reference'],
            'state' => (string) $import['state'], 'lines' => $lineDtos];
    }
    public function index(?int $providerId): array
    {
        $query = $this->connection()->table('sand_ai_billing_import')->order('id', 'desc')->limit(100); if ($providerId !== null) { $query->where('provider_id', $providerId); }
        $rows = [];
        foreach ($query->select()->toArray() as $import) {
            $rows[] = ['id' => (int) $import['id'], 'provider_id' => (int) $import['provider_id'], 'period_start' => (string) $import['period_start'],
                'period_end' => (string) $import['period_end'], 'source_kind' => (string) $import['source_kind'], 'reference' => (string) $import['reference'],
                'state' => (string) $import['state'], 'create_time' => (string) $import['create_time']];
        }
        return $rows;
    }

    private function connection(): ConnectionInterface
    {
        return $this->transactionConnection ?? Db::connect('pgsql');
    }
}
