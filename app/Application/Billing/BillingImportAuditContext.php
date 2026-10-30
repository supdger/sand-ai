<?php

declare(strict_types=1);

namespace app\Application\Billing;

final class BillingImportAuditContext
{
    /** @param array<string,mixed> $context */
    private function __construct(
        public string $action,
        public string $summary,
        public array $context,
    ) {
    }

    /** Preserve provider-sync public input instead of hashing its fetched private lines. */
    public function withRecovery(array $input, string $billingFingerprint): self
    {
        return new self($this->action, $this->summary, $this->context + [
            'request_key' => (string) $input['idempotency_key'],
            'input_sha256' => BillingOperationRecovery::inputSha($input),
            'billing_fingerprint' => $billingFingerprint,
        ]);
    }

    public static function manual(): self
    {
        return new self('billing.import.create', 'Imported immutable SandAI billing facts', []);
    }

    /** @param array<string,mixed> $request */
    public static function providerSync(array $request): self
    {
        return new self('billing.sync.import', 'Imported immutable Provider billing facts', [
            'provider_id' => (int) $request['provider_id'],
            'period_start' => (string) $request['period_start'],
            'period_end' => (string) $request['period_end'],
            'request_key' => (string) $request['idempotency_key'],
            'input_sha256' => BillingOperationRecovery::inputSha(BillingOperationRecovery::syncInput($request)),
        ]);
    }
}
