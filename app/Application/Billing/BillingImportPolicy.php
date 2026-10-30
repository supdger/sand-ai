<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use DateTimeImmutable;
use DateTimeZone;

final class BillingImportPolicy
{
    private const MAX_LINES = 500;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $this->allowOnly($input, ['provider_id', 'period_start', 'period_end', 'idempotency_key', 'source_kind', 'reference', 'lines', 'evidence_id'], 'billing import');
        $evidenceId = array_key_exists('evidence_id', $input)
            ? $this->positiveId($input['evidence_id'], 'evidence_id') : null;
        $periodStart = $this->time($input['period_start'] ?? null, 'period_start');
        $periodEnd = $this->time($input['period_end'] ?? null, 'period_end');
        if ($periodEnd <= $periodStart) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'period_end must be later than period_start');
        }
        $lines = $input['lines'] ?? null;
        if (!is_array($lines) || $lines === [] || count($lines) > self::MAX_LINES) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'lines must contain between 1 and 500 entries');
        }
        $normalizedLines = [];
        $locators = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'billing line must be an object');
            }
            $this->allowOnly($line, ['external_line_id', 'model_code', 'capability_code', 'meter_code', 'quantity', 'amount', 'currency', 'occurred_start', 'occurred_end', 'evidence_locator'], 'billing line');
            if ($evidenceId === null && array_key_exists('evidence_locator', $line)) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'evidence_locator requires the original private evidence');
            }
            $locator = $evidenceId === null ? null : $this->locator($line['evidence_locator'] ?? null);
            if ($locator !== null && isset($locators[$locator])) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'evidence_locator must be unique within an import');
            }
            if ($locator !== null) { $locators[$locator] = true; }
            $externalLineId = $this->text($line['external_line_id'] ?? null, 'external_line_id', 128);
            if (isset($normalizedLines[$externalLineId])) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'external_line_id must be unique within an import');
            }
            $occurredStart = $this->time($line['occurred_start'] ?? null, 'occurred_start');
            $occurredEnd = $this->time($line['occurred_end'] ?? null, 'occurred_end');
            if ($occurredStart < $periodStart || $occurredEnd > $periodEnd || $occurredEnd <= $occurredStart) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'billing line time window must be inside its import period');
            }
            $normalizedLines[$externalLineId] = [
                'external_line_id' => $externalLineId,
                'model_code' => $this->code($line['model_code'] ?? null, 'model_code'),
                'capability_code' => $this->code($line['capability_code'] ?? null, 'capability_code'),
                'meter_code' => $this->code($line['meter_code'] ?? null, 'meter_code'),
                'quantity' => BillingDecimal::normalize($line['quantity'] ?? null, 'quantity'),
                'amount' => BillingDecimal::normalize($line['amount'] ?? null, 'amount'),
                'currency' => $this->currency($line['currency'] ?? null),
                'occurred_start' => $occurredStart->format('Y-m-d H:i:s'),
                'occurred_end' => $occurredEnd->format('Y-m-d H:i:s'),
            ];
            if ($locator !== null) {
                $normalizedLines[$externalLineId]['evidence_locator'] = $locator;
            }
        }
        ksort($normalizedLines, SORT_STRING);

        $data = [
            'provider_id' => $this->positiveId($input['provider_id'] ?? null, 'provider_id'),
            'period_start' => $periodStart->format('Y-m-d H:i:s'),
            'period_end' => $periodEnd->format('Y-m-d H:i:s'),
            'idempotency_key' => $this->text($input['idempotency_key'] ?? null, 'idempotency_key', 128),
            'source_kind' => $this->code($input['source_kind'] ?? null, 'source_kind'),
            'reference' => $this->text($input['reference'] ?? null, 'reference', 256),
            'lines' => array_values($normalizedLines),
        ];
        if ($evidenceId !== null) { $data['evidence_id'] = $evidenceId; }
        return $data;
    }

    /** @param array<string,mixed> $input @param list<string> $allowed */
    private function allowOnly(array $input, array $allowed, string $scope): void
    {
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$scope} contains an unsupported field");
            }
        }
    }

    /** @param array<string, mixed> $normalized */
    public function fingerprint(array $normalized): string
    {
        $content = $normalized;
        unset($content['idempotency_key']);

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }

            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} must be a positive integer");
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} must be a positive integer");
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} must fit a signed 64-bit positive integer");
        }

        return (int) $value;
    }

    private function text(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) || ($text = trim($value)) === '' || mb_strlen($text, 'UTF-8') > $max) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} is required");
        }
        return $text;
    }

    private function locator(mixed $value): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || preg_match('/[\p{Cc}]/u', $value) === 1 || trim($value) === ''
            || mb_strlen(trim($value), 'UTF-8') > 256) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'evidence_locator must be valid text between 1 and 256 characters');
        }
        // This is the operator's exact locator, not a parser-certified row.
        return trim($value);
    }

    private function code(mixed $value, string $field): string
    {
        $code = $this->text($value, $field, 96);
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $code) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} is invalid");
        }
        return $code;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'currency must be a three-letter code');
        }
        return $value;
    }

    private function time(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} is required");
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', "{$field} must use Y-m-d H:i:s");
        }
        return $time;
    }
}
