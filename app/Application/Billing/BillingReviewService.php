<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

final class BillingReviewService
{
    public function __construct(private ?BillingReviewStore $store = null)
    {
        $this->store ??= new PostgresBillingReviewStore();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function review(array $input, int $adminId): array
    {
        if ($adminId < 1) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'Authenticated administrator required');
        }
        $data = (new BillingReviewPolicy())->normalize($input);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        // Additional audit metadata is excluded from the original financial fingerprint.
        $data['_recovery_input_sha256'] = BillingOperationRecovery::inputSha($input);
        return $this->store->transaction(function () use ($data, $fingerprint, $adminId): array {
            $this->store->lockKey($adminId, $data['billing_line_id'], $data['idempotency_key']);
            $replay = $this->store->replay($adminId, $data['billing_line_id'], $data['idempotency_key']);
            if ($replay !== null) {
                if (!hash_equals((string) $replay['fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_BILLING_REVIEW_CONFLICT', 'Review key has different content');
                }
                return ['idempotent_replay' => true, 'review' => $this->store->read((int) $replay['id'])];
            }
            $line = $this->store->lockLine($data['billing_line_id']);
            $evidence = $this->store->lockEvidence($data['evidence_id']);
            if ((int) $line['provider_id'] !== (int) $evidence['provider_id']
                || (new BillingEvidenceService())->availability($evidence) !== 'available') {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_UNAVAILABLE', 'Evidence does not match this provider or has been cleared');
            }
            if ($this->store->hasFinalReview($data['billing_line_id'])) {
                throw new ApiProblem('SAND_AI_BILLING_REVIEW_FINAL', 'This line already has a final review');
            }
            $event = null;
            $result = ['comparison_state' => match ($data['decision']) {
                'reject' => 'rejected', 'unresolved' => 'unresolved', default => 'aggregate_only',
            }, 'reason' => $data['decision'] === 'compare' ? 'aggregate_not_allocated' : 'operator_' . $data['decision'],
                'quantity_difference' => null, 'amount_difference' => null];
            if ($data['decision'] === 'compare' && $data['granularity'] === 'request') {
                if ($line['meter_code'] !== 'total_tokens') {
                    throw new ApiProblem('SAND_AI_BILLING_REVIEW_INVALID', 'Aggregate costs cannot be allocated to a request');
                }
                $event = $this->store->lockEvent($data['usage_event_id'], $data['environment_id']);
                $this->assertScope($line, $event);
                if ($this->store->eventAllocated($data['usage_event_id'])) {
                    throw new ApiProblem('SAND_AI_BILLING_REVIEW_FINAL', 'Usage event already has an operator allocation');
                }
                $match = (new BillingReconciliationMatcher())->match($line, [[
                    'id' => (int) $event['id'], 'meter_code' => 'total_tokens',
                    'quantity' => $event['usage_state'] === 'measured' && $event['total_tokens'] !== null ? (string) $event['total_tokens'] : null,
                    'amount' => $event['pricing_state'] === 'priced' && $event['metered_amount'] !== null ? (string) $event['metered_amount'] : null,
                ]]);
                $result = ['comparison_state' => match ($match['state']) {
                    'reconciled' => 'reviewed_match', 'discrepancy' => 'reviewed_discrepancy', default => 'unmatched',
                }, 'reason' => $match['reason'], 'quantity_difference' => $match['quantity_difference'],
                    'amount_difference' => $match['amount_difference']];
            }
            $id = $this->store->insert($data, $fingerprint, $adminId, $result, $event);
            return ['idempotent_replay' => false, 'review' => $this->store->read($id)];
        });
    }

    /** @param array<string,mixed> $line @param array<string,mixed> $event */
    private function assertScope(array $line, array $event): void
    {
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if ((int) ($event[$field] ?? 0) < 1
                || (string) $event[$field] !== (string) ($event['owner_' . $field] ?? '')) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Usage event has unverified ownership');
            }
        }
        $model = is_string($event['model_snapshot']) ? json_decode($event['model_snapshot'], true, 32, JSON_THROW_ON_ERROR) : $event['model_snapshot'];
        if ((int) $line['provider_id'] !== (int) $event['provider_id']
            || ($event['pricing_state'] === 'priced' && $line['currency'] !== $event['currency'])
            || $line['capability_code'] !== $event['capability_code']
            || $line['model_code'] !== ($model['code'] ?? null)
            || strcmp((string) $event['create_time'], (string) $line['occurred_start']) < 0
            || strcmp((string) $event['create_time'], (string) $line['occurred_end']) >= 0) {
            throw new ApiProblem('SAND_AI_BILLING_REVIEW_INVALID', 'Selected event does not match the bill line');
        }
    }

    /** @return list<array<string,mixed>> */
    public function index(int $lineId): array { return $this->store->index($lineId); }
    /** @return array<string,mixed>|null */
    public function forEvent(int $eventId): ?array { return $this->store->forEvent($eventId); }
}
