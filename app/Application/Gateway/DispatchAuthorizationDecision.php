<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** Lock-free SandIAM authorization decision, rebound inside the coordinator. */
final readonly class DispatchAuthorizationDecision
{
    public const MAX_TTL_SECONDS = 60;
    /** @var list<string> */
    public const ALLOWED_VERSIONS = ['sand_iam/v1'];
    public function __construct(public bool $allowed, public string $contextDigest, public string $authorizerVersion, public int $expireAt)
    {
    }

    /** @param list<string>|null $allowedVersions Test-only callers may supply an explicit allowlist. */
    public function isCurrent(int $now, ?array $allowedVersions = null): bool
    {
        return in_array($this->authorizerVersion, $allowedVersions ?? self::ALLOWED_VERSIONS, true)
            && $this->expireAt > $now
            && $this->expireAt <= $now + self::MAX_TTL_SECONDS;
    }

    /** @param array<string,mixed> $routeSnapshot */
    public static function contextDigest(InternalModelInvocationContext $context, UsageAttribution $attribution, string $capabilityCode, ProviderDispatchContext $providerContext, array $routeSnapshot, Provider $provider, ModelDeployment $deployment, ?int $priceRevisionId): string
    {
        ksort($routeSnapshot);
        return hash('sha256', json_encode([
            'workflow_run_id' => $context->workflowRunId, 'task_run_id' => $context->taskRunId, 'task_step_id' => $context->taskStepId, 'workflow_run_file_id' => $context->workflowRunFileId,
            'logical_execution_id' => $context->logicalExecutionId, 'execution_snapshot_sha256' => $context->executionSnapshotSha256,
            'application_scope' => $context->applicationScope, 'attribution_scope' => $attribution->applicationScope(), 'capability' => $capabilityCode,
            'body_digest' => $providerContext->bodyDigest, 'provider_key' => $providerContext->providerKey,
            'route' => $routeSnapshot, 'provider_id' => (int) $provider->id, 'deployment_id' => (int) $deployment->id,
            'deployment_revision' => (int) $deployment->revision, 'model_id' => (int) $deployment->model_id,
            'price_revision_id' => $priceRevisionId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
