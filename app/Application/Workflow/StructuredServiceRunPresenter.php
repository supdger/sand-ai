<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

/** Pure allowlist projection; persistence is collected by StructuredWorkflowGateway. */
final class StructuredServiceRunPresenter
{
    /**
     * @param array<string,mixed> $legacyRun
     * @param array{code:string,version:string,name:string} $service
     * @param array<string,mixed>|null $artifact
     * @param array<string,mixed>|null $usageSummary UsageMeter::summary only
     * @return array<string,mixed>
     */
    public function present(array $legacyRun, array $service, ?array $artifact, ?array $usageSummary, ?string $auditRef, bool $idempotentReplay): array
    {
        $runId = $legacyRun['id'] ?? null;
        $state = $legacyRun['state'] ?? null;
        if (!is_int($runId) || $runId <= 0 || !is_string($state) || $state === '') {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow run projection is invalid');
        }
        if (!is_string($service['code'] ?? null) || !is_string($service['version'] ?? null) || !is_string($service['name'] ?? null)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_SERVICE_CATALOG_INVALID', 'The structured workflow service is invalid');
        }

        [$result, $citations] = $this->artifactProjection($artifact);
        [$usage, $cost] = $this->usageProjection($service['code'], $usageSummary);

        return $legacyRun + [
            'run_id' => $runId,
            'service' => $service,
            'status' => $state,
            'result' => $result,
            'citations' => $citations,
            'usage' => $usage,
            'cost' => $cost,
            'audit_ref' => $auditRef,
            'idempotent_replay' => $idempotentReplay,
        ];
    }

    /** @param array<string,mixed>|null $artifact @return array{0:array<string,mixed>|null,1:list<array<string,mixed>>} */
    private function artifactProjection(?array $artifact): array
    {
        if ($artifact === null) {
            return [null, []];
        }
        $schema = $artifact['schema'] ?? null;
        $payload = $artifact['payload'] ?? null;
        $citations = $artifact['citations'] ?? null;
        if (!is_array($schema) || array_keys($schema) !== ['code', 'version']
            || !is_string($schema['code']) || $schema['code'] === ''
            || !is_string($schema['version']) || $schema['version'] === ''
            || !is_array($payload) || !is_array($citations) || !array_is_list($citations)) {
            throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The structured workflow artifact projection is invalid');
        }
        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'The structured workflow citation projection is invalid');
            }
        }

        return [['schema' => $schema, 'payload' => $payload], $citations];
    }

    /** @param array<string,mixed>|null $summary @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function usageProjection(string $serviceCode, ?array $summary): array
    {
        if ($serviceCode === 'source_manifest') {
            return [
                ['state' => 'not_applicable', 'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null],
                ['state' => 'not_applicable', 'metered_amount' => null, 'currency' => null],
            ];
        }
        if (!in_array($serviceCode, ['material_analysis', 'legal_analysis'], true)) {
            throw new ApiProblem('SAND_AI_WORKFLOW_SERVICE_CATALOG_INVALID', 'The structured workflow service usage is unsupported');
        }
        if ($summary === null) {
            return [
                ['state' => 'unavailable', 'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null],
                ['state' => 'unavailable', 'metered_amount' => null, 'currency' => null],
            ];
        }
        if (($summary['aggregation'] ?? null) === 'single_file_v1') {
            return $this->fileUsageProjection($summary);
        }

        $usageState = $summary['usage_state'] ?? null;
        $pricingState = $summary['pricing_state'] ?? null;
        $tokens = $summary['tokens'] ?? null;
        if (!in_array($usageState, ['measured', 'unmeasured'], true)
            || !in_array($pricingState, ['priced', 'unpriced'], true)
            || !is_array($tokens)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow usage summary is invalid');
        }

        if ($usageState === 'unmeasured') {
            $usage = ['state' => 'unmeasured', 'input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null];
        } else {
            $input = $tokens['input_tokens'] ?? null;
            $output = $tokens['output_tokens'] ?? null;
            $total = $tokens['total_tokens'] ?? null;
            if (!is_int($input) || !is_int($output) || !is_int($total) || $input < 0 || $output < 0 || $total !== $input + $output) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow token summary is invalid');
            }
            $usage = ['state' => 'measured', 'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total];
        }

        if ($pricingState === 'unpriced') {
            return [$usage, ['state' => 'unpriced', 'metered_amount' => null, 'currency' => null]];
        }
        $amount = $summary['metered_amount'] ?? null;
        $currency = $summary['currency'] ?? null;
        if (!is_string($amount) || preg_match('/^\d+(?:\.\d+)?$/', $amount) !== 1
            || !is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The structured workflow cost summary is invalid');
        }

        return [$usage, ['state' => 'priced', 'metered_amount' => $amount, 'currency' => $currency]];
    }

    /** @param array<string,mixed> $summary @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function fileUsageProjection(array $summary): array
    {
        $usageState = $summary['usage_state'] ?? null;
        $pricingState = $summary['pricing_state'] ?? null;
        $attemptCount = $summary['attempt_count'] ?? null;
        $eventCount = $summary['event_count'] ?? null;
        $missingCount = $summary['missing_event_count'] ?? null;
        $unmeasuredCount = $summary['unmeasured_count'] ?? null;
        $unpricedCount = $summary['unpriced_count'] ?? null;
        $byCurrency = $summary['by_currency'] ?? null;
        if (!in_array($usageState, ['measured', 'unmeasured', 'unavailable'], true)
            || !in_array($pricingState, ['priced', 'mixed_currency', 'unavailable'], true)
            || !is_int($attemptCount) || $attemptCount < 1
            || !is_int($eventCount) || $eventCount < 0 || $eventCount > $attemptCount
            || !is_int($missingCount) || $missingCount < 0 || $missingCount > $attemptCount - $eventCount
            || !is_int($unmeasuredCount) || $unmeasuredCount < 0 || $unmeasuredCount > $eventCount
            || !is_int($unpricedCount) || $unpricedCount < 0 || $unpricedCount > $eventCount
            || !is_array($byCurrency)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material usage aggregate is invalid');
        }
        foreach ($byCurrency as $currency => $amount) {
            if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
                || !is_string($amount) || preg_match('/^\d+(?:\.\d{1,6})?$/', $amount) !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material currency aggregate is invalid');
            }
        }
        $tokens = $summary['tokens'] ?? null;
        if ($usageState === 'measured') {
            $input = is_array($tokens) ? ($tokens['input_tokens'] ?? null) : null;
            $output = is_array($tokens) ? ($tokens['output_tokens'] ?? null) : null;
            $total = is_array($tokens) ? ($tokens['total_tokens'] ?? null) : null;
            if (!is_int($input) || !is_int($output) || !is_int($total)
                || $input < 0 || $output < 0 || $input > PHP_INT_MAX - $output || $total !== $input + $output) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material token aggregate is invalid');
            }
        } else {
            $input = $output = $total = null;
        }
        $usage = [
            'state' => $usageState,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'attempt_count' => $attemptCount,
            'event_count' => $eventCount,
            'missing_event_count' => $missingCount,
            'unmeasured_count' => $unmeasuredCount,
        ];
        $amount = $summary['metered_amount'] ?? null;
        $currency = $summary['currency'] ?? null;
        if ($pricingState === 'priced') {
            if (!is_string($amount) || !is_string($currency)
                || !array_key_exists($currency, $byCurrency) || $byCurrency[$currency] !== $amount
                || count($byCurrency) !== 1 || $missingCount !== 0 || $unpricedCount !== 0) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material price aggregate is invalid');
            }
        } elseif ($amount !== null || $currency !== null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The material price total is invalid');
        }
        return [$usage, [
            'state' => $pricingState,
            'metered_amount' => $amount,
            'currency' => $currency,
            'unpriced_count' => $unpricedCount,
            'by_currency' => $byCurrency,
        ]];
    }
}
