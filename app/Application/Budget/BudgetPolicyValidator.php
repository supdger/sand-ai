<?php

declare(strict_types=1);

namespace app\Application\Budget;

use app\Api\Support\ApiProblem;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** Validation and lifecycle rules for environment-scoped budget revisions. */
final class BudgetPolicyValidator
{
    private const FIELDS = ['id', 'environment_id', 'scope_kind', 'scope_ref', 'reference_environment_id', 'period_start', 'period_end', 'currency', 'limit_amount', 'enforcement', 'unpriced_policy', 'unmeasured_policy'];

    public function normalizeRequestKey(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 128 || preg_match('/\A[\x21-\x7e]+\z/', $value) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key must contain 1 to 128 visible ASCII characters');
        }
        return $value;
    }

    /** @param array<string,mixed> $input @return array{request_key:string,scope_kind:string,scope_ref:string} */
    public function normalizeDraftLookup(array $input): array
    {
        if (count($input) !== 3 || array_diff(array_keys($input), ['request_key', 'scope_kind', 'scope_ref']) !== []) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Draft lookup requires the original request key and budget scope');
        }
        $scope = $this->normalizeScope($input);
        return [
            'request_key' => $this->normalizeRequestKey($input['request_key'] ?? null),
            'scope_kind' => $scope['scope_kind'],
            'scope_ref' => $scope['scope_ref'],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalizeDraftTarget(array $input): array
    {
        return [
            'id' => array_key_exists('id', $input) ? $this->id($input['id'], 'id') : null,
            ...$this->normalizeScope($input),
            'reference_environment_id' => isset($input['reference_environment_id'])
                ? $this->id($input['reference_environment_id'], 'reference_environment_id') : null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function normalizeDraft(array $input): array
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'unsupported budget policy field');
            }
        }
        $start = $this->utc($input['period_start'] ?? null, 'period_start');
        $end = $this->utc($input['period_end'] ?? null, 'period_end');
        if ($end <= $start || $start->add(new DateInterval('P366D')) < $end) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget period must be positive and no longer than 366 days');
        }
        $target = $this->normalizeDraftTarget($input);
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'currency must be a three-letter uppercase code');
        }
        try {
            $limit = BudgetDecimal::normalize($input['limit_amount'] ?? null, false);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'limit_amount must be a positive numeric(20,6) decimal string');
        }
        if (!BudgetDecimal::isPositive($limit)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'limit_amount must be positive');
        }
        $enforcement = $input['enforcement'] ?? null;
        $unpriced = $input['unpriced_policy'] ?? null;
        $unmeasured = $input['unmeasured_policy'] ?? null;
        if (!in_array($enforcement, ['hard', 'soft'], true)
            || !in_array($unpriced, ['block', 'allow'], true)
            || !in_array($unmeasured, ['hold', 'allow'], true)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'budget enforcement or missing-usage policy is invalid');
        }
        if ($enforcement === 'hard' && ($unpriced !== 'block' || $unmeasured !== 'hold')) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'hard budgets require unpriced=block and unmeasured=hold');
        }

        return [
            ...$target,
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end' => $end->format('Y-m-d H:i:s'),
            'currency' => $currency,
            'limit_amount' => $limit,
            'enforcement' => $enforcement,
            'unpriced_policy' => $unpriced,
            'unmeasured_policy' => $unmeasured,
        ];
    }

    /** @param array<string,mixed> $input @return array{scope_kind:string,scope_ref:string,environment_id:?int} */
    public function normalizeScope(array $input): array
    {
        $kind = $input['scope_kind'] ?? 'environment';
        if (!is_string($kind) || !in_array($kind, ['organization', 'application', 'environment', 'service', 'capability', 'model'], true)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Unsupported budget scope');
        }
        $ref = $input['scope_ref'] ?? ($kind === 'environment' ? (string) ($input['environment_id'] ?? '') : null);
        if (!is_string($ref) || trim($ref) !== $ref || $ref === '' || strlen($ref) > 191) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'scope_ref is required');
        }
        if (in_array($kind, ['organization', 'application', 'environment', 'model'], true)) {
            if ((string) $this->id($ref, 'scope_ref') !== $ref) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'scope_ref must use the canonical published directory ID');
            }
        } elseif ($kind === 'service' && $ref !== 'sand_ai') {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'The supported service is sand_ai');
        } elseif ($kind === 'capability' && preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $ref) !== 1) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Capability code is invalid');
        }
        $environment = $kind === 'environment' ? $this->id($ref, 'scope_ref') : null;
        if (isset($input['environment_id']) && $input['environment_id'] !== '' && $input['environment_id'] !== null
            && ($environment === null || $this->id($input['environment_id'], 'environment_id') !== $environment)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'environment_id conflicts with the budget scope');
        }
        return ['scope_kind' => $kind, 'scope_ref' => $ref, 'environment_id' => $environment];
    }

    public function assertTransition(string $from, string $to): void
    {
        if (($from === 'draft' && $to === 'published') || ($from === 'published' && $to === 'archived')) {
            return;
        }
        throw new ApiProblem('SAND_AI_BUDGET_STATE_INVALID', 'budget revision state transition is invalid');
    }

    public function assertMutable(string $state): void
    {
        if ($state !== 'draft') {
            throw new ApiProblem('SAND_AI_BUDGET_IMMUTABLE', 'published or archived budget revisions are immutable');
        }
    }

    private function id(mixed $value, string $field): int
    {
        if (is_int($value)) {
            if ($value <= 0) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', "{$field} must be a positive integer");
            }

            return $value;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', "{$field} must be a positive integer");
        }
        $digits = ltrim($value, '0');
        if ($digits === '' || strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', "{$field} must be a positive integer");
        }

        return (int) $digits;
    }

    private function utc(mixed $value, string $field): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', "{$field} must use UTC Y-m-d H:i:s");
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', "{$field} must use UTC Y-m-d H:i:s");
        }

        return $date;
    }
}
