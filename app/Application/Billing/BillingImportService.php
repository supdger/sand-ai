<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

/** Coordinates locks before immutable bill facts are inserted. */
final class BillingImportService
{
    private BillingImportStore $store;

    public function __construct(?BillingImportStore $store = null) { $this->store = $store ?? new PostgresBillingImportStore(); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function import(array $input, int $adminId, ?BillingImportAuditContext $audit = null, array $trustedFacts = []): array
    {
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        $policy = new BillingImportPolicy();
        $data = $policy->normalize($input);
        if (isset($data['evidence_id']) && $trustedFacts !== []) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'Manual evidence cannot supply provider-verified billing identity');
        }
        foreach ($data['lines'] as $index => &$line) {
            // normalize sorts external_line_id. Positional metadata would bind
            // one provider account/request to another line after sorting.
            $identity = $trustedFacts[$line['external_line_id']] ?? null;
            if ($identity !== null) {
                $line['identity_namespace'] = $identity['namespace'];
                $line['account_scope'] = $identity['account_scope'];
                $line['stable_key'] = $identity['stable_key'];
                $line['identity_state'] = 'verified';
                if ($identity['namespace'] === 'openrouter_generation_v1') {
                    if (!is_array($identity['request'] ?? null)
                        || ($identity['request']['generation_id'] ?? null) !== $line['external_line_id']) {
                        throw new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Generation request evidence is unavailable');
                    }
                    $line['_request_fact'] = $identity['request'];
                }
            } else {
                $line['identity_state'] = 'identity_unverified';
            }
            $line['content_digest'] = hash('sha256', json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if (isset($data['evidence_id'])) {
                // Batch keys, references and external labels do not change the original line's facts.
                $facts = array_intersect_key($line, array_flip(['model_code', 'capability_code', 'meter_code',
                    'quantity', 'amount', 'currency', 'occurred_start', 'occurred_end']));
                $line['content_digest'] = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
        }
        unset($line);
        $fingerprint = $policy->fingerprint($data);

        $audit ??= BillingImportAuditContext::manual();
        $audit = $audit->withRecovery($input, $fingerprint);

        return $this->store->transaction(function () use ($data, $fingerprint, $adminId, $audit): array {
            // Serializes same-key claims before re-reading; the database unique key is a final guard.
            $this->store->lockIdempotency((int) $data['provider_id'], (string) $data['idempotency_key']);
            if (!$this->store->providerExists((int) $data['provider_id'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'provider not found');
            }
            $claim = $this->store->claimImport($data, $fingerprint);
            if (!$claim['created']) {
                (new BillingImportIdempotencyPolicy())->isReplay($claim['fingerprint'], $fingerprint);
                return ['idempotent_replay' => true, 'billing_import' => $this->store->read($claim['id'])];
            }
            $source = isset($data['evidence_id'])
                ? (new BillingEvidenceService())->importSource((int) $data['provider_id'], $data['evidence_id'])
                : null;
            $importId = $claim['id'];
            $lineClaims = [];
            $lineOrder = array_keys($data['lines']);
            if ($source !== null) {
                // Every source lock is acquired in the same canonical order.
                usort($lineOrder, static fn (int $left, int $right): int =>
                    strcmp($data['lines'][$left]['evidence_locator'], $data['lines'][$right]['evidence_locator']));
            }
            foreach ($lineOrder as $index) {
                $line = $data['lines'][$index];
                if ($source !== null) {
                    $line['_manual_source'] = $source + ['evidence_locator' => $line['evidence_locator']];
                }
                $lineClaims[$index] = $this->store->createLine($importId, $line);
            }
            $candidateSnapshots = [];
            $allCandidateIds = [];
            foreach ($data['lines'] as $index => $line) {
                // Provider cost aggregates cannot be request-reconciled; do not query UsageEvent at all.
                if (!$lineClaims[$index]['created'] || $line['identity_state'] !== 'verified' || $line['meter_code'] !== 'total_tokens') {
                    $candidateSnapshots[] = [];
                    continue;
                }
                $ids = $this->store->candidateIds((int) $data['provider_id'], $line);
                $candidateSnapshots[] = $ids;
                if (count($ids) > BillingImportStore::MAX_CANDIDATES_PER_LINE) { continue; }
                foreach ($ids as $id) { $allCandidateIds[$id] = true; }
            }
            if (count($allCandidateIds) > BillingImportStore::MAX_CANDIDATES_PER_BATCH) {
                throw new ApiProblem('SAND_AI_BILLING_CANDIDATE_LIMIT_EXCEEDED', 'too many unique billing candidates; shorten the billing period and retry');
            }
            $candidateIds = array_keys($allCandidateIds);
            sort($candidateIds, SORT_NUMERIC);
            foreach ($candidateIds as $candidateId) { $this->store->lockUsageEvent((int) $candidateId); }
            foreach ($data['lines'] as $index => $line) {
                if (!$lineClaims[$index]['created']) {
                    continue;
                }
                $match = $line['identity_state'] === 'identity_unverified'
                    ? ['state' => 'unmatched', 'reason' => 'identity_unverified', 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null]
                    : $this->resolveLockedMatch((int) $data['provider_id'], $line, $candidateSnapshots[$index]);
                $this->store->createReconciliation($lineClaims[$index]['id'], $match, $line);
            }
            $this->store->createAudit($adminId, $importId, count($data['lines']), $audit);

            return ['idempotent_replay' => false, 'billing_import' => $this->store->read($importId)];
        });
    }

    /** @param array<string,mixed> $line @return array{state:string,usage_event_id:int|null,quantity_difference:string|null,amount_difference:string|null} */
    private function resolveLockedMatch(int $providerId, array $line, array $candidateIds): array
    {
        if ($line['meter_code'] !== 'total_tokens') {
            return ['state' => 'unmatched', 'reason' => 'unsupported_meter', 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null];
        }
        if (count($candidateIds) > BillingImportStore::MAX_CANDIDATES_PER_LINE) {
            return ['state' => 'ambiguous', 'reason' => 'candidate_limit_exceeded', 'usage_event_id' => null, 'quantity_difference' => null, 'amount_difference' => null];
        }
        $available = [];
        foreach ($candidateIds as $candidateId) {
            $candidate = $this->store->availableCandidate($candidateId, $providerId, $line);
            if ($candidate !== null) { $available[] = $candidate; }
        }
        if (count($available) === 1 && ($available[0]['native_tokens_match'] ?? true) === false
            && $available[0]['quantity'] !== null && $available[0]['amount'] !== null) {
            return ['state' => 'discrepancy', 'reason' => 'native_token_difference',
                'usage_event_id' => $available[0]['id'],
                'quantity_difference' => BillingDecimal::difference($line['quantity'], $available[0]['quantity']),
                'amount_difference' => BillingDecimal::difference($line['amount'], $available[0]['amount'])];
        }
        return (new BillingReconciliationMatcher())->match($line, $available);
    }

    /** @return array<string,mixed> */
    public function read(int $id): array { return $this->store->read($id); }
    /** @return list<array<string,mixed>> */
    public function index(?int $providerId = null): array { return $this->store->index($providerId); }
}
