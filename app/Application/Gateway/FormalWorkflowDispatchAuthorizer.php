<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\WorkflowRunAccessGuard;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\WorkflowRun;

/** Revalidates the original workflow grant at the last safe provider boundary. */
final class FormalWorkflowDispatchAuthorizer implements DispatchAuthorizer
{
    /** @param null|\Closure(int):?WorkflowRun $loadRun @param null|\Closure(array,WorkflowRun):void $revalidate */
    public function __construct(private readonly ?\Closure $loadRun = null, private readonly ?\Closure $revalidate = null)
    {
    }

    public function decide(InternalModelInvocationContext $context, UsageAttribution $attribution, Provider $provider, ModelDeployment $deployment, string $capabilityCode, ProviderDispatchContext $providerContext, array $routeSnapshot, ?int $priceRevisionId): DispatchAuthorizationDecision
    {
        $digest = DispatchAuthorizationDecision::contextDigest($context, $attribution, $capabilityCode, $providerContext, $routeSnapshot, $provider, $deployment, $priceRevisionId);
        if ($capabilityCode !== 'structured_extraction'
            || $attribution->capabilityCode !== 'structured_extraction'
            || $attribution->sourceKind !== 'structured_workflow'
            || $attribution->serviceAction !== 'sand_ai.workflow.run'
            || $context->applicationScope !== $attribution->applicationScope()
            || ($routeSnapshot['selection_kind'] ?? null) !== 'capability_profile'
            || (int) ($routeSnapshot['profile_id'] ?? 0) < 1
            || (int) ($routeSnapshot['profile_revision'] ?? 0) < 1
            || (int) ($routeSnapshot['route_id'] ?? 0) < 1) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Trusted dispatch scope is invalid');
        }
        $authorization = $context->iamAuthorization;
        if (($authorization['version'] ?? null) !== 1
            || !is_int($authorization['expires_at'] ?? null)
            || $authorization['expires_at'] < 1) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
        }
        if ($authorization['expires_at'] <= time()) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'Workload identity context has expired');
        }
        try {
            $run = $this->loadRun === null
                ? WorkflowRun::where('id', $context->workflowRunId)->where('status', 1)->whereNull('delete_time')->find()
                : ($this->loadRun)($context->workflowRunId);
            if (!$run instanceof WorkflowRun
                || (int) $run->id !== $context->workflowRunId
                || (int) $run->task_id !== $context->taskRunId
                || (int) $run->status !== 1
                || $run->delete_time !== null
                || $attribution->organizationId === null || (int) $run->organization_id !== $attribution->organizationId
                || $attribution->applicationId === null || (int) $run->application_id !== $attribution->applicationId
                || (int) $run->environment_id !== $attribution->environmentId
                || $attribution->workloadClientId === null || (int) $run->workload_client_id !== $attribution->workloadClientId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Workflow dispatch attribution is unverified');
            }
            if ($this->revalidate === null) {
                (new WorkflowRunAccessGuard())->revalidateQueued($authorization, $run);
            } else {
                ($this->revalidate)($authorization, $run);
            }
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
        }
        if ($authorization['expires_at'] <= time()) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'Workload identity context has expired');
        }

        return new DispatchAuthorizationDecision(true, $digest, 'sand_iam/v1', min(time() + DispatchAuthorizationDecision::MAX_TTL_SECONDS, $authorization['expires_at']));
    }
}
