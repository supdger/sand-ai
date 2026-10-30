<?php
declare(strict_types=1);
namespace app\Application\Billing;
use app\Api\Support\ApiProblem;
final class ProviderBillingSyncService
{
    public function __construct(
        private ProviderBillingConnector $connector,
        private BillingImportService $imports,
        private ?ProviderBillingSyncAuditStore $auditStore = null,
    ) {
        $this->auditStore ??= new PostgresProviderBillingSyncAuditStore();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function sync(array $input, int $adminId): array
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['provider_id', 'period_start', 'period_end', 'start_unix', 'end_unix', 'idempotency_key'], true)) {
                throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'unsupported billing sync field');
            }
        }
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }

        $lines = $this->connector->fetch($input);
        if ($lines === []) {
            $this->auditStore->recordNoData($adminId, $input);
            return ['status' => 'no_data'];
        }

        $trustedFacts = [];
        foreach ($lines as $index => &$line) {
            $account = $line['_fact_account_scope'] ?? null;
            $namespace = $line['_fact_namespace'] ?? null;
            $request = $line['_fact_request'] ?? null;
            unset($line['_fact_account_scope'], $line['_fact_namespace'], $line['_fact_request']);
            if ($this->connector->providerCode() === 'openai'
                && is_string($account)
                && preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $account) === 1) {
                $trustedFacts[$line['external_line_id']] = ['namespace' => 'openai_organization_costs_v1', 'account_scope' => $account,
                    'stable_key' => (string) $line['external_line_id']];
            } elseif ($this->connector->providerCode() === 'openrouter'
                && $namespace === 'openrouter_generation_v1'
                && is_string($account) && preg_match('/\A[a-f0-9]{64}\z/D', $account) === 1
                && is_array($request) && ($request['generation_id'] ?? null) === $line['external_line_id']) {
                $trustedFacts[$line['external_line_id']] = ['namespace' => $namespace, 'account_scope' => $account,
                    'stable_key' => (string) $line['external_line_id'], 'request' => $request];
            }
        }
        unset($line);
        $import = $input;
        unset($import['start_unix'], $import['end_unix']);

        return $this->imports->import(
            $import + ['source_kind' => $this->connector->providerCode() === 'openrouter' ? 'provider_generation' : 'provider_cost',
                'reference' => $this->connector->providerCode() === 'openrouter' ? 'provider_generation_sync' : 'provider_cost_sync', 'lines' => $lines],
            $adminId,
            BillingImportAuditContext::providerSync($input),
            $trustedFacts,
        );
    }
}
