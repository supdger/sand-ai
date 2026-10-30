<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/**
 * One versioned, non-secret route contract for every model dispatch.
 *
 * The persistence payload contains hashes of configuration documents rather
 * than their contents.  It is deliberately fail-closed: pre-1.46 snapshots
 * have no route_snapshot_version and cannot be rebound to mutable routing.
 */
final class DispatchRouteSnapshot
{
    public const VERSION = 'route_snapshot@1';

    /**
     * @return array<string,mixed>
     */
    public static function freeze(
        CapabilityProfile $profile,
        CapabilityRoute $route,
        ModelDeployment $deployment,
        AiModel $model,
        Provider $provider,
        ConfigRevision $deploymentRevision,
        ConfigRevision $profileRevision,
        bool $skill = false,
        ?int $maxOutputTokens = null,
        string $selectionKind = 'capability_profile',
        ?string $skillOutputStrategy = null,
    ): array {
        $snapshot = [
            'route_snapshot_version' => self::VERSION,
            'capability_code' => (string) $route->capability_code,
            'profile_id' => (int) $profile->id,
            'profile_revision' => (int) $profile->revision,
            'profile_config_revision_id' => (int) $profileRevision->id,
            'profile_config_payload_sha256' => self::hashValue(self::arrayValue($profileRevision->payload)),
            'data_egress_policy' => (string) $profile->data_egress_policy,
            'route_id' => (int) $route->id,
            'route_kind' => (string) $route->route_kind,
            'route_priority' => (int) $route->priority,
            'route_config_sha256' => self::hashValue(self::arrayValue($route->config)),
            'deployment_id' => (int) $deployment->id,
            'deployment_revision' => (int) $deployment->revision,
            'deployment_remote_model' => (string) $deployment->remote_model,
            'deployment_config_sha256' => self::hashValue(self::arrayValue($deployment->config)),
            'model_id' => (int) $model->id,
            'model_code' => (string) $model->code,
            'provider_id' => (int) $provider->id,
            'provider_adapter' => (string) $provider->adapter,
            'provider_timeout_ms' => (int) $provider->timeout_ms,
            'provider_config_sha256' => self::hashValue(self::arrayValue($provider->encrypted_config)),
            'config_revision_id' => (int) $deploymentRevision->id,
            'config_revision' => (int) $deploymentRevision->revision,
            'config_payload_sha256' => self::hashValue(self::arrayValue($deploymentRevision->payload)),
        ];
        if ($skill) {
            if (!is_int($maxOutputTokens) || $maxOutputTokens < 1 || $selectionKind !== 'capability_profile') {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Skill route has no fixed output budget');
            }
            // API 1.46 deliberately preserves the frozen Skill contract's
            // absence of selection_kind. It is not a profile-route fallback.
            $snapshot['max_output_tokens'] = $maxOutputTokens;
            if ($skillOutputStrategy !== null) {
                $configured = \app\Application\Skill\SkillOutputStrategy::forNewSnapshot(self::arrayValue($deployment->config), (string) $provider->adapter);
                if ($configured !== $skillOutputStrategy) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Skill output strategy differs from deployment configuration');
                $snapshot['skill_output_strategy'] = $configured;
            }
        } else {
            $snapshot['selection_kind'] = $selectionKind;
        }
        $snapshot['snapshot_sha256'] = self::hash($snapshot);

        return self::assert($snapshot, $skill);
    }

    /** @param array<string,mixed> $snapshot */
    public static function assert(array $snapshot, bool $skill): array
    {
        $keys = [
            'route_snapshot_version', 'capability_code', 'profile_id', 'profile_revision', 'profile_config_revision_id', 'profile_config_payload_sha256', 'data_egress_policy',
            'route_id', 'route_kind', 'route_priority', 'route_config_sha256', 'deployment_id', 'deployment_revision', 'deployment_remote_model', 'deployment_config_sha256',
            'model_id', 'model_code', 'provider_id', 'provider_adapter', 'provider_timeout_ms', 'provider_config_sha256', 'config_revision_id', 'config_revision', 'config_payload_sha256',
        ];
        $keys[] = $skill ? 'max_output_tokens' : 'selection_kind';
        $keys[] = 'snapshot_sha256';
        if ($skill && array_key_exists('skill_output_strategy', $snapshot)) {
            $keys[] = 'skill_output_strategy';
            \app\Application\Skill\SkillOutputStrategy::assert($snapshot['skill_output_strategy']);
            if (($snapshot['provider_adapter'] ?? null) !== 'openai_compatible' || ($snapshot['capability_code'] ?? null) !== 'chat') throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Skill output strategy requires its compatible chat route');
        }
        if (array_diff(array_keys($snapshot), $keys) !== [] || array_diff($keys, array_keys($snapshot)) !== []) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot fields are invalid');
        }
        if (($snapshot['route_snapshot_version'] ?? null) !== self::VERSION
            || (!$skill && !in_array($snapshot['selection_kind'] ?? null, ['capability_profile', 'lowest_cost'], true))
            || ($skill && array_key_exists('selection_kind', $snapshot))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot version is unsupported');
        }
        if (($snapshot['selection_kind'] ?? null) === 'lowest_cost'
            && (($snapshot['capability_code'] ?? null) !== 'chat'
                || ($snapshot['route_kind'] ?? null) !== 'model_deployment')) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Lowest-cost selection requires a chat model route');
        }
        foreach (['profile_id', 'profile_revision', 'profile_config_revision_id', 'route_id', 'deployment_id', 'deployment_revision', 'model_id', 'provider_id', 'provider_timeout_ms', 'config_revision_id', 'config_revision'] as $key) {
            if (!is_int($snapshot[$key] ?? null) || $snapshot[$key] < 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot is invalid');
            }
        }
        if (!is_int($snapshot['route_priority'] ?? null) || $snapshot['route_priority'] < 0 || ($skill && (!is_int($snapshot['max_output_tokens'] ?? null) || $snapshot['max_output_tokens'] < 1))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot is invalid');
        }
        foreach (['capability_code', 'data_egress_policy', 'route_kind', 'deployment_remote_model', 'model_code', 'provider_adapter'] as $key) {
            if (!is_string($snapshot[$key] ?? null) || $snapshot[$key] === '') {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot is invalid');
            }
        }
        foreach (['profile_config_payload_sha256', 'route_config_sha256', 'deployment_config_sha256', 'provider_config_sha256', 'config_payload_sha256', 'snapshot_sha256'] as $key) {
            if (!is_string($snapshot[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $snapshot[$key]) !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot is invalid');
            }
        }
        $hashInput = $snapshot;
        unset($hashInput['snapshot_sha256']);
        if (!hash_equals($snapshot['snapshot_sha256'], self::hash($hashInput))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot digest is invalid');
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    public static function matches(array $snapshot, CapabilityProfile $profile, CapabilityRoute $route, ModelDeployment $deployment, AiModel $model, Provider $provider, ConfigRevision $deploymentRevision, ConfigRevision $profileRevision, bool $skill): bool
    {
        try {
            $snapshot = self::assert($snapshot, $skill);
            if ((int) $deployment->model_id !== (int) $model->id
                || (int) $deployment->provider_id !== (int) $provider->id
                || (int) $route->deployment_id !== (int) $deployment->id) {
                return false;
            }
            $currentConfig = self::arrayValue($deployment->config);
            $current = self::freeze(
                $profile,
                $route,
                $deployment,
                $model,
                $provider,
                $deploymentRevision,
                $profileRevision,
                $skill,
                $skill ? ($currentConfig['max_output_tokens'] ?? null) : null,
                $skill ? 'capability_profile' : (string) $snapshot['selection_kind'],
                $skill ? ($snapshot['skill_output_strategy'] ?? null) : null,
            );
        } catch (ApiProblem) {
            return false;
        }

        return hash_equals($snapshot['snapshot_sha256'], $current['snapshot_sha256']);
    }

    /**
     * Resolve only a request option bound to the dispatch's exact immutable
     * deployment revision. Never infer it from the current deployment row.
     *
     * @param array<string,mixed> $route
     */
    public static function reasoningEffortFromRevision(array $route, ConfigRevision $revision): ?string
    {
        $payload = self::verifiedDeploymentRevisionPayload($route, $revision);
        // A published snapshot is authoritative when present. Older published
        // revisions may only carry their tested execution snapshot.
        $snapshotField = array_key_exists('published_execution_snapshot', $payload)
            || array_key_exists('published_execution_snapshot_digest', $payload)
            ? 'published_execution_snapshot' : 'execution_snapshot';
        $digestField = $snapshotField === 'published_execution_snapshot'
            ? 'published_execution_snapshot_digest' : 'execution_snapshot_digest';
        $frozen = $payload[$snapshotField] ?? null;
        $frozenDigest = $payload[$digestField] ?? null;
        $deployment = is_array($frozen) ? ($frozen['deployment'] ?? null) : null;
        $config = is_array($deployment) ? ($deployment['config'] ?? null) : null;
        if (!is_array($frozen) || !is_string($frozenDigest)
            || preg_match('/\A[a-f0-9]{64}\z/', $frozenDigest) !== 1
            || !hash_equals($frozenDigest, self::hashValue($frozen))
            || ($frozen['version'] ?? null) !== 'onboarding_deployment_execution@1'
            || !is_array($deployment) || !is_array($config)
            || (int) ($deployment['id'] ?? 0) !== $route['deployment_id']
            || (int) ($deployment['revision'] ?? 0) !== $route['deployment_revision']
            || (string) ($deployment['remote_model'] ?? '') !== $route['deployment_remote_model']
            || (int) ($deployment['provider_id'] ?? 0) !== $route['provider_id']
            || (int) ($deployment['model_id'] ?? 0) !== $route['model_id']
            || !hash_equals($route['deployment_config_sha256'], self::hashValue($config))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen deployment settings are invalid');
        }
        if (!array_key_exists('reasoning_effort', $config)) {
            return null;
        }
        $effort = $config['reasoning_effort'];
        if (!is_string($effort) || !in_array($effort, ['low', 'high', 'max'], true)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen reasoning setting is invalid');
        }
        return $effort;
    }

    /** Billing may read only a digest-proven original connection fingerprint. */
    public static function providerFingerprintFromRevision(array $route, ConfigRevision $revision): array
    {
        $payload = self::verifiedDeploymentRevisionPayload($route, $revision);
        $published = array_key_exists('published_execution_snapshot', $payload)
            || array_key_exists('published_execution_snapshot_digest', $payload);
        $frozen = $payload[$published ? 'published_execution_snapshot' : 'execution_snapshot'] ?? null;
        $digest = $payload[$published ? 'published_execution_snapshot_digest' : 'execution_snapshot_digest'] ?? null;
        $provider = is_array($frozen) ? ($frozen['provider'] ?? null) : null;
        $deployment = is_array($frozen) ? ($frozen['deployment'] ?? null) : null;
        if (!is_array($frozen) || !is_string($digest) || !hash_equals($digest, self::hashValue($frozen))
            || ($frozen['version'] ?? null) !== 'onboarding_deployment_execution@1'
            || !is_array($provider) || !is_array($deployment)
            || (int) ($provider['id'] ?? 0) !== $route['provider_id']
            || ($provider['adapter'] ?? null) !== $route['provider_adapter']
            || (int) ($deployment['id'] ?? 0) !== $route['deployment_id']
            || (int) ($deployment['revision'] ?? 0) !== $route['deployment_revision']
            || ($deployment['remote_model'] ?? null) !== $route['deployment_remote_model']
            || !is_string($provider['execution_config_version'] ?? null)
            || !is_string($provider['execution_config_hmac_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $provider['execution_config_hmac_sha256']) !== 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original provider connection fingerprint is unavailable');
        }
        return ['version' => $provider['execution_config_version'], 'hmac_sha256' => $provider['execution_config_hmac_sha256']];
    }

    /**
     * Only the historical cross-file result writer may use this narrow
     * digest-proven omission path. New dispatches always require a snapshot.
     *
     * @param array<string,mixed> $route
     */
    public static function legacyRevisionWithoutExecutionSnapshot(array $route, ConfigRevision $revision): bool
    {
        $payload = self::verifiedDeploymentRevisionPayload($route, $revision);
        foreach (['published_execution_snapshot', 'published_execution_snapshot_digest', 'execution_snapshot', 'execution_snapshot_digest'] as $field) {
            if (array_key_exists($field, $payload)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $route @return array<string,mixed> */
    private static function verifiedDeploymentRevisionPayload(array $route, ConfigRevision $revision): array
    {
        self::assert($route, array_key_exists('max_output_tokens', $route));
        $payload = self::arrayValue($revision->payload);
        if ((int) $revision->id !== $route['config_revision_id']
            || (string) $revision->resource_type !== 'model_deployment'
            || (int) $revision->resource_id !== $route['deployment_id']
            || (int) $revision->revision !== $route['deployment_revision']
            || !hash_equals($route['config_payload_sha256'], self::hashValue($payload))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen deployment revision is unavailable');
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private static function arrayValue(mixed $value): array
    {
        $value = is_object($value) ? (array) $value : $value;
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $value */
    private static function hashValue(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /**
     * Public canonical config hash used by any gateway that must store or
     * compare a configuration fingerprint the same way a frozen route
     * snapshot hashes it (profile/route/deployment/provider config fields).
     * @param array<string,mixed>|object $value
     */
    public static function configHash(array|object $value): string
    {
        return self::hashValue(self::arrayValue($value));
    }

    /** @param array<string,mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonical($item), $value);
        }
        $canonical = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) && !is_int($key)) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen route snapshot key is invalid');
            }
            $canonical[(string) $key] = self::canonical($item);
        }
        ksort($canonical, SORT_STRING);
        return $canonical;
    }
}
