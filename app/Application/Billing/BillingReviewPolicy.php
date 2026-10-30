<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

final class BillingReviewPolicy
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalize(array $input): array
    {
        $allowed = ['billing_line_id', 'evidence_id', 'evidence_locator', 'granularity', 'decision',
            'idempotency_key', 'note', 'environment_id', 'usage_event_id', 'request_evidence_confirmed'];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->invalid('Unsupported review field');
            }
        }
        $data = [];
        foreach (['billing_line_id', 'evidence_id'] as $field) {
            $data[$field] = BillingPositiveId::parse($input[$field] ?? null, $field, 'SAND_AI_BILLING_REVIEW_INVALID');
        }
        foreach (['evidence_locator' => 256, 'idempotency_key' => 128, 'note' => 2000] as $field => $limit) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $this->invalid('Invalid ' . $field);
            }
            $data[$field] = trim($value);
        }
        $data['granularity'] = $input['granularity'] ?? null;
        $data['decision'] = $input['decision'] ?? null;
        if (!in_array($data['granularity'], ['request', 'aggregate'], true)
            || !in_array($data['decision'], ['compare', 'reject', 'unresolved'], true)) {
            $this->invalid('Invalid review decision or granularity');
        }
        if ($data['granularity'] === 'request' && $data['decision'] === 'compare') {
            foreach (['environment_id', 'usage_event_id'] as $field) {
                $data[$field] = BillingPositiveId::parse($input[$field] ?? null, $field, 'SAND_AI_BILLING_REVIEW_INVALID');
            }
            if (($input['request_evidence_confirmed'] ?? null) !== true) {
                $this->invalid('Explicit review of request-level evidence is required');
            }
            $data['request_evidence_confirmed'] = true;
        } else {
            foreach (['environment_id', 'usage_event_id', 'request_evidence_confirmed'] as $field) {
                if (array_key_exists($field, $input)) {
                    $this->invalid('Event fields are only allowed for request comparison');
                }
            }
            $data['environment_id'] = null;
            $data['usage_event_id'] = null;
            $data['request_evidence_confirmed'] = false;
        }
        return $data;
    }

    private function invalid(string $message): never
    {
        throw new ApiProblem('SAND_AI_BILLING_REVIEW_INVALID', $message);
    }
}
