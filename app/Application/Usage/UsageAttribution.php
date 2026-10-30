<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Identity\Contract\IdentityContext;

/**
 * Trusted SandIAM attribution carried from an authenticated entry point to a
 * model invocation. Raw caller identifiers remain in the snapshot because
 * SandIAM identifiers are not assumed to be numeric by this application.
 */
final readonly class UsageAttribution
{
    public const PERSISTED_FORMAT_VERSION = 1;

    /** @param array<string, string> $identitySnapshot */
    public function __construct(
        public int $environmentId,
        public ?int $organizationId,
        public ?int $applicationId,
        public ?int $workloadClientId,
        public array $identitySnapshot,
        public string $sourceKind,
        public string $serviceAction,
        public string $capabilityCode = 'chat',
    ) {
    }

    public static function fromIdentity(IdentityContext $context, string $sourceKind, string $serviceAction, string $capabilityCode = 'chat'): self
    {
        return new self(
            $context->environmentId,
            self::numericId($context->organizationId),
            self::numericId($context->applicationId),
            self::numericId($context->workloadClientId),
            [
                'organization_ref' => $context->organizationId,
                'application_ref' => $context->applicationId,
                'workload_client_ref' => $context->workloadClientId,
            ],
            $sourceKind,
            $serviceAction,
            $capabilityCode,
        );
    }

    /** @param array<string, mixed>|null $value */
    public static function fromPersisted(?array $value, int $environmentId, string $sourceKind, string $serviceAction): self
    {
        $persistedEnvironment = null;
        if (array_key_exists('environment_id', $value ?? [])) {
            if (!is_int($value['environment_id']) || $value['environment_id'] < 1) {
                throw new \InvalidArgumentException('Persisted usage attribution environment is invalid');
            }
            $persistedEnvironment = $value['environment_id'];
        }
        if ($persistedEnvironment !== null && $persistedEnvironment !== $environmentId) {
            throw new \InvalidArgumentException('Persisted usage attribution belongs to another environment');
        }
        $snapshot = is_array($value['identity_snapshot'] ?? null) ? $value['identity_snapshot'] : [];

        return new self(
            $environmentId,
            self::persistedProjection($value, 'organization_id'),
            self::persistedProjection($value, 'application_id'),
            self::persistedProjection($value, 'workload_client_id'),
            array_filter($snapshot, 'is_string'),
            $sourceKind,
            $serviceAction,
            is_string($value['capability_code'] ?? null) ? $value['capability_code'] : 'chat',
        );
    }

    /**
     * Rehydrates a task/agent attribution created under the current contract.
     * SandIAM references are opaque strings. Local query ids are merely an
     * optional numeric projection of those same references, never a second
     * identity authority.
     *
     * @param array<string,mixed>|null $value
     */
    public static function fromCurrentPersisted(?array $value, int $environmentId, string $sourceKind, string $serviceAction): self
    {
        if (!is_array($value) || ($value['format_version'] ?? null) !== self::PERSISTED_FORMAT_VERSION) {
            throw new \InvalidArgumentException('Persisted usage attribution format is not current');
        }

        $attribution = self::fromPersisted($value, $environmentId, $sourceKind, $serviceAction);
        self::assertCurrentIdentityProjection($attribution);

        return $attribution;
    }

    /** @return array<string, mixed> */
    public function toPersisted(): array
    {
        return [
            'format_version' => self::PERSISTED_FORMAT_VERSION,
            'environment_id' => $this->environmentId,
            'organization_id' => $this->organizationId,
            'application_id' => $this->applicationId,
            'workload_client_id' => $this->workloadClientId,
            'identity_snapshot' => $this->identitySnapshot,
            'capability_code' => $this->capabilityCode,
        ];
    }

    /**
     * Canonical immutable identity dimensions used by replay and task scopes.
     * Keep opaque SandIAM references and local numeric projections distinct so
     * either one changing invalidates a frozen execution.
     *
     * @return array<string,int|string|null>
     */
    public function canonicalTrustedIdentity(): array
    {
        return [
            'format_version' => self::PERSISTED_FORMAT_VERSION,
            'environment_id' => $this->environmentId,
            'organization_id' => $this->organizationId,
            'organization_ref' => $this->identityReference('organization_ref'),
            'application_id' => $this->applicationId,
            'application_ref' => $this->identityReference('application_ref'),
            'workload_client_id' => $this->workloadClientId,
            'workload_client_ref' => $this->identityReference('workload_client_ref'),
        ];
    }

    /** A stable opaque application domain for uniqueness, including non-numeric SandIAM identifiers. */
    public function applicationScope(): string
    {
        $reference = $this->identitySnapshot['application_ref'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            throw new \InvalidArgumentException('Trusted application attribution is required');
        }
        return hash('sha256', 'sand-ai/application-scope/v1:' . $reference);
    }

    public function withCapability(string $capabilityCode): self
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $capabilityCode) !== 1) {
            throw new \InvalidArgumentException('Capability code is invalid');
        }
        return new self($this->environmentId, $this->organizationId, $this->applicationId, $this->workloadClientId, $this->identitySnapshot, $this->sourceKind, $this->serviceAction, $capabilityCode);
    }

    public static function fallback(int $environmentId, string $sourceKind, string $serviceAction, string $capabilityCode = 'chat'): self
    {
        return new self($environmentId, null, null, null, [], $sourceKind, $serviceAction, $capabilityCode);
    }

    /**
     * Attribution for admin-initiated billable operations. There is no human
     * workload client; the call is owned by the environment's verified
     * organization and application dimensions resolved through SandIAM.
     */
    public static function fromEnvironmentFacts(int $environmentId, string $sourceKind, string $serviceAction, string $capabilityCode = 'chat'): self
    {
        $facts = (new \app\Identity\Infrastructure\HostSandIamEnvironmentFacts())->resolve($environmentId);
        return new self(
            $environmentId,
            $facts['organization_id'],
            $facts['application_id'],
            null,
            [
                'organization_ref' => (string) $facts['organization_id'],
                'application_ref' => (string) $facts['application_id'],
            ],
            $sourceKind,
            $serviceAction,
            $capabilityCode,
        );
    }

    private static function numericId(string $value): ?int
    {
        if (!ctype_digit($value)) {
            return null;
        }

        // SandIAM references are opaque strings. A decimal-only reference may
        // be projected locally only when PHP can represent the exact same
        // positive integer. Never let a large opaque reference truncate into
        // a different local identity.
        $canonical = ltrim($value, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $maximum = (string) PHP_INT_MAX;
        if ($canonical === '0' || strlen($canonical) > strlen($maximum)
            || (strlen($canonical) === strlen($maximum) && strcmp($canonical, $maximum) > 0)) {
            return null;
        }

        return (int) $canonical;
    }

    /** @param array<string,mixed>|null $value */
    private static function persistedProjection(?array $value, string $field): ?int
    {
        if (!is_array($value) || !array_key_exists($field, $value) || $value[$field] === null) {
            return null;
        }
        if (!is_int($value[$field]) || $value[$field] < 1) {
            throw new \InvalidArgumentException('Persisted usage attribution numeric projection is invalid');
        }

        return $value[$field];
    }

    private static function assertCurrentIdentityProjection(self $attribution): void
    {
        foreach ([
            'organization_ref' => $attribution->organizationId,
            'application_ref' => $attribution->applicationId,
            'workload_client_ref' => $attribution->workloadClientId,
        ] as $field => $localId) {
            $reference = $attribution->identityReference($field);
            if ($reference === null || trim($reference) === '') {
                // Environment-owned (admin) operations carry organization and
                // application references but no workload client dimension. A
                // missing client reference is only acceptable when no local
                // client id is claimed either.
                if ($field === 'workload_client_ref' && $localId === null) {
                    continue;
                }
                throw new \InvalidArgumentException('Current usage attribution identity reference is missing');
            }
            // The application has no SandIAM lookup table. A numeric ref can
            // only project to that exact numeric local id; an opaque ref has no
            // local-id projection at all. This rejects an edited id with an
            // unchanged reference without inventing parallel IAM mapping.
            if (ctype_digit($reference)) {
                $projection = self::numericId($reference);
                // A local projection is optional, but if present it must be a
                // lossless projection of the opaque SandIAM reference. Large
                // decimal references therefore remain opaque rather than being
                // truncated by an integer cast.
                if ($localId !== null && ($projection === null || $localId !== $projection)) {
                    throw new \InvalidArgumentException('Current usage attribution numeric projection is inconsistent');
                }
            } elseif ($localId !== null) {
                throw new \InvalidArgumentException('Current usage attribution opaque reference has a local id');
            }
        }
    }

    private function identityReference(string $key): ?string
    {
        $value = $this->identitySnapshot[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
