<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\PriceRevision;
use app\Infrastructure\Persistence\Model\PriceRate;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Application\Task\TaskExecutionLease;
use app\Application\Agent\AgentGateway;
use think\facade\Db;

/** One fixed-order transaction is the only path from prepared to dispatching. */
final class DispatchCoordinator
{
    public function __construct(private readonly DispatchTransactionPort $transaction = new PostgresDispatchTransactionPort())
    {
    }

    /** @param array<string,mixed> $record */
    public function prepare(array $record): InvocationDispatch
    {
        if (($record['parent_task_run_id'] ?? null) === null
            && ($record['parent_task_step_id'] ?? null) === null) {
            return (new DispatchLedgerGateway())->prepare($record);
        }
        return $this->transaction->transaction(function () use ($record): InvocationDispatch {
            // The caller's budget/invocation preparation owns the outer
            // transaction. Insert first, then take the parent locks in the
            // same dispatch -> task -> step order as claim and cancellation.
            $dispatch = (new DispatchLedgerGateway())->prepare($record);
            $task = TaskRun::where('id', (int) $dispatch->parent_task_run_id)
                ->where('environment_id', (int) $dispatch->environment_id)->lock(true)->find();
            $step = TaskStep::where('id', (int) $dispatch->parent_task_step_id)
                ->where('task_id', (int) $dispatch->parent_task_run_id)->lock(true)->find();
            $invocation = Invocation::where('id', (int) $dispatch->invocation_id)->find();
            if ($task === null || $step === null || $invocation === null
                || (string) $task->state !== 'running' || (string) $step->state !== 'running'
                || $task->canceled_at !== null || $task->content_purged_time !== null
                || (string) $task->lease_owner !== (string) $dispatch->lease_owner
                || !hash_equals((string) $task->lease_token, (string) $dispatch->lease_token)
                || (int) $task->lease_fence !== (int) $dispatch->lease_fence
                || !is_string($task->lease_expire_time) || $task->lease_expire_time <= date('Y-m-d H:i:s')
                || (int) $dispatch->attempt_no < 1
                || (in_array((string) $dispatch->execution_scope, ['ai_task', 'agent'], true)
                    && (int) $step->attempt_count !== (int) $dispatch->attempt_no)
                || ((string) $dispatch->execution_scope === 'structured_workflow'
                    && ((int) $invocation->parent_task_run_id !== (int) $task->id
                        || (int) $invocation->parent_task_step_id !== (int) $step->id
                        || (int) $invocation->execution_attempt_no !== (int) $dispatch->attempt_no))
                || (int) $invocation->environment_id !== (int) $task->environment_id
                || (int) $invocation->organization_id !== (int) $task->organization_id
                || (int) $invocation->application_id !== (int) $task->application_id
                || (int) $invocation->workload_client_id !== (int) $task->workload_client_id
                || (int) $dispatch->application_id !== (int) $task->application_id) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task scope changed before provider preparation');
            }
            return $dispatch;
        });
    }

    /**
     * Executes the provider only after a successful claim has moved the ledger
     * to dispatching. The caller remains responsible for the terminal ledger
     * transition, which must happen after the provider's result is available.
     *
     * @template TResult
     * @param \Closure():TResult $providerCall
     * @return TResult
     */
    public function executeClaimedProvider(\Closure $providerCall): mixed
    {
        $this->assertOutsideTransaction();
        return $providerCall();
    }

    private function assertOutsideTransaction(): void
    {
        $connection = Db::connect('pgsql');
        $pdo = $connection instanceof \think\db\PDOConnection ? $connection->getPdo() : false;
        if (!$pdo instanceof \PDO || $pdo->inTransaction()) {
            throw new ApiProblem('SAND_AI_DISPATCH_TRANSACTION_OPEN', '模型派发必须在领取事务提交后执行，未发送请求');
        }
    }

    /** Rebind only a proven unsent meeting node to its current task lease. */
    public function rebindMeetingPrepared(Invocation $invocation, InvocationDispatch $dispatch, DispatchExecutionScope $scope, UsageAttribution $attribution, string $requestId, string $requestFingerprint): InvocationDispatch
    {
        return $this->transaction->transaction(function () use ($invocation, $dispatch, $scope, $attribution, $requestId, $requestFingerprint): InvocationDispatch {
            $reservation = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id')->order('id')->find();
            $reservationId = $reservation === null ? null : (int) $reservation->id;
            $group = $this->lockPreparedRejectionBudget($reservationId, (int) $invocation->id);
            $lockedInvocation = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
            $ledger = InvocationDispatch::where('id', (int) $dispatch->id)->where('state', 'prepared')->whereNull('dispatch_start_time')->lock(true)->find();
            $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $attribution->environmentId)->lock(true)->find();
            $step = TaskStep::where('id', $scope->taskStepId)->where('task_id', $scope->taskRunId)->lock(true)->find();
            if ($lockedInvocation === null || $ledger === null || $task === null || $step === null
                || (string) $task->state !== 'running' || (string) $step->state !== 'running'
                || $task->canceled_at !== null || $task->content_purged_time !== null
                || (string) $task->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $task->lease_token, $scope->leaseToken)
                || (int) $task->lease_fence !== $scope->leaseFence
                || !is_string($task->lease_expire_time) || $task->lease_expire_time <= date('Y-m-d H:i:s')
                || (int) $step->attempt_count !== $scope->attemptNo
                || (int) $lockedInvocation->environment_id !== $attribution->environmentId
                || ($lockedInvocation->organization_id === null ? null : (int) $lockedInvocation->organization_id) !== $attribution->organizationId
                || ($lockedInvocation->application_id === null ? null : (int) $lockedInvocation->application_id) !== $attribution->applicationId
                || ($lockedInvocation->workload_client_id === null ? null : (int) $lockedInvocation->workload_client_id) !== $attribution->workloadClientId
                || (string) $lockedInvocation->request_id !== $requestId
                || (int) $ledger->environment_id !== $attribution->environmentId
                || ($ledger->application_id === null ? null : (int) $ledger->application_id) !== $attribution->applicationId
                || (string) $ledger->service_action !== $attribution->serviceAction
                || (string) $ledger->source_kind !== $attribution->sourceKind) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting prepared scope or current lease changed');
            }
            MeetingSummaryReceiptScope::assertCurrent($scope, $attribution->environmentId, $requestId, true);
            MeetingSummaryReceiptScope::assertExisting($lockedInvocation, $ledger, $scope, $requestId, $requestFingerprint);
            $changed = InvocationDispatch::where('id', (int) $ledger->id)->where('state', 'prepared')->whereNull('dispatch_start_time')->update([
                'lease_owner' => $scope->leaseOwner, 'lease_token' => $scope->leaseToken, 'lease_fence' => $scope->leaseFence,
            ]);
            if ($changed !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting prepared dispatch changed before lease recovery');
            $ledger->lease_owner = $scope->leaseOwner;
            $ledger->lease_token = $scope->leaseToken;
            $ledger->lease_fence = $scope->leaseFence;
            if ($reservationId !== null && !$this->reservationGroupDispatchable($group, (int) $invocation->id, gmdate('Y-m-d H:i:s'))) {
                $problem = new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Original meeting reservation cannot be resumed');
                $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $group);
                $ledger->state = 'not_dispatched';
            }
            return $ledger;
        });
    }

    /**
     * Shared prepared-to-dispatching boundary for non-workflow provider paths.
     * Structured work keeps claimForDispatch() below because it has the extra
     * frozen-route and SandIAM authorization proof.
     */
    public function claimForScope(int $dispatchId, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, Provider $provider, ModelDeployment $deployment, int $modelId, string $capabilityCode, ?int $priceRevisionId, ?TaskExecutionLease $parentLease = null, ?ProviderDispatchContext $meetingRequest = null, ?ProviderIdempotencyCapability $meetingCapability = null, ?UsageAttribution $attribution = null): DispatchClaimOutcome
    {
        return $this->transaction->transaction(function () use ($dispatchId, $invocation, $reservationId, $scope, $provider, $deployment, $modelId, $capabilityCode, $priceRevisionId, $parentLease, $meetingRequest, $meetingCapability, $attribution): DispatchClaimOutcome {
            $policySelection = \app\Application\Budget\PreparedBudgetPolicySelection::lock((int) $invocation->id, $dispatchId, $attribution);
            if ($scope->scope === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->lockForInvocation((int) $invocation->id);
            $reservationGroup = $this->lockPreparedRejectionBudget($reservationId, (int) $invocation->id);
            $lockedInvocation = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
            $ledger = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')->lock(true)->find();
            // The caller's Invocation is never an authority to close a ledger
            // belonging to another invocation. Reject before any terminal or
            // budget write so both records remain unchanged for investigation.
            if ($ledger !== null && !PreparedDispatchRejectionPolicy::ledgerBelongsToInvocation((int) $ledger->invocation_id, (int) $invocation->id)) {
                return DispatchClaimOutcome::rejected(new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Dispatch ledger belongs to another invocation'));
            }
            // A late worker must not reject or release another worker's
            // re-bound prepared invocation, even before scope/route checks.
            if ($ledger !== null && ((string) $ledger->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $ledger->lease_token, $scope->leaseToken)
                || (int) $ledger->lease_fence !== $scope->leaseFence)) {
                return DispatchClaimOutcome::rejected(new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Prepared dispatch belongs to another worker lease'));
            }
            if ($lockedInvocation === null || $ledger === null
                || (string) $ledger->logical_execution_id !== $scope->logicalExecutionId
                || (string) ($ledger->execution_scope ?? '') !== $scope->scope
                || (int) $ledger->attempt_no !== $scope->attemptNo
                || (int) $ledger->provider_id !== (int) $provider->id || (int) $ledger->deployment_id !== (int) $deployment->id
                || (int) $ledger->model_id !== $modelId || (string) $ledger->capability_code !== $capabilityCode) {
                $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen dispatch scope is no longer dispatchable');
                if ($lockedInvocation !== null && $ledger !== null) {
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                }
                return DispatchClaimOutcome::rejected($problem);
            }
            if (in_array($scope->scope, ['ai_task', 'agent'], true)) {
                $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', (int) $ledger->environment_id)->lock(true)->find();
                $step = TaskStep::where('id', $scope->taskStepId)->where('task_id', $scope->taskRunId)->lock(true)->find();
                if ($task === null || $step === null || (string) $task->state !== 'running' || (string) $step->state !== 'running'
                    || (string) $task->lease_owner !== $scope->leaseOwner || (string) $task->lease_token !== $scope->leaseToken
                    || (int) $task->lease_fence !== $scope->leaseFence || $task->canceled_at !== null) {
                    // Losing the task lease is a safe refusal, not authority
                    // to terminalize a prepared invocation for its next owner.
                    return DispatchClaimOutcome::rejected(new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task scope lease changed before provider dispatch'));
                }
            }
            if ($parentLease !== null) {
                $task = TaskRun::where('id', $parentLease->taskId)
                    ->where('environment_id', (int) $ledger->environment_id)->lock(true)->find();
                $step = TaskStep::where('task_id', $parentLease->taskId)
                    ->where('sequence_no', 1)->lock(true)->find();
                $taskInput = $task === null ? null : $task->input;
                $taskInput = is_object($taskInput) ? (array) $taskInput : $taskInput;
                $binding = is_array($taskInput) ? ($taskInput['_agent_definition'] ?? null) : null;
                if ($scope->scope !== 'embedding' || (string) $ledger->source_kind !== 'knowledge_query'
                    || (string) $ledger->service_action !== 'sand_ai.knowledge.query'
                    || $task === null || $step === null || (string) $task->task_type !== 'agent_run'
                    || !is_array($binding)
                    || (int) ($binding['knowledge_publication_id'] ?? 0) !== (int) $lockedInvocation->knowledge_publication_id
                    || (int) ($binding['knowledge_version_id'] ?? 0) !== (int) $lockedInvocation->knowledge_base_version_id
                    || (string) ($binding['knowledge_scope_sha256'] ?? '') !== (string) $lockedInvocation->knowledge_source_scope_sha256
                    || !AgentGateway::ownsEmbeddingChildRequest(
                        $task, (int) $lockedInvocation->knowledge_publication_id,
                        (string) $lockedInvocation->request_id,
                    )
                    || (string) $task->state !== 'running' || (string) $step->state !== 'running'
                    || (int) $task->organization_id !== (int) $lockedInvocation->organization_id
                    || (int) $task->application_id !== (int) $lockedInvocation->application_id
                    || (int) $task->workload_client_id !== (int) $lockedInvocation->workload_client_id
                    || (string) $task->lease_owner !== $parentLease->owner
                    || !hash_equals((string) $task->lease_token, $parentLease->token)
                    || (int) $task->lease_fence !== $parentLease->fence || $task->canceled_at !== null) {
                    $problem = new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent lease changed before embedding dispatch');
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
            }
            // Follow the structured claim order after the ledger/task locks:
            // deployment, provider, published revision, price, profile, route.
            $lockedDeployment = ModelDeployment::where('id', (int) $deployment->id)->where('status', 1)->lock(true)->find();
            $lockedProvider = Provider::where('id', (int) $provider->id)->where('status', 1)->lock(true)->find();
            $lockedModel = AiModel::where('id', $modelId)->where('status', 1)->lock(true)->find();
            $revision = $lockedDeployment === null ? null : ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $lockedDeployment->id)->where('revision', (int) $lockedDeployment->revision)->where('state', 'published')->where('status', 1)->lock(true)->find();
            $route = is_object($ledger->route_snapshot) ? (array) $ledger->route_snapshot : $ledger->route_snapshot;
            $priceSnapshot = is_object($ledger->price_snapshot) ? (array) $ledger->price_snapshot : $ledger->price_snapshot;
            $price = $priceRevisionId === null ? null : PriceRevision::where('id', $priceRevisionId)->where('provider_id', (int) $provider->id)->where('model_id', $modelId)->where('capability_code', $capabilityCode)->where('state', 'published')->lock(true)->find();
            // Lock rates before profile and route so generic and structured
            // claims observe the same immutable price decision in one order.
            $priceMatches = $this->lockedPriceSnapshotMatches($priceSnapshot, $price, $priceRevisionId, (int) $provider->id, $modelId, $capabilityCode);
            $selectionIsProfile = is_array($route) && DispatchClaimRoutePolicy::usesCapabilityProfileRoute($route, $scope->scope, (string) $ledger->source_kind);
            $frozenSkillRoute = is_array($route) && DispatchClaimRoutePolicy::isFrozenSkillRoute($route, $scope->scope, (string) $ledger->source_kind);
            $profileEnvironmentId = $selectionIsProfile
                ? $this->profileEnvironmentForClaim($lockedInvocation, $ledger, $route, $scope)
                : null;
            $profile = $profileEnvironmentId === null ? null : CapabilityProfile::where('id', (int) ($route['profile_id'] ?? 0))->where('environment_id', $profileEnvironmentId)->where('revision', (int) ($route['profile_revision'] ?? 0))->whereIn('status', [1, 2])->lock(true)->find();
            $capabilityRoute = $selectionIsProfile && $lockedDeployment !== null ? CapabilityRoute::where('id', (int) ($route['route_id'] ?? 0))->where('profile_id', (int) ($route['profile_id'] ?? 0))->where('capability_code', $capabilityCode)->where('deployment_id', (int) $lockedDeployment->id)->where('status', 1)->lock(true)->find() : null;
            $profileRevision = $selectionIsProfile ? ConfigRevision::where('id', (int) ($route['profile_config_revision_id'] ?? 0))->where('resource_type', 'capability_profile')->where('resource_id', (int) ($route['profile_id'] ?? 0))->where('revision', (int) ($route['profile_revision'] ?? 0))->where('state', 'published')->where('status', 1)->lock(true)->find() : null;
            $routeMatches = $lockedProvider !== null && $lockedDeployment !== null && $lockedModel !== null && $revision !== null && $profile !== null && $capabilityRoute !== null && $profileRevision !== null
                && is_array($route) && DispatchRouteSnapshot::matches($route, $profile, $capabilityRoute, $lockedDeployment, $lockedModel, $lockedProvider, $revision, $profileRevision, $frozenSkillRoute);
            if ($lockedProvider === null || $lockedDeployment === null || $lockedModel === null || $revision === null || ($priceRevisionId !== null && $price === null)
                || !$selectionIsProfile || $profile === null || $capabilityRoute === null
                || !$priceMatches
                || !$routeMatches) {
                $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen provider route changed before dispatch');
                $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                return DispatchClaimOutcome::rejected($problem);
            }
            if ($reservationId !== null && !$this->reservationGroupDispatchable($reservationGroup, (int) $invocation->id, gmdate('Y-m-d H:i:s'))) {
                $problem = new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
                $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                return DispatchClaimOutcome::rejected($problem);
            }
            try {
                if ($capabilityCode === 'rerank' && $scope->scope === 'rerank') {
                    (new \app\Application\Knowledge\KnowledgeProfessionalRerankBudget())->assertPrepared(
                        $lockedInvocation, $ledger, $reservationGroup[0]['reservation'] ?? null, $policySelection,
                    );
                } else {
                (new \app\Application\Budget\ChatBudgetGuard())->assertPreparedCapacityForDispatch(
                    $lockedInvocation, $ledger, $reservationGroup[0]['reservation'] ?? null, $attribution, $policySelection,
                );
                }
            } catch (ApiProblem $problem) {
                $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                return DispatchClaimOutcome::rejected($problem);
            }
            if ($meetingRequest !== null) {
                try {
                    $frozenPrice = \app\Application\Usage\CallStartPricingSnapshot::fromPersistence(
                        $lockedInvocation->call_started_at, $lockedInvocation->call_start_pricing_state,
                        $lockedInvocation->call_start_price_revision_id, $lockedInvocation->call_start_price_snapshot,
                    )->dispatchSnapshot();
                } catch (ApiProblem $problem) {
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                if ($meetingCapability === null || $scope->scope !== 'ai_task'
                    || $task->content_purged_time !== null
                    || (int) $step->attempt_count !== $scope->attemptNo
                    || $ledger->dispatch_start_time !== null
                    || (string) $ledger->request_body_digest !== $meetingRequest->bodyDigest
                    || (string) $ledger->provider_idempotency_mode !== $meetingCapability->mode
                    || (string) $ledger->provider_idempotency_key !== (string) $meetingRequest->providerKey
                    || (string) $ledger->execution_snapshot_sha256 !== $scope->executionSnapshotSha256
                    || (int) $ledger->parent_task_run_id !== $scope->taskRunId
                    || (int) $ledger->parent_task_step_id !== $scope->taskStepId
                    || (string) $ledger->workflow_node_code !== $scope->logicalNode
                    || (int) $lockedInvocation->deployment_id !== (int) $lockedDeployment->id
                    || (int) $lockedInvocation->model_id !== $modelId
                    || !RequestPayloadFingerprint::same($priceSnapshot, $frozenPrice, [])) {
                    $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original meeting request or price changed before dispatch');
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                // Configuration lock waits can outlive the lease validated at
                // rebind. Recheck the locked task at the final send boundary.
                if (!is_string($task->lease_expire_time) || $task->lease_expire_time <= date('Y-m-d H:i:s')) {
                    $problem = new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting task lease expired before provider dispatch');
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
            }
            try {
                (new DispatchLedgerGateway())->dispatching($dispatchId, $scope->leaseOwner, $scope->leaseToken, $scope->leaseFence);
            } catch (ApiProblem $problem) {
                if ($problem->errorCode !== 'SAND_AI_TASK_LEASE_LOST') {
                    $this->rejectPreparedScopeLocked($ledger, $lockedInvocation, $reservationId, $scope, $problem, $reservationGroup);
                }
                return DispatchClaimOutcome::rejected($problem);
            }
            return DispatchClaimOutcome::claimed($lockedProvider, $lockedDeployment);
        });
    }

    /** Only the same live send boundary can prove a claimed request was refused before I/O. */
    public function finalizeRejectedSendAuthorization(int $dispatchId, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, ApiProblem $problem, ProviderSendBoundary $boundary): void
    {
        if (!$boundary->rejectedAuthorization($problem)) {
            throw new \LogicException('Unsent authorization rejection requires its original send boundary');
        }
        (new DispatchFinalizer())->success(function () use ($dispatchId, $invocation, $reservationId, $scope, $problem, $boundary): void {
            $group = $this->lockPreparedRejectionBudget($reservationId, (int) $invocation->id);
            $lockedInvocation = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
            $ledger = InvocationDispatch::where('id', $dispatchId)->where('invocation_id', (int) $invocation->id)->where('state', 'dispatching')->lock(true)->find();
            if (!$boundary->rejectedAuthorization($problem) || $lockedInvocation === null || $ledger === null
                || (string) $ledger->execution_scope !== $scope->scope
                || (string) $ledger->logical_execution_id !== $scope->logicalExecutionId
                || (string) $ledger->execution_snapshot_sha256 !== $scope->executionSnapshotSha256
                || (int) $ledger->attempt_no !== $scope->attemptNo
                || (string) $ledger->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $ledger->lease_token, $scope->leaseToken)
                || (int) $ledger->lease_fence !== $scope->leaseFence) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Unsent claimed dispatch changed before authorization rejection');
            }
            $summary = mb_strcut($problem->getMessage(), 0, 512, 'UTF-8');
            // dispatch_start_time records the claim, not proof of transport.
            // This live boundary proved that transmission never began.
            $changed = InvocationDispatch::where('id', $dispatchId)->where('invocation_id', (int) $invocation->id)
                ->where('state', 'dispatching')->where('lease_owner', $scope->leaseOwner)
                ->where('lease_token', $scope->leaseToken)->where('lease_fence', $scope->leaseFence)->update([
                    'state' => 'not_dispatched', 'dispatch_complete_time' => date('Y-m-d H:i:s'),
                    'error_code' => $problem->errorCode, 'error_summary' => $summary,
                ]);
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Claimed dispatch changed before unsent rejection');
            }
            if ($reservationId !== null
                && $this->reservationGroupRejectionAction($group, (int) $invocation->id) === PreparedDispatchRejectionPolicy::RELEASE) {
                (new \app\Application\Budget\ChatBudgetGuard())->release($reservationId, 'dispatch_not_dispatched');
            }
            $updated = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'),
                'error_code' => $problem->errorCode, 'error_summary' => $summary,
            ]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before unsent authorization rejection');
            }
            AuditLog::create([
                'actor_type' => 'system_worker', 'actor_ref' => (string) ($scope->taskRunId ?? 0),
                'action' => 'model.dispatch.not_dispatched', 'resource_type' => 'invocation', 'resource_id' => $invocation->id,
                'summary' => 'Current authorization refused provider transmission', 'context' => ['error_code' => $problem->errorCode],
            ]);
        });
    }

    public function finalizeProviderOutcomeForScope(int $dispatchId, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, string $capabilityCode, ApiProblem $problem, ProviderDispatchTerminalization $outcome): void
    {
        if ($outcome->isSucceeded() || $outcome->ledgerState === 'not_dispatched') {
            throw new \LogicException('A dispatching provider finalizer cannot accept this outcome');
        }
        (new DispatchFinalizer())->success(function () use ($dispatchId, $invocation, $reservationId, $scope, $capabilityCode, $problem, $outcome): void {
            if ($capabilityCode === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->lockForInvocation((int) $invocation->id);
            (new DispatchFinalizer())->lockInternalTerminal($reservationId, (int) $invocation->id, $dispatchId);
            (new DispatchLedgerGateway())->terminal($dispatchId, $outcome->ledgerState, $problem->errorCode, $problem->getMessage());
            if ($reservationId !== null) {
                $guard = new \app\Application\Budget\ChatBudgetGuard();
                if ($outcome->releasesBudget()) {
                    $guard->release($reservationId, 'provider_rejected');
                } elseif ($outcome->holdsBudget()) {
                    $guard->settle($reservationId, null, 'dispatch_ambiguous', null, $capabilityCode);
                }
            }
            $errorCode = $outcome->isAmbiguous() ? 'SAND_AI_DISPATCH_AMBIGUOUS' : $problem->errorCode;
            $summary = $outcome->isAmbiguous() ? 'Provider dispatch outcome is ambiguous' : mb_strcut($problem->getMessage(), 0, 512, 'UTF-8');
            $affected = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => $outcome->invocationState, 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $errorCode, 'error_summary' => $summary,
            ]);
            if ($affected !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before provider outcome finalization');
        });
    }

    public function finalizeSuccessForScope(int $dispatchId, ?InternalModelInvocationContext $internalContext, ProviderDispatchTerminalization $outcome): void
    {
        if (!$outcome->isSucceeded()) {
            throw new \LogicException('A successful provider finalizer requires a succeeded outcome');
        }
        (new DispatchLedgerGateway())->terminal($dispatchId, $outcome->ledgerState, null, null, $internalContext);
    }

    /** @param array<string,mixed> $route */
    private function profileEnvironmentForClaim(
        Invocation $invocation, InvocationDispatch $ledger, array $route, DispatchExecutionScope $scope,
    ): ?int {
        if ($scope->scope !== 'embedding' || (string) $ledger->source_kind !== 'knowledge_query') {
            return (int) $ledger->environment_id;
        }
        if ((string) $ledger->service_action !== 'sand_ai.knowledge.query'
            || (string) $ledger->capability_code !== 'embedding'
            || (int) $ledger->environment_id !== (int) $invocation->environment_id
            || (int) ($invocation->knowledge_publication_id ?? 0) < 1
            || (int) ($invocation->knowledge_base_version_id ?? 0) < 1
            || (int) ($invocation->organization_id ?? 0) < 1
            || (int) ($invocation->application_id ?? 0) < 1
            || !is_string($invocation->knowledge_source_scope_sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $invocation->knowledge_source_scope_sha256) !== 1) {
            return null;
        }
        $rows = Db::connect('pgsql')->query(
            "SELECT p.owner_environment_id
               FROM sand_ai_knowledge_publication p
               JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id
               JOIN sand_ai_knowledge_base_version v ON v.knowledge_base_id=kb.id
               JOIN sand_ai_knowledge_embedding_active a ON a.knowledge_base_version_id=v.id
               JOIN sand_ai_knowledge_embedding_index i ON i.id=a.index_id
              WHERE p.id=? AND p.state='published'
                AND p.consumer_environment_id=? AND p.consumer_organization_id=?
                AND p.consumer_application_id=?
                AND kb.environment_id=p.owner_environment_id AND kb.state='published'
                AND kb.status=1 AND kb.delete_time IS NULL AND kb.active_version_id=v.id
                AND v.id=? AND v.state='published'
                AND v.source_scope_sha256=? AND v.data_class IS NOT DISTINCT FROM ?
                AND i.owner_environment_id=p.owner_environment_id
                AND i.knowledge_base_version_id=v.id AND i.state='ready'
                AND i.source_snapshot_sha256=v.snapshot_sha256
                AND i.source_scope_sha256=v.source_scope_sha256
                AND i.profile_id=? AND i.profile_revision=? AND i.route_id=?
                AND i.deployment_id=? AND i.deployment_revision=?
                AND i.model_id=? AND i.provider_id=?
                AND i.provider_config_sha256=?
              FOR SHARE OF p,kb,v,a,i",
            [
                (int) $invocation->knowledge_publication_id, (int) $ledger->environment_id,
                (int) $invocation->organization_id, (int) $invocation->application_id,
                (int) $invocation->knowledge_base_version_id,
                $invocation->knowledge_source_scope_sha256, $invocation->data_class,
                (int) ($route['profile_id'] ?? 0), (int) ($route['profile_revision'] ?? 0),
                (int) ($route['route_id'] ?? 0), (int) ($route['deployment_id'] ?? 0),
                (int) ($route['deployment_revision'] ?? 0), (int) ($route['model_id'] ?? 0),
                (int) ($route['provider_id'] ?? 0), (string) ($route['provider_config_sha256'] ?? ''),
            ],
        );
        return count($rows) === 1 ? (int) $rows[0]['owner_environment_id'] : null;
    }

    /** @param array<string,mixed>|null $snapshot */
    private function lockedPriceSnapshotMatches(?array $snapshot, ?PriceRevision $price, ?int $priceRevisionId, int $providerId, int $modelId, string $capabilityCode): bool
    {
        if (!is_array($snapshot) || !is_string($snapshot['called_at'] ?? null) || !is_string($snapshot['pricing_state'] ?? null)) {
            return false;
        }
        if ($capabilityCode === 'rerank') {
            try {
                $frozen = \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::fromPersistence(
                    $snapshot['called_at'], $snapshot['pricing_state'], $snapshot['price_revision_id'] ?? null, $snapshot['price_snapshot'] ?? null,
                );
                if ($frozen->revisionId !== $priceRevisionId) return false;
                if ($priceRevisionId === null) return $price === null;
                if ($price === null || (int) $price->provider_id !== $providerId || (int) $price->model_id !== $modelId
                    || (string) $price->capability_code !== 'rerank' || (string) $price->state !== 'published'
                    || $frozen->currency !== (string) $price->currency
                    || $frozen->effectiveFrom !== (string) $price->effective_from
                    || $frozen->effectiveUntil !== ($price->effective_until === null ? null : (string) $price->effective_until)) return false;
                $rates = PriceRate::where('price_revision_id', $priceRevisionId)->lock(true)->select()->toArray();
                return count($rates) === 1 && (string) $rates[0]['meter_code'] === $frozen->meteringUnit
                    && (string) $rates[0]['unit_price'] === $frozen->rate['unit_price']
                    && (int) $rates[0]['per_quantity'] === $frozen->rate['per_quantity'];
            } catch (ApiProblem) {
                return false;
            }
        }
        try {
            $callStartFrozen = \app\Application\Usage\CallStartPricingSnapshot::fromPersistence(
                $snapshot['called_at'], $snapshot['pricing_state'], $snapshot['price_revision_id'] ?? null, $snapshot['price_snapshot'] ?? null,
            );
        } catch (ApiProblem) {
            return false;
        }
        if ($callStartFrozen->priceRevisionId !== $priceRevisionId) {
            return false;
        }
        if ($priceRevisionId === null) {
            return $price === null && $callStartFrozen->pricingState === 'unpriced';
        }
        if ($price === null || (int) $price->provider_id !== $providerId || (int) $price->model_id !== $modelId
            || (string) $price->capability_code !== $capabilityCode || (string) $price->state !== 'published'
            || (string) $price->effective_from > $callStartFrozen->calledAt
            || ($price->effective_until !== null && (string) $price->effective_until <= $callStartFrozen->calledAt)) {
            return false;
        }
        $rates = PriceRate::where('price_revision_id', $priceRevisionId)->lock(true)->select()->toArray();
        $ratesByMeter = [];
        foreach ($rates as $rate) {
            $ratesByMeter[(string) $rate['meter_code']] = ['unit_price' => (string) $rate['unit_price'], 'per_quantity' => (int) $rate['per_quantity']];
        }
        $resolved = $callStartFrozen->resolvedPrice();
        return $resolved !== null && $resolved->currency === (string) $price->currency
            && $resolved->effectiveFrom === (string) $price->effective_from
            && $resolved->effectiveUntil === ($price->effective_until === null ? null : (string) $price->effective_until)
            && $resolved->inputRate === ($ratesByMeter['input_tokens'] ?? null)
            && $resolved->outputRate === ($ratesByMeter['output_tokens'] ?? null);
    }

    /**
     * A generic claim can fail before dispatching for scope, lease, route,
     * configuration, price or reservation reasons. At this point the ledger
     * is still prepared, so it must be terminalized with its invocation and
     * reservation instead of being left for a future worker to redispatch.
     * @param list<array{reservation:BudgetReservation,window:BudgetWindow}> $reservationGroup
     */
    private function rejectPreparedScopeLocked(InvocationDispatch $ledger, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, ApiProblem $problem, array $reservationGroup = []): void
    {
        if ((string) $ledger->lease_owner !== $scope->leaseOwner
            || !hash_equals((string) $ledger->lease_token, $scope->leaseToken)
            || (int) $ledger->lease_fence !== $scope->leaseFence) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A stale worker cannot reject a prepared dispatch');
        }
        $summary = mb_strcut($problem->getMessage(), 0, 512, 'UTF-8');
        $changed = InvocationDispatch::where('id', (int) $ledger->id)->where('state', 'prepared')->whereNull('dispatch_start_time')
            ->where('lease_owner', $scope->leaseOwner)->where('lease_token', $scope->leaseToken)->where('lease_fence', $scope->leaseFence)->update([
            'state' => 'not_dispatched', 'dispatch_complete_time' => date('Y-m-d H:i:s'),
            'error_code' => $problem->errorCode, 'error_summary' => $summary,
        ]);
        if ($changed !== 1) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Prepared dispatch changed before scope rejection');
        }
        // The successful CAS proves no send boundary was crossed. Expiry no
        // longer justifies retaining this group's unused amount.
        $budgetAction = $this->reservationGroupRejectionAction($reservationGroup, (int) $invocation->id);
        if ($reservationId !== null && $budgetAction === PreparedDispatchRejectionPolicy::RELEASE) {
            (new \app\Application\Budget\ChatBudgetGuard())->release($reservationId, 'dispatch_not_dispatched');
        }
        if ((string) $ledger->execution_scope === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->finish((int) $invocation->id, null, null, false);
        $updated = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
            'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'),
            'error_code' => $problem->errorCode, 'error_summary' => $summary,
        ]);
        if ($updated !== 1) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before prepared scope rejection');
        }
        AuditLog::create([
            'actor_type' => 'system_worker', 'actor_ref' => (string) ($scope->taskRunId ?? 0),
            'action' => 'model.dispatch.not_dispatched', 'resource_type' => 'invocation', 'resource_id' => $invocation->id,
            'summary' => 'Provider dispatch was not sent', 'context' => ['error_code' => $problem->errorCode],
        ]);
    }

    public function finalizeNotDispatchedForScope(int $dispatchId, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, string $errorCode, string $summary): void
    {
        $finalizer = new DispatchFinalizer();
        $finalizer->success(function () use ($finalizer, $dispatchId, $invocation, $reservationId, $scope, $errorCode, $summary): void {
            // Keep prepare failure on the same windows -> reservations ->
            // invocation -> ledger order as terminal dispatch finalization.
            // An invalid budget group fails before any terminal mutation.
            if ($scope->scope === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->lockForInvocation((int) $invocation->id);
            $reservationGroup = $this->lockPreparedRejectionBudget($reservationId, (int) $invocation->id);
            $lockedInvocation = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
            $ledger = InvocationDispatch::where('id', $dispatchId)->where('invocation_id', (int) $invocation->id)->where('state', 'prepared')->whereNull('dispatch_start_time')->lock(true)->find();
            if ($lockedInvocation === null || $ledger === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Prepared dispatch changed before not-dispatched finalization');
            }
            (new DispatchLedgerGateway())->notDispatched($dispatchId, $scope->leaseOwner, $scope->leaseToken, $scope->leaseFence, $errorCode, $summary);
            $budgetAction = $this->reservationGroupRejectionAction($reservationGroup, (int) $lockedInvocation->id);
            if ($reservationId !== null && $budgetAction === PreparedDispatchRejectionPolicy::RELEASE) {
                (new \app\Application\Budget\ChatBudgetGuard())->release($reservationId, 'dispatch_not_dispatched');
            }
            if ((string) $ledger->execution_scope === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->finish((int) $invocation->id, null, null, false);
            $affected = Invocation::where('id', (int) $lockedInvocation->id)->where('state', 'running')->update([
                'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $errorCode, 'error_summary' => mb_strcut($summary, 0, 512, 'UTF-8'),
            ]);
            if ($affected !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before not-dispatched finalization');
        });
    }

    /**
     * Expiry recovery never resolves a current route or prepares a Provider request.
     * Only the original, unsent ledger and its complete expired budget group can close.
     * The true return is observed after commit, so the caller may then report expiry.
     */
    public function rejectExpiredPreparedForScope(Invocation $invocation, DispatchExecutionScope $scope, UsageAttribution $attribution, ?InternalModelInvocationContext $context = null): bool
    {
        if (!in_array($scope->scope, ['chat', 'structured_workflow', 'embedding'], true)
            || ($scope->scope === 'structured_workflow') !== ($context !== null)
            || ($context !== null && $scope != DispatchExecutionScope::internal($context))
            || ($scope->scope === 'embedding'
                && ($scope->taskRunId !== null || $scope->taskStepId !== null
                    || $scope->workflowRunId !== null || $scope->workflowRunFileId !== null
                    || $scope->logicalNode !== null || $scope->attemptNo !== 1
                    || $attribution->capabilityCode !== 'embedding'
                    || !in_array(
                        [$attribution->sourceKind, $attribution->serviceAction],
                        [['knowledge_index', 'sand_ai.knowledge.index'],
                            ['knowledge_query', 'sand_ai.knowledge.query']],
                        true,
                    )))) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery scope is invalid');
        }
        return $this->transaction->transaction(function () use ($invocation, $scope, $attribution, $context): bool {
            $anchor = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id')->order('id')->find();
            if ($anchor === null) return false;
            $group = $this->lockPreparedRejectionBudget((int) $anchor->id, (int) $invocation->id);
            $now = gmdate('Y-m-d H:i:s');
            $expired = $this->reservationGroupExpired($group, $now);
            foreach ($group as $member) {
                $reservation = $member['reservation'];
                $expired = $expired || ((string) $reservation->state === 'held' && (string) $reservation->reason === 'reservation_expired');
            }
            if (!$expired) return false;
            $locked = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
            $ledger = InvocationDispatch::where('invocation_id', (int) $invocation->id)->lock(true)->find();
            if ($locked === null || $ledger === null || (string) $ledger->state !== 'prepared') return false;
            if ($ledger->dispatch_start_time !== null
                || (string) $locked->request_id !== (string) $invocation->request_id
                || !is_string($locked->request_fingerprint) || !is_string($invocation->request_fingerprint)
                || !hash_equals($locked->request_fingerprint, $invocation->request_fingerprint)
                || (int) $locked->environment_id !== $attribution->environmentId
                || (int) $locked->environment_id !== (int) $invocation->environment_id
                || (int) $locked->model_id !== (int) $invocation->model_id
                || (int) $locked->deployment_id !== (int) $invocation->deployment_id
                || $locked->data_class !== $invocation->data_class
                || (int) $ledger->environment_id !== (int) $locked->environment_id
                || (int) $ledger->model_id !== (int) $locked->model_id
                || (int) $ledger->deployment_id !== (int) $locked->deployment_id
                || (string) $ledger->logical_request_id !== hash('sha256', (string) $locked->request_id)
                || (string) $ledger->execution_scope !== $scope->scope
                || (string) $ledger->logical_execution_id !== $scope->logicalExecutionId
                || (string) $ledger->execution_snapshot_sha256 !== $scope->executionSnapshotSha256
                || (int) $ledger->attempt_no !== $scope->attemptNo
                || (string) $ledger->source_kind !== $attribution->sourceKind
                || (string) $ledger->service_action !== $attribution->serviceAction
                || (string) $ledger->capability_code !== $attribution->capabilityCode) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen expiry recovery facts changed');
            }
            foreach (['organization_id' => $attribution->organizationId, 'application_id' => $attribution->applicationId, 'workload_client_id' => $attribution->workloadClientId] as $field => $expected) {
                if (($locked->$field === null ? null : (int) $locked->$field) !== $expected) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery belongs to another caller');
                }
            }
            if (($ledger->application_id === null ? null : (int) $ledger->application_id) !== $attribution->applicationId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery belongs to another application');
            }
            $leaseMatches = (string) $ledger->lease_owner === $scope->leaseOwner
                && (string) $ledger->lease_token === $scope->leaseToken && (int) $ledger->lease_fence === $scope->leaseFence;
            if ($context === null && !$leaseMatches) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Expiry recovery no longer owns the prepared ledger');
            }
            foreach (['parent_workflow_run_id' => $scope->workflowRunId, 'parent_task_run_id' => $scope->taskRunId, 'parent_task_step_id' => $scope->taskStepId, 'parent_workflow_run_file_id' => $scope->workflowRunFileId] as $field => $expected) {
                if (($ledger->$field === null ? null : (int) $ledger->$field) !== $expected
                    || ($locked->$field === null ? null : (int) $locked->$field) !== $expected) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery belongs to another task or resource');
                }
            }
            if (($ledger->workflow_node_code === null ? null : (string) $ledger->workflow_node_code) !== $scope->logicalNode
                || ($locked->workflow_node_code === null ? null : (string) $locked->workflow_node_code) !== $scope->logicalNode) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery node changed');
            }
            if ($scope->scope === 'embedding') {
                $ledgerPrice = is_object($ledger->price_snapshot)
                    ? (array) $ledger->price_snapshot : $ledger->price_snapshot;
                if (!is_array($ledgerPrice)) {
                    throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Embedding dispatch price snapshot is invalid');
                }
                $invocationPrice = \app\Application\Usage\CallStartPricingSnapshot::fromPersistence(
                    $locked->call_started_at, $locked->call_start_pricing_state,
                    $locked->call_start_price_revision_id, $locked->call_start_price_snapshot,
                );
                $dispatchPrice = \app\Application\Usage\CallStartPricingSnapshot::fromPersistence(
                    $ledgerPrice['called_at'] ?? null, $ledgerPrice['pricing_state'] ?? null,
                    $ledgerPrice['price_revision_id'] ?? null, $ledgerPrice['price_snapshot'] ?? null,
                );
                if ($invocationPrice->dispatchSnapshot() !== $dispatchPrice->dispatchSnapshot()) {
                    throw new ApiProblem('SAND_AI_PRICING_SNAPSHOT_INVALID', 'Embedding frozen price changed');
                }
            }
            foreach ($group as $member) {
                $reservation = $member['reservation'];
                if ((string) $reservation->request_id !== (string) $locked->request_id
                    || !is_string($reservation->request_fingerprint)
                    || !hash_equals($reservation->request_fingerprint, $locked->request_fingerprint)
                    || (int) $reservation->price_revision_id !== (int) $locked->call_start_price_revision_id) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Expiry recovery budget facts changed');
                }
            }
            if ($this->reservationGroupRejectionAction($group, (int) $locked->id) !== PreparedDispatchRejectionPolicy::RELEASE) {
                return false;
            }
            if ($context !== null) {
                [$task, $step] = (new PreDispatchGuard())->assertTaskLeaseLocked($context);
                if (!is_string($task->lease_expire_time) || $task->lease_expire_time <= date('Y-m-d H:i:s')
                    || $task->canceled_at !== null || (int) $task->environment_id !== (int) $locked->environment_id
                    || (string) $locked->logical_execution_id !== $context->logicalExecutionId
                    || (string) $locked->execution_snapshot_sha256 !== $context->executionSnapshotSha256
                    || (!$leaseMatches && $scope->leaseFence <= (int) $ledger->lease_fence)) {
                    throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Expiry recovery task lease is stale or expired');
                }
                if (!$leaseMatches) {
                    $rebound = InvocationDispatch::where('id', (int) $ledger->id)->where('state', 'prepared')->whereNull('dispatch_start_time')
                        ->where('lease_owner', (string) $ledger->lease_owner)->where('lease_token', (string) $ledger->lease_token)
                        ->where('lease_fence', (int) $ledger->lease_fence)->update([
                            'lease_owner' => $scope->leaseOwner, 'lease_token' => $scope->leaseToken, 'lease_fence' => $scope->leaseFence,
                        ]);
                    if ($rebound !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Expired prepared dispatch could not be rebound');
                }
            }
            // All rows this finalizer reacquires are already locked above, in
            // the same order. Its lease CAS must succeed before any release.
            $this->finalizeNotDispatchedForScope((int) $ledger->id, $locked, (int) $anchor->id, $scope,
                'SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
            return true;
        });
    }

    /** @return list<array{reservation:BudgetReservation,window:BudgetWindow}> */
    private function lockPreparedRejectionBudget(?int $reservationId, int $invocationId): array
    {
        $group = $reservationId === null ? [] : (new \app\Application\Budget\ChatBudgetGuard())->lockReservationGroup($reservationId);
        foreach ($group as $member) {
            if ((int) $member['reservation']->invocation_id !== $invocationId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Budget reservation group belongs to another invocation');
            }
        }
        return $group;
    }

    /** @param list<array{reservation:BudgetReservation,window:BudgetWindow}> $reservationGroup */
    private function reservationGroupDispatchable(array $reservationGroup, int $invocationId, string $now): bool
    {
        if ($reservationGroup === []) {
            return false;
        }
        foreach ($reservationGroup as $member) {
            if ((int) $member['reservation']->policy_id !== (int) $member['window']->policy_id
                || !DispatchClaimPolicy::reservationDispatchable($member['reservation']->toArray(), $invocationId, $now)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array{reservation:BudgetReservation,window:BudgetWindow}> $reservationGroup */
    private function reservationGroupExpired(array $reservationGroup, string $now): bool
    {
        foreach ($reservationGroup as $member) {
            $reservation = $member['reservation'];
            if ($reservation->expires_at !== null && (string) $reservation->expires_at <= $now) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{reservation:BudgetReservation,window:BudgetWindow}> $reservationGroup */
    private function reservationGroupRejectionAction(array $reservationGroup, int $invocationId): string
    {
        if ($reservationGroup === []) {
            return PreparedDispatchRejectionPolicy::NO_OP;
        }
        foreach ($reservationGroup as $member) {
            if (PreparedDispatchRejectionPolicy::budgetAction($member['reservation'], $member['window'], $invocationId)
                !== PreparedDispatchRejectionPolicy::RELEASE) {
                return PreparedDispatchRejectionPolicy::NO_OP;
            }
        }
        return PreparedDispatchRejectionPolicy::RELEASE;
    }

    /** Stale workers may only seal a started dispatch; they never re-dispatch it. */
    public function finalizeStaleAmbiguous(int $dispatchId, Invocation $invocation, ?int $reservationId, string $capabilityCode): void
    {
        (new DispatchFinalizer())->ambiguous(function () use ($dispatchId, $invocation, $reservationId, $capabilityCode): void {
            if ($capabilityCode === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->lockForInvocation((int) $invocation->id);
            (new DispatchFinalizer())->lockInternalTerminal($reservationId, (int) $invocation->id, $dispatchId);
            (new DispatchLedgerGateway())->terminal($dispatchId, 'ambiguous', 'SAND_AI_DISPATCH_AMBIGUOUS', 'Worker lease expired after provider dispatch');
            if ($reservationId !== null) (new \app\Application\Budget\ChatBudgetGuard())->settle($reservationId, null, 'dispatch_ambiguous', null, $capabilityCode);
            if ($capabilityCode === 'rerank') (new \app\Application\Knowledge\KnowledgeProfessionalRerankSoftBudget())->finish((int) $invocation->id, null, null, true);
            $affected = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => 'SAND_AI_DISPATCH_AMBIGUOUS', 'error_summary' => 'Worker lease expired after provider dispatch',
            ]);
            if ($affected !== 1) throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before stale dispatch finalization');
        });
    }

    public function claimForDispatch(int $dispatchId, Invocation $invocation, ?int $reservationId, InternalModelInvocationContext $context, UsageAttribution $attribution, Provider $provider, ModelDeployment $deployment, int $modelId, string $capabilityCode, ?int $priceRevisionId, DispatchAuthorizationDecision $authorization, ProviderDispatchContext $providerContext, ProviderIdempotencyCapability $providerCapability): DispatchClaimOutcome
    {
        return $this->transaction->transaction(function () use ($dispatchId, $invocation, $reservationId, $context, $attribution, $provider, $deployment, $modelId, $capabilityCode, $priceRevisionId, $authorization, $providerContext, $providerCapability): DispatchClaimOutcome {
            // These records are acquired once, before task/workflow/configuration
            // locks. The catch path must reuse them; reacquiring ledger or
            // invocation after configuration locks would invert the global order.
            $reservationGroup = [];
            $lockedInvocation = null;
            $ledger = null;
            try {
                // Global dispatch order: all budget windows, reservations, invocation,
                // ledger, then task/step/workflow and immutable configuration.
                $policySelection = \app\Application\Budget\PreparedBudgetPolicySelection::lock((int) $invocation->id, $dispatchId, $attribution);
            $reservationGroup = $this->lockPreparedRejectionBudget($reservationId, (int) $invocation->id);
                $lockedInvocation = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->lock(true)->find();
                $ledger = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')->lock(true)->find();
                if ($lockedInvocation === null || $ledger === null || (int) $ledger->invocation_id !== (int) $invocation->id) {
                    return DispatchClaimOutcome::rejected(new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen budget reservation or invocation is no longer dispatchable'));
                }
                // Lease ownership is checked before any expiry rejection can
                // mutate the frozen ledger, reservation, or invocation.
                $guard = new PreDispatchGuard();
                [$lockedTask, $lockedStep] = $guard->assertTaskLeaseLocked($context);
                $reservationExpired = $this->reservationGroupExpired($reservationGroup, gmdate('Y-m-d H:i:s'));
                if ($reservationId !== null && !$this->reservationGroupDispatchable($reservationGroup, (int) $invocation->id, gmdate('Y-m-d H:i:s'))) {
                    if ($reservationExpired) {
                        $problem = new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
                        $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                        return DispatchClaimOutcome::rejected($problem);
                    }
                    $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen budget reservation or invocation is no longer dispatchable');
                    $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                $route = is_object($ledger->route_snapshot) ? (array) $ledger->route_snapshot : $ledger->route_snapshot;
                $priceSnapshot = is_object($ledger->price_snapshot) ? (array) $ledger->price_snapshot : $ledger->price_snapshot;
                if (!is_array($route) || !is_array($priceSnapshot)) {
                    $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen dispatch route or price snapshot is invalid');
                    $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                $lockedDeployment = ModelDeployment::where('id', (int) $deployment->id)->where('status', 1)->lock(true)->find();
                $lockedProvider = Provider::where('id', (int) $provider->id)->where('status', 1)->lock(true)->find();
                $lockedModel = AiModel::where('id', $modelId)->where('status', 1)->lock(true)->find();
                $revision = $lockedDeployment === null ? null : ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', (int) $lockedDeployment->id)->where('revision', (int) $lockedDeployment->revision)->where('state', 'published')->where('status', 1)->lock(true)->find();
                // Keep the structured path on the exact same provider/model/
                // capability/published/effective price fence as generic chat.
                // lockedPriceSnapshotMatches() also locks every PriceRate row
                // before the profile/route locks below.
                $price = $priceRevisionId === null ? null : PriceRevision::where('id', $priceRevisionId)
                    ->where('provider_id', (int) $provider->id)->where('model_id', $modelId)
                    ->where('capability_code', $capabilityCode)->where('state', 'published')->lock(true)->find();
                $priceMatches = $this->lockedPriceSnapshotMatches($priceSnapshot, $price, $priceRevisionId, (int) $provider->id, $modelId, $capabilityCode);
                $selectionIsProfile = ($route['selection_kind'] ?? null) === 'capability_profile';
                $profile = $selectionIsProfile ? CapabilityProfile::where('id', (int) ($route['profile_id'] ?? 0))->where('environment_id', $attribution->environmentId)->where('revision', (int) ($route['profile_revision'] ?? 0))->whereIn('status', [1, 2])->lock(true)->find() : null;
                $capabilityRoute = $selectionIsProfile && $lockedDeployment !== null ? CapabilityRoute::where('id', (int) ($route['route_id'] ?? 0))->where('profile_id', (int) ($route['profile_id'] ?? 0))->where('capability_code', $capabilityCode)->where('deployment_id', (int) $lockedDeployment->id)->where('status', 1)->lock(true)->find() : null;
                $profileRevision = $selectionIsProfile ? ConfigRevision::where('id', (int) ($route['profile_config_revision_id'] ?? 0))->where('resource_type', 'capability_profile')->where('resource_id', (int) ($route['profile_id'] ?? 0))->where('revision', (int) ($route['profile_revision'] ?? 0))->where('state', 'published')->where('status', 1)->lock(true)->find() : null;
                // `InvocationDispatch` intentionally hides the provider key and
                // lease token from DTO serialization. The coordinator is an
                // internal fence, so it must compare the model's raw record,
                // not `toArray()` which removes both fields.
                $ledgerSnapshot = $this->rawLedgerRecord($ledger);
                $routeMatches = $lockedDeployment !== null && $lockedProvider !== null && $lockedModel !== null && $revision !== null && $profile !== null && $capabilityRoute !== null && $profileRevision !== null
                    && DispatchRouteSnapshot::matches($route, $profile, $capabilityRoute, $lockedDeployment, $lockedModel, $lockedProvider, $revision, $profileRevision, false);
                if ($lockedDeployment === null || $lockedProvider === null || $lockedModel === null || $revision === null || ($priceRevisionId !== null && $price === null)
                    || !$selectionIsProfile
                    || ($selectionIsProfile && ($profile === null || $capabilityRoute === null || $profileRevision === null))
                    || !$priceMatches
                    || !PreparedDispatchRecoveryPolicy::matches($ledgerSnapshot, $route, $priceSnapshot, $context, $providerContext, $providerCapability, (int) $lockedProvider->id, (int) $lockedDeployment->id, $modelId, $capabilityCode, $priceRevisionId)
                    || !$routeMatches
                    ) {
                    $problem = new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen dispatch dependencies are no longer current');
                    $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                (new \app\Application\Budget\ChatBudgetGuard())->assertPreparedCapacityForDispatch(
                    $lockedInvocation, $ledger, $reservationGroup[0]['reservation'] ?? null, $attribution, $policySelection,
                );
                $guard->assertCurrentLocked($ledger, $lockedTask, $lockedStep, $context, $attribution, $lockedProvider, $lockedDeployment, $modelId, $capabilityCode);
                if (!$authorization->allowed || !$authorization->isCurrent(time()) || !hash_equals($authorization->contextDigest, DispatchAuthorizationDecision::contextDigest($context, $attribution, $capabilityCode, $providerContext, $route, $lockedProvider, $lockedDeployment, $priceRevisionId))) {
                    $problem = new ApiProblem('SAND_AI_DISPATCH_NOT_AUTHORIZED', 'Trusted dispatch authorization was not confirmed');
                    $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                    return DispatchClaimOutcome::rejected($problem);
                }
                // A crash before HTTP may be safely rebound only after all frozen
                // content and the current task lease have been checked under lock.
                if ((string) $ledger->lease_owner !== $context->lease->owner || (string) $ledger->lease_token !== $context->lease->token || (int) $ledger->lease_fence !== $context->lease->fence) {
                    $rebound = InvocationDispatch::where('id', $dispatchId)->where('state', 'prepared')->whereNull('dispatch_start_time')->update([
                        'lease_owner' => $context->lease->owner, 'lease_token' => $context->lease->token, 'lease_fence' => $context->lease->fence,
                    ]);
                    if ($rebound !== 1) {
                        return DispatchClaimOutcome::rejected(new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Prepared dispatch could not be rebound to the current task lease'));
                    }
                }
                (new DispatchLedgerGateway())->dispatching($dispatchId, $context->lease->owner, $context->lease->token, $context->lease->fence);
                return DispatchClaimOutcome::claimed($lockedProvider, $lockedDeployment);
            } catch (ApiProblem $problem) {
                // A stale worker must never close a newer worker's prepared
                // ledger. For every other pre-provider fault, close the exact
                // records already locked above. Do not reacquire them here.
                if ($problem->errorCode !== 'SAND_AI_TASK_LEASE_LOST'
                    && $ledger !== null
                    && $lockedInvocation !== null
                    && DispatchClaimPolicy::currentLeaseOwnsPrepared($this->rawLedgerRecord($ledger), $context)) {
                    $this->rejectLocked($ledger, $lockedInvocation, $reservationId, $context, $problem, $reservationGroup);
                }
                return DispatchClaimOutcome::rejected($problem);
            }
        });
    }

    /** @param list<array{reservation:BudgetReservation,window:BudgetWindow}> $reservationGroup */
    private function rejectLocked(InvocationDispatch $ledger, Invocation $invocation, ?int $reservationId, InternalModelInvocationContext $context, ApiProblem $problem, array $reservationGroup): void
    {
        if (!DispatchClaimPolicy::currentLeaseOwnsPrepared($this->rawLedgerRecord($ledger), $context)) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'A stale worker cannot reject a prepared dispatch');
        }
        $changed = InvocationDispatch::where('id', (int) $ledger->id)->where('state', 'prepared')->whereNull('dispatch_start_time')
            ->where('lease_owner', $context->lease->owner)->where('lease_token', $context->lease->token)->where('lease_fence', $context->lease->fence)
            ->update(['state' => 'not_dispatched', 'dispatch_complete_time' => date('Y-m-d H:i:s'), 'error_code' => $problem->errorCode, 'error_summary' => mb_strcut($problem->getMessage(), 0, 512, 'UTF-8')]);
        if ($changed !== 1) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Prepared dispatch changed before rejection'); }
        $budgetAction = $this->reservationGroupRejectionAction($reservationGroup, (int) $invocation->id);
        if ($reservationId !== null && $budgetAction === PreparedDispatchRejectionPolicy::RELEASE) {
            (new \app\Application\Budget\ChatBudgetGuard())->release($reservationId, 'dispatch_not_dispatched');
        }
        $updated = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $problem->errorCode, 'error_summary' => mb_strcut($problem->getMessage(), 0, 512, 'UTF-8')]);
        if ($updated !== 1) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before prepared dispatch rejection'); }
        AuditLog::create(['actor_type' => 'system_worker', 'actor_ref' => (string) $context->workflowRunId, 'action' => 'model.dispatch.not_dispatched', 'resource_type' => 'invocation', 'resource_id' => $invocation->id, 'summary' => 'Trusted provider dispatch was not sent', 'context' => ['error_code' => $problem->errorCode]]);
    }

    /** @return array<string,mixed> */
    private function rawLedgerRecord(InvocationDispatch $ledger): array
    {
        $record = $ledger->getData();
        if (!is_array($record)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen dispatch ledger is invalid');
        }

        return $record;
    }

    public function finalizeProviderOutcome(int $dispatchId, Invocation $invocation, ?int $reservationId, InternalModelInvocationContext $context, string $capabilityCode, ApiProblem $problem, ProviderDispatchTerminalization $outcome): void
    {
        if ($outcome->isSucceeded() || $outcome->ledgerState === 'not_dispatched') {
            throw new \LogicException('A dispatching provider finalizer cannot accept this outcome');
        }
        $finalizer = new DispatchFinalizer();
        $finalizer->success(function () use ($finalizer, $dispatchId, $invocation, $reservationId, $context, $capabilityCode, $problem, $outcome): void {
            $finalizer->lockInternalTerminal($reservationId, (int) $invocation->id, $dispatchId);
            (new DispatchLedgerGateway())->terminal($dispatchId, $outcome->ledgerState, $problem->errorCode, $problem->getMessage(), $context);
            if ($reservationId !== null) {
                $guard = new \app\Application\Budget\ChatBudgetGuard();
                if ($outcome->releasesBudget()) {
                    $guard->release($reservationId, 'provider_rejected');
                } elseif ($outcome->holdsBudget()) {
                    $guard->settle($reservationId, null, 'dispatch_ambiguous', null, $capabilityCode);
                }
            }
            $errorCode = $outcome->isAmbiguous() ? 'SAND_AI_DISPATCH_AMBIGUOUS' : $problem->errorCode;
            $summary = $outcome->isAmbiguous() ? 'Provider dispatch outcome is ambiguous' : mb_strcut($problem->getMessage(), 0, 512, 'UTF-8');
            $updated = Invocation::where('id', (int) $invocation->id)->where('state', 'running')->update([
                'state' => $outcome->invocationState, 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $errorCode, 'error_summary' => $summary,
            ]);
            if ($updated !== 1) { throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Invocation changed before provider outcome finalization'); }
            AuditLog::create(['actor_type' => 'system_worker', 'actor_ref' => (string) $context->workflowRunId, 'action' => 'model.dispatch.' . $outcome->ledgerState, 'resource_type' => 'invocation', 'resource_id' => $invocation->id, 'summary' => $summary, 'context' => ['error_code' => $problem->errorCode]]);
        });
    }
}
