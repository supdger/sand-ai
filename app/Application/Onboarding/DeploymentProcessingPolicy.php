<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;

/** Versioned deployment processing policy; never infer egress from an adapter. */
final class DeploymentProcessingPolicy
{
    public const VERSION = 'processing_policy@1';

    /** @var array<string,list<string>> */
    private const COMPATIBLE_EGRESS = [
        'local_runtime' => ['local_only', 'private_only', 'approved_cloud'],
        'private_network' => ['private_only', 'approved_cloud'],
        'public_cloud' => ['approved_cloud'],
    ];

    /** @return array{version:string,processing_location:string,compatible_data_egress_policies:list<string>,egress_source:string} */
    public function legacyProtocolDefault(): array
    {
        return [
            'version' => self::VERSION,
            'processing_location' => 'public_cloud',
            'compatible_data_egress_policies' => ['approved_cloud'],
            'egress_source' => 'legacy_protocol_default@1',
        ];
    }

    /** @return array{version:string,processing_location:string,compatible_data_egress_policies:list<string>,egress_source:string} */
    public function create(string $processingLocation): array
    {
        $processingLocation = trim($processingLocation);
        if (!isset(self::COMPATIBLE_EGRESS[$processingLocation])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'processing_location is required and must be a registered location');
        }

        return [
            'version' => self::VERSION,
            'processing_location' => $processingLocation,
            'compatible_data_egress_policies' => self::COMPATIBLE_EGRESS[$processingLocation],
            'egress_source' => 'administrator_versioned_config',
        ];
    }

    /** @param array<string,mixed> $payload @return array{processing_location:?string,compatible_data_egress_policies:list<string>,egress_source:string,policy:?array<string,mixed>} */
    public function fromPayload(array $payload): array
    {
        $policy = $payload['processing_policy'] ?? null;
        if (!is_array($policy) || ($policy['version'] ?? null) !== self::VERSION
            || !is_string($policy['processing_location'] ?? null)
            || !is_array($policy['compatible_data_egress_policies'] ?? null)
            || ($policy['egress_source'] ?? null) !== 'administrator_versioned_config') {
            return ['processing_location' => null, 'compatible_data_egress_policies' => [], 'egress_source' => 'not_configured', 'policy' => null];
        }
        try {
            $expected = $this->create($policy['processing_location']);
        } catch (ApiProblem) {
            return ['processing_location' => null, 'compatible_data_egress_policies' => [], 'egress_source' => 'not_configured', 'policy' => null];
        }
        if (!RequestPayloadFingerprint::same($policy, $expected, [])) {
            return ['processing_location' => null, 'compatible_data_egress_policies' => [], 'egress_source' => 'not_configured', 'policy' => null];
        }

        return [...$expected, 'policy' => $expected];
    }

    /** @param array<string,mixed> $payload */
    public function isApi142Payload(array $payload): bool
    {
        return array_key_exists('processing_policy', $payload)
            || array_key_exists('deployment_validation', $payload)
            || array_key_exists('deployment_verification_claim', $payload);
    }

    /** @param array<string,mixed> $payload */
    private function hasApi142VerificationTag(array $payload): bool
    {
        return array_key_exists('deployment_validation', $payload)
            || array_key_exists('deployment_verification_claim', $payload);
    }

    /** @param array<string,mixed> $payload */
    public function isLegacyProtocolPayload(array $payload): bool
    {
        $legacy = $this->legacyProtocolDefault();
        $current = $payload['processing_policy'] ?? null;
        $frozen = $payload['published_processing_policy'] ?? null;
        return !$this->hasApi142VerificationTag($payload)
            && is_array($current)
            && is_array($frozen)
            // PostgreSQL JSONB may reorder object keys. Compare every field
            // and strict JSON value while retaining list order and tag guards.
            && RequestPayloadFingerprint::same($current, $legacy, [])
            && RequestPayloadFingerprint::same($frozen, $legacy, []);
    }

    /** @param array<string,mixed> $payload @return array{processing_location:?string,compatible_data_egress_policies:list<string>,egress_source:string,policy:?array<string,mixed>} */
    public function fromPublishedPayload(array $payload): array
    {
        $current = $this->fromPayload($payload);
        $hasFrozen = array_key_exists('published_processing_policy', $payload);
        if (!$this->isApi142Payload($payload) && !$hasFrozen) {
            return $current;
        }
        $frozen = $this->fromPayload(['processing_policy' => $payload['published_processing_policy'] ?? null]);
        if ($current['policy'] !== null && $frozen['policy'] !== null && $current['policy'] === $frozen['policy']) {
            return $frozen;
        }
        $legacy = $this->legacyProtocolDefault();
        if ($this->isLegacyProtocolPayload($payload)) {
            return [
                'processing_location' => $legacy['processing_location'],
                'compatible_data_egress_policies' => $legacy['compatible_data_egress_policies'],
                'egress_source' => $legacy['egress_source'],
                'policy' => $legacy,
            ];
        }
        throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'Published deployment processing policy changed');
    }

    /** @param array<string,mixed> $payload */
    public function requireCompatible(array $payload, string $dataEgressPolicy): void
    {
        $policy = $this->fromPayload($payload);
        if ($policy['policy'] === null || !in_array($dataEgressPolicy, $policy['compatible_data_egress_policies'], true)) {
            throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The deployment processing policy does not permit this environment egress');
        }
    }

    /** @param array<string,mixed> $payload */
    public function requirePublishedCompatible(array $payload, string $dataEgressPolicy): void
    {
        $policy = $this->fromPublishedPayload($payload);
        if ($policy['policy'] === null || !in_array($dataEgressPolicy, $policy['compatible_data_egress_policies'], true)) {
            throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'The published deployment processing policy does not permit this environment egress');
        }
    }
}
