<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;

/** Trusted-only input to the narrow persistence boundary; no request body leaks here. */
final readonly class PreDispatchRejectionEvidence
{
    public function __construct(
        public int $environmentId,
        public UsageAttribution $attribution,
        public DispatchExecutionScope $scope,
        public string $requestId,
        public string $requestFingerprint,
        public ApiProblem $problem,
        public ?int $invocationId = null,
    ) {
        if ($environmentId < 1 || $attribution->environmentId !== $environmentId
            || preg_match('/\A[a-f0-9]{64}\z/', $requestFingerprint) !== 1
            || ($invocationId !== null && $invocationId < 1)) {
            throw new \InvalidArgumentException('Pre-dispatch rejection evidence is not trusted');
        }
        // This rejects UsageAttribution::fallback(), empty application/workload
        // references and invalid current-format projections before any write.
        \app\Application\Usage\UsageAttribution::fromCurrentPersisted(
            $attribution->toPersisted(), $environmentId, $attribution->sourceKind, $attribution->serviceAction,
        );
    }

    /** @return array<string,int|string|null> */
    public function persistence(): array
    {
        $stage = PreDispatchRejectionPolicy::stage($this->problem);
        PreDispatchRejectionPolicy::assertStage($stage);
        return [
            'environment_id' => $this->environmentId,
            'application_id' => $this->attribution->applicationId,
            'workload_client_id' => $this->attribution->workloadClientId,
            'trusted_scope' => RequestIdempotencyKey::trustedScopeForAttribution($this->attribution),
            'request_id' => $this->requestId,
            'request_fingerprint' => $this->requestFingerprint,
            'execution_scope' => $this->scope->scope,
            'parent_task_run_id' => $this->scope->taskRunId,
            'parent_task_step_id' => $this->scope->taskStepId,
            'workflow_node_code' => $this->scope->logicalNode,
            'attempt_no' => $this->scope->attemptNo,
            'invocation_id' => $this->invocationId,
            'error_code' => $this->problem->errorCode,
            'rejection_stage' => $stage,
        ];
    }
}
