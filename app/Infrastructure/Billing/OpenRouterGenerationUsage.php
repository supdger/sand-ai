<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Billing\ProviderBillingEligibilityPolicy;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use app\Infrastructure\Provider\ProviderExecutionConfigFingerprint;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use think\facade\Db;

/** Binds a metadata read to identifiers observed during the original model send. */
final class OpenRouterGenerationUsage
{
    /** @return array{secret:string,config_sha256:string,execution_fingerprint:array{version:string,hmac_sha256:string}} */
    public function credentials(int $providerId): array
    {
        $provider = Db::connect('pgsql')->table('sand_ai_provider')->where('id', $providerId)->whereNull('delete_time')->find();
        if (!is_array($provider)) throw $this->unverified();
        $encrypted = $this->object($provider['encrypted_config'] ?? null);
        $config = (new ProviderSecretDecryptor())->decrypt($encrypted);
        $eligible = [...$provider, 'base_url' => $config['base_url'] ?? null];
        if ((new ProviderBillingEligibilityPolicy())->driver($eligible) !== 'openrouter_generation') throw $this->unverified();
        $execution = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($config);
        // Generation reads use exactly the inference credential. A different
        // billing key cannot establish the account of the original dispatch.
        return ['secret' => $execution['api_key'], 'config_sha256' => RequestPayloadFingerprint::fingerprint($encrypted, []),
            'execution_fingerprint' => (new ProviderExecutionConfigFingerprint())->fromPlaintext('openai_compatible', $config)];
    }

    public function assertCurrent(int $providerId, string $configSha): void
    {
        if (!hash_equals($configSha, $this->credentials($providerId)['config_sha256'])) throw $this->unverified();
    }

    /** @return list<array<string,mixed>> */
    public function candidates(int $providerId, string $from, string $to, array $credential): array
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT e.id AS usage_event_id,e.model_id,e.model_snapshot,e.capability_code,
                    e.create_time,d.id AS dispatch_id,d.invocation_id,d.route_snapshot,
                    d.request_body_digest,d.execution_snapshot_sha256,r.identifier AS generation_id,
                    r.received_time
             FROM sand_ai_usage_event e
             JOIN sand_ai_invocation i ON i.id=e.invocation_id
             JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
             JOIN sand_ai_provider_response_identity r ON r.dispatch_id=d.id AND r.invocation_id=i.id
             WHERE e.provider_id=? AND d.provider_id=e.provider_id AND r.provider_id=e.provider_id
               AND e.organization_id=i.organization_id AND e.application_id=i.application_id
               AND e.environment_id=i.environment_id AND e.workload_client_id=i.workload_client_id
               AND r.organization_id=i.organization_id AND r.application_id=i.application_id
               AND r.environment_id=i.environment_id AND r.workload_client_id=i.workload_client_id
               AND r.model_id=d.model_id AND r.deployment_id=d.deployment_id
               AND r.model_id=e.model_id AND r.deployment_id=e.deployment_id
               AND r.attempt_no=d.attempt_no
               AND r.execution_snapshot_sha256=d.execution_snapshot_sha256
               AND r.request_body_digest=d.request_body_digest
               AND d.state IN ('succeeded','failed') AND d.dispatch_start_time IS NOT NULL
               AND r.source='body.id' AND r.identity_kind='response_id'
               AND NOT EXISTS (SELECT 1 FROM sand_ai_provider_response_identity other
                               WHERE other.dispatch_id=d.id AND other.identity_kind='response_id'
                                 AND other.identifier<>r.identifier)
               AND e.metering_unit IS NULL AND e.capability_code IN ('chat','structured_extraction')
               AND e.create_time>=? AND e.create_time<?
             ORDER BY e.id LIMIT 501",
            [$providerId, $from, $to],
        );
        if (count($rows) > 500) throw new ApiProblem('SAND_AI_BILLING_IMPORT_TOO_LARGE', 'Shorten the generation billing period');
        $seen = [];
        foreach ($rows as &$row) {
            $route = DispatchRouteSnapshot::assert($this->object($row['route_snapshot']), array_key_exists('max_output_tokens', $this->object($row['route_snapshot'])));
            $id = $row['generation_id'];
            if (!is_string($id) || strlen($id) > 128 || preg_match('/\Agen-[0-9A-Za-z-]+\z/D', $id) !== 1
                || isset($seen[$id]) || $route['provider_id'] !== $providerId
                || $route['model_id'] !== (int) $row['model_id']
                || !hash_equals($credential['config_sha256'], $route['provider_config_sha256'])) throw $this->unverified();
            $revision = ConfigRevision::where('id', $route['config_revision_id'])->find();
            if (!$revision instanceof ConfigRevision) throw $this->unverified();
            $original = DispatchRouteSnapshot::providerFingerprintFromRevision($route, $revision);
            if ($original['version'] !== $credential['execution_fingerprint']['version']
                || !hash_equals($original['hmac_sha256'], $credential['execution_fingerprint']['hmac_sha256'])) throw $this->unverified();
            $model = $this->object($row['model_snapshot']);
            if (!is_string($model['code'] ?? null) || $model['code'] !== $route['model_code']) throw $this->unverified();
            $row['model_code'] = $model['code'];
            $row['remote_model'] = $route['deployment_remote_model'];
            unset($row['route_snapshot'], $row['model_snapshot']);
            $seen[$id] = true;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (is_string($value)) {
            try { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { throw $this->unverified(); }
        }
        if (!is_array($value) || array_is_list($value)) throw $this->unverified();
        return $value;
    }

    private function unverified(): ApiProblem
    {
        return new ApiProblem('SAND_AI_BILLING_REQUEST_IDENTITY_UNVERIFIED', 'Original generation and credential identity cannot be verified');
    }
}
