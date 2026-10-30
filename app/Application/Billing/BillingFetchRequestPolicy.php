<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class BillingFetchRequestPolicy
{
    /** @param array<string,mixed> $input @return array{provider_id:int,period_start:string,period_end:string,start_unix:int,end_unix:int,idempotency_key:string} */
    public function normalize(array $input): array
    {
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['provider_id', 'period_start', 'period_end', 'idempotency_key'], true)) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'unsupported billing sync field');
            }
        }
        // Parse before resolving a provider or retrieving billing credentials.
        $providerId = BillingPositiveId::parse(
            $input['provider_id'] ?? null,
            'provider_id',
            'SAND_AI_BILLING_VALIDATION_ERROR',
        );
        $start = $this->time($input['period_start'] ?? null);
        $end = $this->time($input['period_end'] ?? null);
        if ($end <= $start || $start->add(new DateInterval('P31D')) < $end) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'billing period must be positive and at most 31 days');
        }
        $key = is_string($input['idempotency_key'] ?? null) ? trim($input['idempotency_key']) : '';
        if ($key === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F\s]/', $key) === 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'idempotency_key invalid');
        }

        return [
            'provider_id' => $providerId,
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end' => $end->format('Y-m-d H:i:s'),
            'start_unix' => $start->getTimestamp(),
            'end_unix' => $end->getTimestamp(),
            'idempotency_key' => $key,
        ];
    }

    private function time(mixed $value): DateTimeImmutable
    {
        $time = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'))
            : false;
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $time->format('Y-m-d H:i:s') !== $value) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'time must use UTC Y-m-d H:i:s');
        }

        return $time;
    }
}
