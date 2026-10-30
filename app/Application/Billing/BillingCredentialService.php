<?php
declare(strict_types=1);
namespace app\Application\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentDraftSaveRequestPolicy;
final class BillingCredentialService
{
    private const RECEIPT_VERSION = 'billing_credential_save@1';
    public function __construct(
        private ProviderBillingCredentialStore $store,
        private BillingConfigCipher $cipher,
        private ?ProviderBillingEligibilityPolicy $eligibility = null,
        private ?BillingAdminKeyPolicy $keyPolicy = null,
    ) {
        $this->eligibility ??= new ProviderBillingEligibilityPolicy();
        $this->keyPolicy ??= new BillingAdminKeyPolicy();
    }

    /** @return array<string, mixed> */
    private function provider(int $id): array
    {
        $provider = $this->store->find($id);
        $this->eligibility->assertIdentity($provider);
        $config = $this->cipher->decrypt($provider['encrypted_config']);
        $eligibleProvider = $provider;
        $eligibleProvider['base_url'] = $config['base_url'] ?? null;
        $this->eligibility->assertEligible($eligibleProvider);

        return $provider + ['decrypted_config' => $config];
    }

    /** @param array<string, mixed> $input @return array{provider_id:int,configured:bool,updated_at:string} */
    public function save(array $input, int $adminId, string $requestKey = ''): array
    {
        if (array_diff(array_keys($input), ['provider_id', 'billing_admin_key', 'billing_organization_id']) !== []) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'unsupported billing credential field');
        }
        $providerId = $this->positiveId($input['provider_id'] ?? null);
        $billingAdminKey = $this->keyPolicy->normalize($input['billing_admin_key'] ?? null, 'SAND_AI_BILLING_VALIDATION_ERROR');
        $organization = $input['billing_organization_id'] ?? null;
        if (!is_string($organization) || preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $organization) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'billing_organization_id invalid');
        }
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        $policy = new DeploymentDraftSaveRequestPolicy();
        $scope = $this->actorScope($adminId, $providerId);
        $operationStore = null;
        $lock = null;
        if ($requestKey !== '') {
            $this->assertRequestKey($requestKey);
            $operationStore = $this->operationStore();
            $lock = $policy->advisoryLockFingerprint($requestKey, $scope);
        }
        $canonical = ['provider_id' => $providerId, 'billing_admin_key' => $billingAdminKey, 'billing_organization_id' => $organization];
        return $this->store->transaction(function () use ($providerId, $billingAdminKey, $organization, $adminId, $requestKey, $policy, $scope, $operationStore, $lock, $canonical): array {
            if ($operationStore !== null) {
                $operationStore->lockOriginalSave($lock);
            }
            $provider = $this->store->lock($providerId);
            $this->eligibility->assertIdentity($provider);
            if ($provider === null) {
                throw new \LogicException('provider guard accepted a missing provider');
            }
            $config = $this->cipher->decrypt($provider['encrypted_config']);
            $eligibleProvider = $provider;
            $eligibleProvider['base_url'] = $config['base_url'] ?? null;
            $this->eligibility->assertEligible($eligibleProvider);
            if ($this->eligibility->driver($eligibleProvider) !== 'openai_organization_costs') {
                throw new ApiProblem('SAND_AI_BILLING_UNSUPPORTED', 'This billing protocol uses the original model connection');
            }
            if ($operationStore !== null) {
                $original = $operationStore->originalSave($adminId, $providerId, $lock);
                if ($original !== null) {
                    $context = $this->originalContext($original, $adminId, $providerId, $lock);
                    $proof = $policy->fingerprintForKeyId($canonical, $requestKey, $scope, $context['key_id']);
                    if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])
                        || !hash_equals($context['request_fingerprint_hmac'], $proof['request_fingerprint_hmac'])) {
                        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original billing credential key belongs to different input');
                    }
                    return $this->originalSnapshot($context, $providerId);
                }
            }
            if (isset($config['billing_organization_id']) && $config['billing_organization_id'] !== $organization) {
                throw new ApiProblem('SAND_AI_BILLING_FACT_CONFLICT', 'billing organization is immutable');
            }
            $proof = $operationStore === null ? null : $policy->fingerprint($canonical, $requestKey, $scope);
            $config['billing_admin_key'] = $billingAdminKey;
            $config['billing_organization_id'] = $organization;
            $this->store->update($providerId, $this->cipher->encrypt($config));
            $receipt = ['provider_id' => $providerId, 'configured' => true, 'updated_at' => date('Y-m-d H:i:s')];
            if ($operationStore === null) {
                $this->store->createAudit($adminId, $providerId);
            } else {
                $operationStore->createOriginalSave($adminId, $providerId, [
                    'version' => self::RECEIPT_VERSION,
                    'provider_id' => $providerId,
                    'lock_sha256' => $lock,
                    'receipt' => $receipt,
                ] + $proof);
            }
            return $receipt;
        });
    }

    /** Original receipts prove the former save, never the current credential contents. */
    public function readSavedByRequestKey(array $input, int $adminId): array
    {
        if (array_diff(array_keys($input), ['provider_id', 'request_key']) !== []) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'unsupported billing credential reference');
        }
        $providerId = $this->positiveId($input['provider_id'] ?? null);
        $key = $input['request_key'] ?? null;
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        if (!is_string($key)) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'request_key invalid');
        }
        $this->assertRequestKey($key);
        $this->eligibility->assertIdentity($this->store->find($providerId));
        $policy = new DeploymentDraftSaveRequestPolicy();
        $scope = $this->actorScope($adminId, $providerId);
        $lock = $policy->advisoryLockFingerprint($key, $scope);
        $row = $this->operationStore()->originalSave($adminId, $providerId, $lock);
        $result = ['operation' => 'credential_save', 'actor_id' => null, 'provider_id' => $providerId,
            'request_key' => $key, 'state' => 'unknown', 'credential' => null];
        if ($row !== null) {
            $context = $this->originalContext($row, $adminId, $providerId, $lock);
            $proof = $policy->idempotencyForKeyId($key, $scope, $context['key_id']);
            if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original billing credential key is not confirmed');
            }
            $result['credential'] = $this->originalSnapshot($context, $providerId);
            $result['actor_id'] = $adminId;
            $result['state'] = 'completed';
        }
        $this->eligibility->assertIdentity($this->store->find($providerId));
        return $result;
    }

    private function operationStore(): BillingCredentialOperationStore
    {
        if (!$this->store instanceof BillingCredentialOperationStore) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Original billing credential receipt storage is unavailable');
        }
        return $this->store;
    }

    private function originalContext(array $row, int $adminId, int $providerId, string $lock): array
    {
        $context = $row['context'] ?? null;
        if (is_string($context)) $context = json_decode($context, true, 512, JSON_THROW_ON_ERROR);
        if (($row['actor_type'] ?? null) !== 'admin' || (string) ($row['actor_ref'] ?? '') !== (string) $adminId
            || ($row['action'] ?? null) !== 'billing.credential.update' || ($row['resource_type'] ?? null) !== 'provider'
            || (int) ($row['resource_id'] ?? 0) !== $providerId || !is_array($context)
            || ($context['version'] ?? null) !== self::RECEIPT_VERSION || ($context['provider_id'] ?? null) !== $providerId
            || ($context['lock_sha256'] ?? null) !== $lock || !is_string($context['key_id'] ?? null)
            || ($context['key_version'] ?? null) !== (new DeploymentDraftSaveRequestPolicy())->keyVersion()
            || !is_string($context['idempotency_hmac'] ?? null) || !is_string($context['request_fingerprint_hmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $context['idempotency_hmac']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $context['request_fingerprint_hmac']) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Original billing credential receipt proof is unavailable');
        }
        return $context;
    }

    private function originalSnapshot(array $context, int $providerId): array
    {
        $receipt = $context['receipt'] ?? null;
        if (!is_array($receipt) || ($receipt['provider_id'] ?? null) !== $providerId
            || ($receipt['configured'] ?? null) !== true || !is_string($receipt['updated_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $receipt['updated_at']) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original billing credential receipt is unavailable');
        }
        return ['provider_id' => $providerId, 'configured' => true, 'updated_at' => $receipt['updated_at']];
    }

    private function actorScope(int $adminId, int $providerId): string
    {
        return 'billing:credential:save:provider:' . $providerId . ':admin:' . $adminId;
    }

    private function assertRequestKey(string $key): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'request_key invalid');
        }
    }

    /** @return array{provider_id:int,configured:bool,driver:string} */
    public function status(int $id): array
    {
        $provider = $this->provider($id);
        $config = $provider['decrypted_config'];
        $driver = $this->eligibility->driver([...$provider, 'base_url' => $config['base_url'] ?? null]);
        if ($driver === 'openrouter_generation') {
            $execution = (new \app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy())->normalize($config);
            return ['provider_id' => $id, 'configured' => $execution['api_key'] !== '', 'driver' => $driver];
        }
        $key = $config['billing_admin_key'] ?? null;

        if ($key === null) {
            return ['provider_id' => $id, 'configured' => false, 'driver' => $driver];
        }
        $this->keyPolicy->normalize($key, 'SAND_AI_BILLING_CONFIG_INVALID');

        return ['provider_id' => $id, 'driver' => $driver, 'configured' => is_string($config['billing_organization_id'] ?? null)
            && preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $config['billing_organization_id']) === 1];
    }

    private function positiveId(mixed $value): int
    {
        if ((!is_int($value) && (!is_string($value) || !ctype_digit($value))) || (int) $value <= 0) {
            throw new ApiProblem('SAND_AI_BILLING_VALIDATION_ERROR', 'provider_id invalid');
        }

        return (int) $value;
    }
}
