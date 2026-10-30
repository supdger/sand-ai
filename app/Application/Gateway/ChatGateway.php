<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\FakeProviderPolicy;
use app\Application\Audio\MeetingSummaryRoute;
use app\Application\Audio\MeetingSummaryOutputContract;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\MeetingSummaryNode;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Application\Usage\UsageMeter;
use app\Application\Workflow\StructuredProviderOutcome;
use app\Application\Skill\SkillAttribution;
use app\Application\Skill\SkillSourceSuggestionContext;
use app\Application\Skill\SkillSourceSuggestionUsageProof;
use app\Application\Skill\SkillProviderOutcome;
use app\Application\Skill\SkillFinalizationPolicy;
use app\Application\Knowledge\KnowledgeAnswerAttribution;
use app\Application\Knowledge\KnowledgeProviderOutcome;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\CapabilityProfile;
use app\Infrastructure\Persistence\Model\CapabilityRoute;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\UsageRecord;
use app\Infrastructure\Provider\FakeChatProvider;
use app\Infrastructure\Provider\MeasuredChatFailure;
use app\Infrastructure\Provider\OpenAiCompatibleChatProvider;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\PreparedChatProviderCall;
use app\Infrastructure\Provider\AgentToolChatRequest;
use app\Infrastructure\Provider\AgentToolProviderOutcome;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use think\facade\Db;

final class ChatGateway
{
    private ?OutcomeReceiptService $outcomeReceipts;

    public function __construct(
        ?OutcomeReceiptService $outcomeReceipts = null,
        private readonly ?DispatchAuthorizer $dispatchAuthorizer = null,
        private readonly ?OpenAiCompatibleChatProvider $openAiCompatibleProvider = null,
        /** @var null|\Closure(\app\Infrastructure\Persistence\Model\InvocationDispatch):void test-only hook after durable prepare, before provider preparation/claim. */
        private readonly ?\Closure $afterPreparedDispatch = null,
    )
    {
        $this->outcomeReceipts = $outcomeReceipts;
    }

    /** @return list<array{id: int, code: string, name: string, type: string, capabilities: array}> */
    public function availableModels(int $environmentId): array
    {
        $profileResolver = new CapabilityProfileResolver();
        $profileRoutes = $profileResolver->routes($environmentId, 'chat');
        if (!$profileResolver->hasPublishedProfile($environmentId)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published capability profile is available for this environment');
        }
        $deploymentIds = array_values(array_unique(array_map(static fn (array $route): int => (int) $route['deployment_id'], $profileRoutes)));
        if ($deploymentIds === []) {
            return [];
        }
        $modelIds = ModelDeployment::whereIn('id', $deploymentIds)->column('model_id');
        return $this->modelsByIds($modelIds);
    }

    /** @param list<int|string> $modelIds @return list<array{id: int, code: string, name: string, type: string, capabilities: array}> */
    private function modelsByIds(array $modelIds): array
    {
        if ($modelIds === []) {
            return [];
        }
        return AiModel::whereIn('id', $modelIds)->where('status', 1)->order('id')->select()->map(fn (AiModel $model): array => [
            'id' => (int) $model->id,
            'code' => (string) $model->code,
            'name' => (string) $model->getAttr('name'),
            'type' => (string) $model->type,
            'capabilities' => $this->stringList($model->capabilities),
        ])->all();
    }

    /** @param list<array{role: string, content: string}> $messages @return array<string, mixed> */
    public function complete(int $environmentId, string $modelCode, array $messages, string $requestId, ?UsageAttribution $attribution = null, mixed $maxOutputTokens = null, string $capabilityCode = 'chat', ?OutcomeReceiptRequest $outcomeReceiptRequest = null, ?InternalModelInvocationContext $internalContext = null, ?SkillAttribution $skillAttribution = null, ?DispatchExecutionScope $executionScope = null, ?callable $authorizePublicInvocation = null, ?KnowledgeAnswerAttribution $knowledgeAnswerAttribution = null, ?callable $acceptSegment = null, ?SkillSourceSuggestionContext $skillSourceSuggestionContext = null, ?AgentToolChatRequest $agentToolRequest = null, ?callable $acceptAgentChunk = null, ?\app\Application\Workflow\MaterialReviewOutputContract $materialReviewOutput = null, ?\app\Application\Legal\LegalOutputContract $legalOutput = null, ?MeetingSummaryOutputContract $meetingOutput = null, bool $receiptOnly = false): array
    {
        if ($receiptOnly && $agentToolRequest === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Receipt-only execution requires the original Agent protocol');
        }
        if (($acceptAgentChunk !== null) !== ($agentToolRequest?->stream ?? false)) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent stream requires its trusted live receiver');
        }
        if ($agentToolRequest !== null) {
            if ($capabilityCode !== 'chat' || $internalContext !== null || $skillAttribution !== null
                || $knowledgeAnswerAttribution !== null || $skillSourceSuggestionContext !== null
                || $acceptSegment !== null || $attribution === null || $executionScope === null
                || $authorizePublicInvocation === null || $outcomeReceiptRequest === null
                || $modelCode === LowCostChatRouteResolver::REQUEST_MODEL) {
                throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Agent tool rounds require their trusted task context');
            }
            $agentToolRequest->assertScope($executionScope, $environmentId, $requestId);
            $agentToolRequest->assertMessages($messages);
        }
        if ($skillSourceSuggestionContext !== null) {
            if ($capabilityCode !== 'chat' || $skillAttribution !== null || $internalContext !== null
                || $knowledgeAnswerAttribution !== null || $executionScope !== null || $acceptSegment !== null
                || $modelCode === LowCostChatRouteResolver::REQUEST_MODEL || $attribution === null
                || $authorizePublicInvocation === null || $outcomeReceiptRequest === null
                || $skillSourceSuggestionContext->environmentId !== $environmentId) {
                throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Skill source suggestions require their trusted internal context');
            }
            $skillSourceSuggestionContext->assertAttribution($attribution);
            $knowledgeAnswerAttribution = $skillSourceSuggestionContext->knowledgeAttribution();
        }
        if ($materialReviewOutput !== null) {
            if ($internalContext === null || !$internalContext->crossFileReview
                || $skillAttribution !== null || $agentToolRequest !== null || $acceptSegment !== null) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Review output protocol requires its trusted review context');
            }
            $run = \app\Infrastructure\Persistence\Model\WorkflowRun::where('id', $internalContext->workflowRunId)->find();
            $snapshot = $run === null ? null : (is_object($run->execution_snapshot) ? (array) $run->execution_snapshot : $run->execution_snapshot);
            $frozen = is_array($snapshot) ? \app\Application\Workflow\MaterialReviewOutputContract::fromSnapshot($snapshot) : null;
            if ($run === null || $frozen === null || (int) $run->task_id !== $internalContext->taskRunId
                || !hash_equals($internalContext->executionSnapshotSha256, (string) $run->execution_snapshot_sha256)
                || !hash_equals((string) $run->execution_snapshot_sha256, \app\Application\Workflow\StructuredWorkflowPolicy::fingerprint($snapshot))
                || $frozen->fingerprint() !== $materialReviewOutput->fingerprint()) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Review output protocol differs from its frozen run');
            }
        }
        if ($legalOutput !== null) {
            if ($internalContext === null || $internalContext->crossFileReview || $internalContext->singleFile
                || $internalContext->workflowNodeCode !== 'analyze_legal_sources' || $capabilityCode !== 'structured_extraction'
                || $materialReviewOutput !== null
                || $skillAttribution !== null || $agentToolRequest !== null || $acceptSegment !== null) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Legal output requires its trusted context');
            }
            $legalRun = \app\Infrastructure\Persistence\Model\WorkflowRun::where('id', $internalContext->workflowRunId)->find();
            $legalSnapshot = $legalRun === null ? null : (array) $legalRun->execution_snapshot;
            $frozenLegal = is_array($legalSnapshot) ? \app\Application\Legal\LegalOutputContract::fromSnapshot($legalSnapshot) : null;
            if ($legalRun === null || (string) $legalRun->workflow_code !== 'legal_analysis' || $frozenLegal === null
                || (int) $legalRun->task_id !== $internalContext->taskRunId
                || !hash_equals($internalContext->executionSnapshotSha256, (string) $legalRun->execution_snapshot_sha256)
                || !hash_equals((string) $legalRun->execution_snapshot_sha256, \app\Application\Workflow\StructuredWorkflowPolicy::fingerprint($legalSnapshot))
                || $frozenLegal->fingerprint() !== $legalOutput->fingerprint()) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Legal output differs from its frozen run');
            }
        }
        if ($meetingOutput !== null) {
            if ($capabilityCode !== 'chat' || $executionScope?->scope !== 'ai_task'
                || $attribution === null || $authorizePublicInvocation === null || $outcomeReceiptRequest === null
                || $internalContext !== null || $skillAttribution !== null || $knowledgeAnswerAttribution !== null
                || $skillSourceSuggestionContext !== null || $agentToolRequest !== null || $acceptAgentChunk !== null
                || $materialReviewOutput !== null || $legalOutput !== null || $acceptSegment !== null
                || $modelCode === LowCostChatRouteResolver::REQUEST_MODEL) {
                throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Meeting output requires its trusted task node');
            }
        }
        if ($executionScope?->scope === 'ai_task' && $outcomeReceiptRequest !== null) {
            $this->assertMeetingOutput($environmentId, $modelCode, $messages, $requestId,
                $attribution, $executionScope, $meetingOutput);
        }
        $streamSegments = $acceptSegment !== null;
        if ($streamSegments && $knowledgeAnswerAttribution === null) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Validated segment streaming requires a knowledge answer');
        }
        $this->assertChatModelCapability($capabilityCode);
        if ($skillAttribution !== null && $skillAttribution->environmentId !== $environmentId) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill attribution environment is inconsistent');
        }
        if ($knowledgeAnswerAttribution !== null && ($skillAttribution !== null || $internalContext !== null || $capabilityCode !== 'chat')) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Knowledge answer attribution requires public chat');
        }
        if ($capabilityCode === 'structured_extraction' && $internalContext === null) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Structured extraction requires a trusted internal invocation context');
        }
        if ($capabilityCode !== 'chat' && $capabilityCode !== 'structured_extraction') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'This internal capability has no dispatch policy');
        }
        if ($internalContext !== null && $capabilityCode !== 'structured_extraction') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Internal invocation context is limited to structured extraction');
        }
        if ($internalContext !== null) {
            $dispatchConfig = config('sand_ai_material_analysis_dispatch');
            if (!is_array($dispatchConfig) || ($dispatchConfig['material_analysis_dispatch_enabled'] ?? false) !== true) {
                throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Structured extraction dispatch is disabled');
            }
        }
        $lowCostRequest = $modelCode === LowCostChatRouteResolver::REQUEST_MODEL;
        if ($lowCostRequest && ($capabilityCode !== 'chat' || $internalContext !== null
            || $skillAttribution !== null || $knowledgeAnswerAttribution !== null || $executionScope !== null)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Lowest-cost routing is limited to public chat');
        }
        $attribution = ModelCapabilityInvocationPolicy::attribution($environmentId, $capabilityCode, $attribution);
        // A Skill is a trusted internal consumer of the chat route. Its outcome
        // receipt is intentionally distinct from public chat retention.
        if ($executionScope?->scope === 'ai_task' && $outcomeReceiptRequest !== null
            && ($capabilityCode !== 'chat' || $internalContext !== null
                || $skillAttribution !== null || $knowledgeAnswerAttribution !== null)) {
            throw new ApiProblem('SAND_AI_RECEIPT_FORBIDDEN', 'Meeting receipt requires an AI task chat node');
        }
        $meetingReceipt = $capabilityCode === 'chat' && $outcomeReceiptRequest !== null
            && $executionScope?->scope === 'ai_task';
        $receiptCapabilityCode = $agentToolRequest !== null ? 'agent_tool' : ($skillSourceSuggestionContext !== null ? 'skill_source_suggestion' : ($meetingReceipt ? 'meeting_summary' : ($knowledgeAnswerAttribution !== null
            ? 'knowledge_answer'
            : ($skillAttribution === null ? $capabilityCode : 'skill'))));
        $allowIncompleteReceiptReplay = $skillAttribution !== null;
        $outcomeReceipts = $outcomeReceiptRequest === null ? null : $this->outcomeReceipts();
        $receiptEnabled = $outcomeReceipts?->preflight($receiptCapabilityCode, $outcomeReceiptRequest) ?? false;
        if ($agentToolRequest !== null && !$receiptEnabled) throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Agent tool rounds require a private outcome receipt');
        if ($meetingReceipt && !$receiptEnabled) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Meeting nodes require a private outcome receipt');
        }
        if ($knowledgeAnswerAttribution !== null && !$receiptEnabled) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Knowledge answers require a private outcome receipt');
        }
        $options = ChatCompletionOptionsPolicy::normalize($maxOutputTokens);
        if ($skillAttribution?->outputContract !== null) {
            $options = new ChatCompletionOptions($options->maxOutputTokens, $options->reasoningEffort, $skillAttribution->outputContract);
        }
        if ($meetingOutput !== null) $options = new ChatCompletionOptions($options->maxOutputTokens, $options->reasoningEffort, $meetingOutput);
        if ($legalOutput !== null) $options = new ChatCompletionOptions($options->maxOutputTokens, $options->reasoningEffort, $legalOutput);
        if ($materialReviewOutput !== null) $options = new ChatCompletionOptions($options->maxOutputTokens, $options->reasoningEffort, $materialReviewOutput);
        $fingerprintInput = [
            'environment_id' => $environmentId,
            'trusted_identity_scope' => RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            'model' => $modelCode,
            'messages' => $messages,
            'max_output_tokens' => $options->maxOutputTokens,
        ];
        if ($options->skillOutput !== null) $fingerprintInput['skill_output'] = $options->skillOutput->fingerprint();
        if ($agentToolRequest !== null) $fingerprintInput['agent_tool'] = $agentToolRequest->fingerprint();
        if ($capabilityCode !== 'chat') {
            $fingerprintInput['capability_code'] = $capabilityCode;
            $fingerprintInput['capability_fingerprint_version'] = 1;
        }
        if ($knowledgeAnswerAttribution !== null) {
            $fingerprintInput['knowledge_answer'] = [
                'publication_id' => $knowledgeAnswerAttribution->publicationId,
                'version_id' => $knowledgeAnswerAttribution->versionId,
                'source_scope_sha256' => $knowledgeAnswerAttribution->sourceScopeSha256,
                'data_class' => $knowledgeAnswerAttribution->dataClass,
            ];
        }
        if ($skillSourceSuggestionContext !== null) {
            $fingerprintInput['skill_source_suggestion'] = $skillSourceSuggestionContext->fingerprint();
        }
        if ($streamSegments) {
            $fingerprintInput['segment_stream_version'] = 1;
        }
        $useNeuronSourceSuggestion = false;
        if ($skillSourceSuggestionContext !== null) {
            // Only newly accepted suggestion invocations get this protocol marker.
            // Compare complete immutable fingerprints to retain legacy replays,
            // including requests rejected before an invocation was created.
            $legacySourceFingerprint = RequestPayloadFingerprint::fingerprint($fingerprintInput, []);
            $fingerprintInput['skill_source_suggestion_driver'] = 'neuron_chat@1';
            $useNeuronSourceSuggestion = true;
            $priorSourceInvocation = Invocation::where('request_id', $requestId)->find();
            $priorSourceRejection = \app\Infrastructure\Persistence\Model\PreDispatchRejection::where(
                'trusted_scope', RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            )->where('request_id', $requestId)->find();
            if (($priorSourceInvocation !== null && is_string($priorSourceInvocation->request_fingerprint)
                    && hash_equals($legacySourceFingerprint, $priorSourceInvocation->request_fingerprint))
                || ($priorSourceRejection !== null && is_string($priorSourceRejection->request_fingerprint)
                    && hash_equals($legacySourceFingerprint, $priorSourceRejection->request_fingerprint))) {
                unset($fingerprintInput['skill_source_suggestion_driver']);
                $useNeuronSourceSuggestion = false;
            }
        }
        $requestFingerprint = RequestPayloadFingerprint::fingerprint($fingerprintInput, []);
        if ($executionScope !== null && $internalContext !== null) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'A dispatch cannot have two execution scopes');
        }
        $executionScope ??= $internalContext !== null
            ? DispatchExecutionScope::internal($internalContext)
            : ($skillAttribution === null
                ? DispatchExecutionScope::publicChat($environmentId, $requestId, $requestFingerprint)
                : DispatchExecutionScope::skill($environmentId, $skillAttribution->skillRunId, $requestFingerprint));
        $rejectionStore = new PreDispatchRejectionStore();
        $priorRejection = $rejectionStore->replayOrNull($environmentId, $attribution, $requestId, $requestFingerprint);
        if ($priorRejection !== null) {
            throw $priorRejection;
        }
        $existing = Invocation::where('request_id', $requestId)->find();
        if ($receiptOnly && $existing === null) {
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Receipt-only execution cannot create a model invocation');
        }
        $meetingRoute = null;
        if ($meetingReceipt) {
            MeetingSummaryReceiptScope::assertCurrent($executionScope, $environmentId, $requestId, $existing !== null);
            // Existing unsent calls first acquire their fenced ledger lease,
            // so invalid legacy route/price data can close without sending.
            if ($existing === null) $meetingRoute = (new MeetingSummaryRoute())->forNode($executionScope, $environmentId, false);
        }
        $recoverSkillReceipt = false;
        $resumeSourcePrepared = false;
        $resumeMeetingPrepared = false;
        $meetingDispatch = null;
        $selectedDeploymentId = $meetingRoute['deployment_id'] ?? null;
        $selection = null;
        if ($existing !== null) {
            if ((int) $existing->environment_id !== $environmentId) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another application environment');
            }
            if (!is_string($existing->request_fingerprint) || !hash_equals($existing->request_fingerprint, $requestFingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id was already used with a different or unverifiable chat input');
            }
            if ($agentToolRequest !== null) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                $agentDispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int)$existing->id)->find();
                if ($agentDispatch === null || (string)$agentDispatch->execution_scope !== 'agent'
                    || (string)$agentDispatch->capability_code !== 'chat'
                    || (int)$agentDispatch->parent_task_run_id !== $executionScope->taskRunId
                    || (int)$agentDispatch->parent_task_step_id !== $executionScope->taskStepId
                    || (string)$agentDispatch->workflow_node_code !== $executionScope->logicalNode
                    || (string)$agentDispatch->logical_request_id !== hash('sha256',$requestId)
                    || (string)$agentDispatch->logical_execution_id !== $executionScope->logicalExecutionId
                    || (string)$agentDispatch->execution_snapshot_sha256 !== $executionScope->executionSnapshotSha256
                    || (int)$agentDispatch->attempt_no !== $executionScope->attemptNo) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent round differs from frozen task dispatch');
                }
                if (!in_array([(string)$existing->state,(string)$agentDispatch->state], [['running','dispatching'],['succeeded','succeeded']],true)) {
                    throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS','Agent round cannot safely be sent again');
                }
                $context=$this->receiptContext($existing,$environmentId,$receiptCapabilityCode,$requestFingerprint);
                $probe=$outcomeReceipts->probe((int)$existing->id,$context);
                if ($probe==='missing') throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS','Agent outcome is not locally recoverable');
                if ($probe!=='valid') throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent outcome receipt is invalid');
                $agentOutcome=AgentToolProviderOutcome::decode($outcomeReceipts->replay($context));
                $agentToolRequest->assertOutcome($agentOutcome->response,$messages);
                $reservation=BudgetReservation::where('invocation_id',(int)$existing->id)->order('policy_id')->order('id')->find();
                $usage=$this->finalizeKnowledgeOutcome($existing,$agentDispatch,$reservation===null?null:(int)$reservation->id,$agentOutcome->accounting,$attribution,$requestId,$modelCode,true);
                $completed=$usage['_invocation']; unset($usage['_invocation']);
                return $agentOutcome->response + ['idempotent_replay'=>true,'invocation'=>$this->invocationSummary($completed),'invocation_id'=>(int)$completed->id,'usage'=>$usage['tokens']+$usage];
            }
            if ($knowledgeAnswerAttribution !== null && (
                (int) ($existing->knowledge_publication_id ?? 0) !== $knowledgeAnswerAttribution->publicationId
                || (int) ($existing->knowledge_base_version_id ?? 0) !== $knowledgeAnswerAttribution->versionId
                || (string) ($existing->knowledge_source_scope_sha256 ?? '') !== $knowledgeAnswerAttribution->sourceScopeSha256
                || $existing->data_class !== $knowledgeAnswerAttribution->dataClass
            )) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another knowledge source');
            }
            if ($skillSourceSuggestionContext !== null) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                $skillSourceSuggestionContext->assertInvocation($existing);
                $outcomeReceipts->renewPendingSkillSourceSuggestion((int) $existing->id, $skillSourceSuggestionContext->suggestionId);
                if ((string) $existing->state === 'running') {
                    $sourceDispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $existing->id)->find();
                    // Only the durable unsent state may continue to the existing
                    // fenced prepare/claim path; dispatching never redispatches.
                    $resumeSourcePrepared = $sourceDispatch !== null && (string) $sourceDispatch->state === 'prepared';
                }
            }
            $persistedSkill = [(int) ($existing->skill_id ?? 0), (int) ($existing->skill_version_id ?? 0), (int) ($existing->skill_run_id ?? 0)];
            $expectedSkill = $skillAttribution === null ? [0, 0, 0] : [$skillAttribution->skillId, $skillAttribution->skillVersionId, $skillAttribution->skillRunId];
            if ($persistedSkill !== $expectedSkill) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another Skill attribution');
            }
            // A replay must not silently trust mutable storage evidence. This is intentionally
            // checked before returning the legacy public-chat summary as well.
            if (!$meetingReceipt) CallStartPricingSnapshot::fromPersistence(
                $existing->call_started_at,
                $existing->call_start_pricing_state,
                $existing->call_start_price_revision_id,
                $existing->call_start_price_snapshot,
            );
            if ($skillAttribution === null && (string) $existing->state === 'running'
                && ($knowledgeAnswerAttribution === null || $skillSourceSuggestionContext !== null)
                && in_array($executionScope->scope, ['chat', 'structured_workflow'], true)) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                if ((new DispatchCoordinator())->rejectExpiredPreparedForScope($existing, $executionScope, $attribution, $internalContext)) {
                    throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Budget reservation expired before provider dispatch');
                }
            }
            if ($lowCostRequest) {
                $frozenSelection = $this->frozenLowCostRoute($existing);
                $modelCode = $frozenSelection['model_code'];
                $selectedDeploymentId = $frozenSelection['deployment_id'];
            }
            if ($meetingReceipt) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                $meetingDispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $existing->id)->find();
                if ($meetingDispatch === null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting dispatch cannot be recovered');
                }
                MeetingSummaryReceiptScope::assertExisting($existing, $meetingDispatch, $executionScope, $requestId, $requestFingerprint);
                if ((string) $existing->state === 'running' && (string) $meetingDispatch->state === 'prepared'
                    && $meetingDispatch->dispatch_start_time === null) {
                    $meetingDispatch = (new DispatchCoordinator())->rebindMeetingPrepared(
                        $existing, $meetingDispatch, $executionScope, $attribution, $requestId, $requestFingerprint,
                    );
                    if ((string) $meetingDispatch->state !== 'prepared') {
                        throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Original meeting reservation cannot be resumed');
                    }
                    try {
                        CallStartPricingSnapshot::fromPersistence($existing->call_started_at, $existing->call_start_pricing_state,
                            $existing->call_start_price_revision_id, $existing->call_start_price_snapshot);
                        $meetingRoute = (new MeetingSummaryRoute())->forNode($executionScope, $environmentId, true)
                            ?? (new MeetingSummaryRoute())->forPrepared($executionScope, $environmentId);
                        MeetingSummaryRoute::assertSame($meetingRoute, $meetingDispatch->route_snapshot);
                    } catch (ApiProblem $problem) {
                        $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                        $this->finalizePreProviderFailure((int) $meetingDispatch->id, $existing,
                            $reservation === null ? null : (int) $reservation->id, $executionScope, $problem);
                        throw $problem;
                    }
                    $selectedDeploymentId = $meetingRoute['deployment_id'];
                    $resumeMeetingPrepared = true;
                } else {
                    CallStartPricingSnapshot::fromPersistence($existing->call_started_at, $existing->call_start_pricing_state,
                        $existing->call_start_price_revision_id, $existing->call_start_price_snapshot);
                    $meetingRoute = (new MeetingSummaryRoute())->forNode($executionScope, $environmentId, true);
                    if ($meetingRoute !== null) MeetingSummaryRoute::assertSame($meetingRoute, $meetingDispatch->route_snapshot);
                }
                if ((string) $existing->state === 'running' && (string) $meetingDispatch->state === 'dispatching') {
                    $context = $this->receiptContext($existing, $environmentId, $receiptCapabilityCode, $requestFingerprint);
                    $probe = $outcomeReceipts->probe((int) $existing->id, $context);
                    if ($probe === 'missing') {
                        throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Meeting provider outcome is not locally recoverable');
                    }
                    if ($probe !== 'valid') {
                        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Meeting outcome receipt is invalid');
                    }
                    $outcome = KnowledgeProviderOutcome::decode($outcomeReceipts->replay($context));
                    $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
                    $usage = $this->finalizeKnowledgeOutcome($existing, $meetingDispatch, $reservation === null ? null : (int) $reservation->id, $outcome, $attribution, $requestId, $modelCode, true);
                    $completed = $usage['_invocation'];
                    unset($usage['_invocation']);
                    return ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($completed), 'invocation_id' => (int) $completed->id, 'content' => $outcome->content, 'usage' => $usage['tokens'] + $usage];
                }
                if ((string) $existing->state === 'succeeded' && (string) $meetingDispatch->state === 'succeeded') {
                    return $this->replayExisting($existing, $environmentId, $receiptCapabilityCode, $requestFingerprint, $receiptEnabled, false, $outcomeReceipts);
                }
                if (!$resumeMeetingPrepared) throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Meeting dispatch cannot be safely replayed');
            }
            if ($knowledgeAnswerAttribution !== null && !$resumeSourcePrepared && ((string) $existing->state === 'running'
                || ($skillSourceSuggestionContext !== null && (string) $existing->state === 'succeeded'))) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                if (!$receiptEnabled || $outcomeReceipts === null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer recovery requires a private receipt');
                }
                $context = $this->receiptContext($existing, $environmentId, $receiptCapabilityCode, $requestFingerprint);
                $probe = $outcomeReceipts->probe((int) $existing->id, $context);
                if ($probe === 'missing') {
                    throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Knowledge answer is still in progress');
                }
                if ($probe !== 'valid') {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer receipt is invalid');
                }
                $knowledgeRecoveredOutcome = KnowledgeProviderOutcome::decode($outcomeReceipts->replay($context));
                $dispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $existing->id)->find();
                if ($dispatch === null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer dispatch cannot be recovered');
                }
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
                $usage = $this->finalizeKnowledgeOutcome($existing, $dispatch, $reservation === null ? null : (int) $reservation->id, $knowledgeRecoveredOutcome, $attribution, $requestId, $modelCode, true);
                $completed = $usage['_invocation'];
                unset($usage['_invocation']);
                return ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($completed), 'invocation_id' => (int) $completed->id, 'content' => $knowledgeRecoveredOutcome->content, 'usage' => $usage['tokens'] + $usage];
            }
            if ($skillAttribution !== null && (string) $existing->state === 'running' && $receiptEnabled && $outcomeReceipts !== null) {
                $probe = $outcomeReceipts->probe((int) $existing->id, $this->receiptContext($existing, $environmentId, $receiptCapabilityCode, $requestFingerprint));
                if ($probe === 'missing') {
                    throw new ApiProblem('SAND_AI_SKILL_RUN_IN_PROGRESS', 'Skill provider outcome is not yet recoverable');
                }
                if ($probe !== 'valid') {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill outcome receipt is invalid');
                }
                $recoverSkillReceipt = true;
            } elseif (!$resumeSourcePrepared && !$resumeMeetingPrepared && ($internalContext === null || (string) $existing->state !== 'running')) {
                $this->authorizePublic($authorizePublicInvocation, (int) $existing->id);
                $replay = $this->replayExisting($existing, $environmentId, $receiptCapabilityCode, $requestFingerprint, $receiptEnabled, $allowIncompleteReceiptReplay, $outcomeReceipts);
                return $lowCostRequest ? ['selected_model' => $modelCode] + $replay : $replay;
            }
        }

        try {
        if ($lowCostRequest && $existing === null) {
            $selection = (new LowCostChatRouteResolver())->select($environmentId, $messages, $options->maxOutputTokens, $attribution,
                streamSegments: $streamSegments);
            $modelCode = $selection['model_code'];
            $selectedDeploymentId = $selection['deployment_id'];
        }
        $model = AiModel::where('code', $modelCode)->where('status', 1)->find();
        if ($model === null) {
            throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'The requested model is not available to this application');
        }
        if ((string) $model->type !== 'chat') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The requested model does not support chat completion');
        }

        $profileResolver = new CapabilityProfileResolver();
        $profileRoutes = $skillAttribution === null ? $profileResolver->routes($environmentId, $capabilityCode) : [];
        if ($skillAttribution === null && !$profileResolver->hasPublishedProfile($environmentId)) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published capability profile is available for this environment');
        $deploymentId = null;
        $routeSnapshot = null;
        $profile = null;
        $capabilityRoute = null;
        $profileRevision = null;
        if ($skillAttribution !== null) {
            $candidate = ModelDeployment::where('id', $skillAttribution->deploymentId)->where('status', 1)->find();
            if ($candidate === null || (int) $candidate->model_id !== (int) $model->id) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Frozen Skill route is unavailable');
            $deploymentId = (int) $candidate->id; $routeSnapshot = $skillAttribution->executionSnapshot;
        }
        foreach ($skillAttribution === null ? $profileRoutes : [] as $route) {
            $candidate = ModelDeployment::where('id', (int) $route['deployment_id'])->find();
            if ($candidate !== null && (int) $candidate->model_id === (int) $model->id
                && ($selectedDeploymentId === null || (int) $candidate->id === $selectedDeploymentId)
                && ($selection === null || (int) $route['route_id'] === $selection['route_id'])
                && ($meetingRoute === null || ((int) $route['route_id'] === $meetingRoute['route_id']
                    && (int) $route['profile_id'] === $meetingRoute['profile_id']
                    && (int) $route['profile_revision'] === $meetingRoute['profile_revision']))) {
                $deploymentId = (int) $candidate->id;
                $profile = CapabilityProfile::where('id', (int) $route['profile_id'])->where('environment_id', $environmentId)
                    ->where('revision', (int) $route['profile_revision'])->where('state', 'published')->where('status', 1)->find();
                $capabilityRoute = $profile === null ? null : CapabilityRoute::where('id', (int) $route['route_id'])
                    ->where('profile_id', (int) $profile->id)->where('capability_code', $capabilityCode)
                    ->where('deployment_id', $deploymentId)->where('status', 1)->find();
                $profileRevision = $profile === null ? null : ConfigRevision::where('resource_type', 'capability_profile')
                    ->where('resource_id', (int) $profile->id)->where('revision', (int) $profile->revision)
                    ->where('state', 'published')->where('status', 1)->find();
                if ($profile === null || $capabilityRoute === null || $profileRevision === null) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published capability route is available for this model');
                }
                break;
            }
        }
        if ($deploymentId === null) {
            if ($selection !== null) {
                throw new ApiProblem('SAND_AI_LOW_COST_ROUTE_CHANGED', 'Selected route is no longer published for this request');
            }
            throw new ApiProblem('SAND_AI_MODEL_NOT_AVAILABLE', 'The requested model is not enabled by this capability profile');
        }
        $deployment = ModelDeployment::where('id', $deploymentId)->where('status', 1)->find();
        $deploymentRevision = $deployment === null ? null : ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', $deployment->id)->where('revision', $deployment->revision)->where('state', 'published')->where('status', 1)->find();
        if ($deployment === null || $deploymentRevision === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published deployment is available for this model');
        }
        $provider = Provider::where('id', $deployment->provider_id)->where('status', 1)->find();
        if ($provider === null) {
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'No supported provider is available for this deployment');
        }
        if (!in_array((string) $provider->adapter, ['fake', 'openai_compatible'], true)) {
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'No supported provider is available for this deployment');
        }
        if ($streamSegments && (string) $provider->adapter !== 'openai_compatible') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'This provider does not support verified segments');
        }
        if ($skillAttribution !== null) {
            $this->assertSkillExecutionSnapshot($skillAttribution, $model, $deployment, $provider, $options);
            $routeSnapshot = $skillAttribution->executionSnapshot;
        } else {
            if ($profile === null || $capabilityRoute === null || $profileRevision === null) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'No published capability route is available for this model');
            }
            $routeSnapshot = DispatchRouteSnapshot::freeze(
                $profile, $capabilityRoute, $deployment, $model, $provider, $deploymentRevision, $profileRevision,
                selectionKind: $lowCostRequest ? 'lowest_cost' : 'capability_profile',
            );
            if ($selection !== null && !hash_equals($selection['route_snapshot_sha256'], $routeSnapshot['snapshot_sha256'])) {
                throw new ApiProblem('SAND_AI_LOW_COST_ROUTE_CHANGED', 'Selected route changed before request preparation');
            }
            if ($meetingRoute !== null) {
                MeetingSummaryRoute::assertSame($meetingRoute, $routeSnapshot);
                MeetingSummaryRoute::assertOutputBound($deployment->config, $options->maxOutputTokens);
            }
        }
        if (($materialReviewOutput ?? $legalOutput) !== null && \app\Application\Skill\SkillOutputStrategy::isStructured(($materialReviewOutput ?? $legalOutput)->strategy)
            && (string) $provider->adapter !== 'openai_compatible') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The selected adapter cannot construct the configured official review protocol');
        }
        if ((string) $provider->adapter === 'openai_compatible') {
            $options = new ChatCompletionOptions(
                $options->maxOutputTokens,
                DispatchRouteSnapshot::reasoningEffortFromRevision($routeSnapshot, $deploymentRevision),
                $options->skillOutput,
            );
        }

        } catch (ApiProblem $problem) {
            if ($resumeMeetingPrepared && $existing !== null && $meetingDispatch !== null) {
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                $this->finalizePreProviderFailure((int) $meetingDispatch->id, $existing,
                    $reservation === null ? null : (int) $reservation->id, $executionScope, $problem);
            }
            throw $rejectionStore->recordOrReplay(new PreDispatchRejectionEvidence(
                $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint, $problem,
            ));
        }

        $startedAt = microtime(true);
        $dispatch = null;
        $dispatchContext = null;
        $dispatchPreparation = null;
        try {
            $runtimeConfig = $this->providerRuntimeConfig($provider);
            if ((string) $provider->adapter === 'openai_compatible') {
                $runtimeConfig = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($runtimeConfig);
            }
            $providerCapability = ProviderIdempotencyCapability::fromServerConfig((string) $provider->adapter, $runtimeConfig);
            $body = (string) $provider->adapter === 'openai_compatible'
                ? $this->openAiCompatibleProvider()->requestBody($runtimeConfig, (string) $deployment->remote_model, $messages, $options, $streamSegments, $agentToolRequest)
                : ($agentToolRequest !== null ? throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED','Agent tools require a supported real chat provider') : ProviderRequestBodyBuilder::chat((string) $deployment->remote_model, $messages, $options));
            $digest = ProviderRequestBodyBuilder::digest($body);
            if ($selection !== null && !hash_equals($selection['request_body_sha256'], $digest)) {
                throw new ApiProblem('SAND_AI_LOW_COST_ROUTE_CHANGED', 'Selected request body changed before budget preparation');
            }
            if ($internalContext?->crossFileReview === true) {
                (new \app\Application\Workflow\MaterialCrossFileReviewOptionsStore())->freeze(
                    $internalContext,
                    $options->maxOutputTokens,
                    (string) $provider->adapter === 'openai_compatible'
                        ? (string) $runtimeConfig['output_token_parameter'] : 'max_tokens',
                );
            }
            $providerKey = $this->providerIdempotencyKey($providerCapability, $executionScope->logicalExecutionId, $digest);
            $dispatchContext = new ProviderDispatchContext($executionScope->logicalExecutionId, $digest, $providerKey);
            $dispatchPreparation = function (Invocation $createdInvocation, CallStartPricingSnapshot $pricingSnapshot) use (&$dispatch, $executionScope, $environmentId, $provider, $deployment, $model, $capabilityCode, $requestId, $digest, $providerCapability, $providerKey, $routeSnapshot, $attribution, $skillAttribution, $skillSourceSuggestionContext): void {
                $skillSourceSuggestionContext?->bindInvocation($createdInvocation);
                $dispatch = (new DispatchCoordinator())->prepare([
                    'invocation_id' => (int) $createdInvocation->id, 'environment_id' => $environmentId, 'execution_scope' => $executionScope->scope, 'logical_execution_id' => $executionScope->logicalExecutionId,
                    'logical_request_id' => hash('sha256', $requestId), 'parent_workflow_run_id' => $executionScope->workflowRunId,
                    'parent_task_run_id' => $executionScope->taskRunId, 'parent_task_step_id' => $executionScope->taskStepId,
                    'parent_workflow_run_file_id' => $executionScope->workflowRunFileId, 'workflow_node_code' => $executionScope->logicalNode, 'attempt_no' => $executionScope->attemptNo,
                    'application_id' => $attribution->applicationId, 'service_action' => $attribution->serviceAction, 'source_kind' => $attribution->sourceKind,
                    'skill_id' => $skillAttribution?->skillId, 'skill_version_id' => $skillAttribution?->skillVersionId, 'skill_run_id' => $skillAttribution?->skillRunId,
                    'provider_id' => (int) $provider->id, 'deployment_id' => (int) $deployment->id, 'model_id' => (int) $model->id,
                    'capability_code' => $capabilityCode,
                    'route_snapshot' => $routeSnapshot,
                    'price_snapshot' => $pricingSnapshot->dispatchSnapshot(),
                    'execution_snapshot_sha256' => $executionScope->executionSnapshotSha256, 'request_body_digest' => $digest,
                    'provider_idempotency_mode' => $providerCapability->mode, 'provider_idempotency_key' => $providerKey,
                    'lease_owner' => $executionScope->leaseOwner, 'lease_token' => $executionScope->leaseToken, 'lease_fence' => $executionScope->leaseFence,
                ]);
            };
        } catch (ApiProblem $problem) {
            if ($resumeMeetingPrepared && $existing !== null && $meetingDispatch !== null) {
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                $this->finalizePreProviderFailure((int) $meetingDispatch->id, $existing,
                    $reservation === null ? null : (int) $reservation->id, $executionScope, $problem);
            }
            throw $rejectionStore->recordOrReplay(new PreDispatchRejectionEvidence(
                $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint, $problem,
            ));
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider request could not be prepared');
            if ($resumeMeetingPrepared && $existing !== null && $meetingDispatch !== null) {
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                $this->finalizePreProviderFailure((int) $meetingDispatch->id, $existing,
                    $reservation === null ? null : (int) $reservation->id, $executionScope, $problem);
            }
            throw $rejectionStore->recordOrReplay(new PreDispatchRejectionEvidence(
                $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint, $problem,
            ));
        }
        $budgetMessages = $agentToolRequest === null ? $messages : $agentToolRequest->budgetAdvisoryMessages($messages);
        if ($options->skillOutput !== null && \app\Application\Skill\SkillOutputStrategy::isStructured($options->skillOutput->strategy)) {
            // Include official schema/messages in the advisory. Existing hard
            // reservations still use the verified deployment token ceiling.
            $budgetMessages = array_map(static fn (string $chunk): array => ['role' => 'user', 'content' => $chunk], mb_str_split(ProviderRequestBodyBuilder::bytes($body), 16000, 'UTF-8'));
        }
        $reservationTtlSeconds = min(3600, max(60, (int) ceil(max(1000, (int) $provider->timeout_ms) / 1000) + 60));
        try {
            // Reserve the published official protocol capacity against this exact SDK body.
            $budget = (new ChatBudgetGuard())->prepare(
                $environmentId, (int) $model->id, $deployment, $budgetMessages, $options, $requestId, $requestFingerprint,
                $reservationTtlSeconds, $capabilityCode, $internalContext, $dispatchPreparation, $skillAttribution,
                function (Invocation $rejectedInvocation, ApiProblem $problem) use ($rejectionStore, $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint): void {
                    $rejectionStore->recordWithinTransaction(new PreDispatchRejectionEvidence(
                        $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint, $problem, (int) $rejectedInvocation->id,
                    ));
                },
                RequestIdempotencyKey::trustedScopeForAttribution($attribution),
                $attribution,
                $knowledgeAnswerAttribution,
                providerRequestBody: $body,
                providerRouteSnapshot: $routeSnapshot,
            );
        } catch (ApiProblem $problem) {
            if ($resumeMeetingPrepared && $existing !== null && $meetingDispatch !== null) {
                $reservation = BudgetReservation::where('invocation_id', (int) $existing->id)->order('policy_id')->order('id')->find();
                $this->finalizePreProviderFailure((int) $meetingDispatch->id, $existing,
                    $reservation === null ? null : (int) $reservation->id, $executionScope, $problem);
            }
            if ($problem->errorCode === 'SAND_AI_PRICING_SNAPSHOT_INVALID') {
                throw $rejectionStore->recordOrReplay(new PreDispatchRejectionEvidence(
                    $environmentId, $attribution, $executionScope, $requestId, $requestFingerprint, $problem,
                ));
            }
            throw $problem;
        }
        $invocation = $budget['invocation'];
        if ($selection !== null && ($budget['resume_prepared'] ?? false) === true
            && ((int) $invocation->model_id !== (int) $model->id
                || (int) $invocation->deployment_id !== (int) $deployment->id)) {
            throw new ApiProblem('SAND_AI_LOW_COST_ROUTE_CHANGED', 'Retry this request to resume its frozen low-cost route');
        }
        if ($selection !== null && !$budget['idempotent_replay']
            && ($budget['resume_prepared'] ?? false) !== true
            && $budget['price_revision_id'] !== $selection['price_revision_id']) {
            $problem = new ApiProblem('SAND_AI_LOW_COST_ROUTE_CHANGED', 'Selected route price changed before dispatch');
            if ($dispatch !== null) {
                $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $budget['reservation_id'], $executionScope, $problem);
            }
            throw $problem;
        }
        if (($budget['resume_prepared'] ?? false) === true) {
            $dispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $invocation->id)->where('state', 'prepared')->find();
            if ($dispatch === null || $dispatchContext === null) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Prepared dispatch resume is missing trusted context');
            }
        }
        if ($budget['idempotent_replay']) {
            $this->authorizePublic($authorizePublicInvocation, (int) $invocation->id);
            if ($lowCostRequest && $existing === null) {
                $modelCode = $this->frozenLowCostRoute($invocation)['model_code'];
            }
            if ($recoverSkillReceipt && $skillAttribution !== null && $outcomeReceipts !== null) {
                $skillOutcome = SkillProviderOutcome::decode($outcomeReceipts->replay($this->receiptContext($invocation, $environmentId, $receiptCapabilityCode, $requestFingerprint)));
                $skillDispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
                $usage = $this->finalizeSkillOutcome($invocation, $skillOutcome, $deployment, $provider, $model, $attribution, $budget['reservation_id'], $budget['price_revision_id'], $budget['pricing_snapshot'], $routeSnapshot, $skillAttribution, $capabilityCode, true, $skillDispatch === null ? null : (int) $skillDispatch->id, ProviderDispatchTerminalizationPolicy::succeeded());
                $lockedInvocation = $usage['_invocation']; unset($usage['_invocation']);
                return ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($lockedInvocation), 'invocation_id' => (int) $lockedInvocation->id, 'content' => $skillOutcome->content, 'provider_outcome' => $skillOutcome->encode(), 'usage' => $usage];
            }
            if ($skillAttribution !== null && (string) $invocation->state === 'running') {
                throw new ApiProblem('SAND_AI_SKILL_RUN_IN_PROGRESS', 'Skill provider outcome is not yet recoverable');
            }
            if ($skillSourceSuggestionContext !== null) {
                $skillSourceSuggestionContext->assertInvocation($invocation);
                $outcomeReceipts->renewPendingSkillSourceSuggestion((int) $invocation->id, $skillSourceSuggestionContext->suggestionId);
                $context = $this->receiptContext($invocation, $environmentId, $receiptCapabilityCode, $requestFingerprint);
                $probe = $outcomeReceipts->probe((int) $invocation->id, $context);
                if ($probe !== 'valid') {
                    throw new ApiProblem($probe === 'missing' ? 'SAND_AI_DISPATCH_AMBIGUOUS' : 'SAND_AI_RECEIPT_INVALID', 'Skill source suggestion outcome is unavailable');
                }
                $outcome = KnowledgeProviderOutcome::decode($outcomeReceipts->replay($context));
                $sourceDispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
                if ($sourceDispatch === null) {
                    throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion dispatch is unavailable');
                }
                $sourceReservation = BudgetReservation::where('invocation_id', (int) $invocation->id)->order('policy_id', 'asc')->order('id', 'asc')->find();
                $usage = $this->finalizeKnowledgeOutcome($invocation, $sourceDispatch, $sourceReservation === null ? null : (int) $sourceReservation->id, $outcome, $attribution, $requestId, $modelCode, true);
                $completed = $usage['_invocation']; unset($usage['_invocation']);
                return ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($completed), 'invocation_id' => (int) $completed->id, 'content' => $outcome->content, 'usage' => $usage['tokens'] + $usage];
            }
            $replay = $this->replayExisting($invocation, $environmentId, $receiptCapabilityCode, $requestFingerprint, $receiptEnabled, $allowIncompleteReceiptReplay, $outcomeReceipts);
            return $lowCostRequest ? ['selected_model' => $modelCode] + $replay : $replay;
        }
        $reservationId = $budget['reservation_id'];
        $estimatedAmount = $budget['estimated_amount'];
        $priceRevisionId = $budget['price_revision_id'];
        $pricingSnapshot = $budget['pricing_snapshot'];
        if ($dispatch === null || $dispatchContext === null) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'The invocation did not create a dispatch record');
        }
        if ($this->afterPreparedDispatch !== null) {
            ($this->afterPreparedDispatch)($dispatch);
        }
        try {
            // Every non-I/O Provider check happens while the ledger is still
            // prepared. This preserves the only safe not_dispatched closure.
            $preparedProviderCall = $this->prepareProviderCall(
                $provider, $deployment, $messages, $options, $dispatchContext, $streamSegments,
                $acceptSegment === null ? null : static function (array $segment) use ($acceptSegment, $invocation, $dispatch): array {
                    return $acceptSegment($segment, (int) $invocation->id, (int) $dispatch->id);
                },
                ($capabilityCode === 'structured_extraction' || $skillAttribution !== null || $meetingReceipt || $useNeuronSourceSuggestion) && !$streamSegments,
                $agentToolRequest,
                $acceptAgentChunk === null ? null : static function (\NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk $chunk) use ($acceptAgentChunk, $invocation, $dispatch): void {
                    $acceptAgentChunk($chunk, (int) $invocation->id, (int) $dispatch->id);
                },
            );
        } catch (ApiProblem $problem) {
            $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
            throw $problem;
        } catch (\Throwable) {
            $problem = new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider request could not be prepared');
            $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
            throw $problem;
        }
        try {
            $this->authorizePublic($authorizePublicInvocation, (int) $invocation->id);
        } catch (ApiProblem $problem) {
            $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
            throw $problem;
        }
        if ($internalContext !== null) {
            try {
                $frozenRoute = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
                if (!is_array($frozenRoute)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Prepared dispatch route snapshot is invalid');
                }
                $authorization = ($this->dispatchAuthorizer ?? new FormalWorkflowDispatchAuthorizer())->decide($internalContext, $attribution, $provider, $deployment, $capabilityCode, $dispatchContext, $frozenRoute, $priceRevisionId);
            } catch (ApiProblem $problem) {
                // Frozen route reads and every SandIAM failure occur before the
                // Provider-visible boundary. They must take the same atomic
                // prepared -> not_dispatched closure as prepare failures.
                $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
                throw $problem;
            } catch (\Throwable) {
                $problem = new ApiProblem('SAND_AI_DISPATCH_NOT_AUTHORIZED', 'Trusted dispatch authorization is unavailable');
                $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
                throw $problem;
            }
            try {
                $claim = (new DispatchCoordinator())->claimForDispatch((int) $dispatch->id, $invocation, $reservationId, $internalContext, $attribution, $provider, $deployment, (int) $model->id, $capabilityCode, $priceRevisionId, $authorization, $dispatchContext, $providerCapability);
            } catch (\Throwable) {
                $problem = new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider dispatch claim failed');
                $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
                throw $problem;
            }
            if (!$claim->claimed && $claim->problem !== null) {
                throw $claim->problem;
            }
            if (!$claim->claimed) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Dispatch claim rejected without a stable problem');
            }
            if ($claim->provider === null || $claim->deployment === null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Dispatch claim did not return its locked provider route');
            }
            $provider = $claim->provider;
            $deployment = $claim->deployment;
        } else {
            try {
                $claim = (new DispatchCoordinator())->claimForScope((int) $dispatch->id, $invocation, $reservationId, $executionScope, $provider, $deployment, (int) $model->id, $capabilityCode, $priceRevisionId,
                    meetingRequest: $meetingReceipt ? $dispatchContext : null,
                    meetingCapability: $meetingReceipt ? $providerCapability : null,
                    attribution: $attribution);
            } catch (\Throwable) {
                $problem = new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider dispatch claim failed');
                $this->finalizePreProviderFailure((int) $dispatch->id, $invocation, $reservationId, $executionScope, $problem);
                throw $problem;
            }
            if (!$claim->claimed || $claim->provider === null || $claim->deployment === null) {
                throw $claim->problem ?? new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Dispatch claim rejected without a stable problem');
            }
            $provider = $claim->provider;
            $deployment = $claim->deployment;
        }
        $providerDispatchStarted = false;
        // Once a Skill outcome receipt is durable, a local finalizer failure is
        // recoverable state, not a provider failure.  Keep Invocation running
        // so the fenced Skill claimant can resume without another HTTP call.
        $durableRecoverableOutcome = false;
        $durableRecoverableAccounting = false;
        $receivedPurgedSkillAccounting = false;
        $contentPurged = false;
        $identityStore = new ProviderResponseIdentityStore();
        $sendBoundary = new ProviderSendBoundary(
            $authorizePublicInvocation === null ? null
                : function () use ($authorizePublicInvocation, $invocation): void {
                    $this->authorizePublic($authorizePublicInvocation, (int) $invocation->id);
                },
            static function (ProviderResponseIdentity $identity) use ($identityStore, $invocation, $dispatch): void {
                $identityStore->record((int) $invocation->id, (int) $dispatch->id, $identity);
            },
        );
        try {
            $identityStore->assertAvailable((int) $invocation->id, (int) $dispatch->id);
            $result = (new DispatchCoordinator())->executeClaimedProvider(
                static function () use ($preparedProviderCall, $sendBoundary): array {
                    try {
                        $result = $preparedProviderCall->send($sendBoundary);
                    } catch (\Throwable $error) {
                        // SDK adapters may wrap the gate's exception. Its live
                        // proof retains the original IAM refusal and unsent state.
                        if ($sendBoundary->authorizationRejection() !== null) throw $sendBoundary->authorizationRejection();
                        // Preserve independently measured failure costs even if
                        // their observation callback could not commit an ID.
                        if ($error instanceof MeasuredChatFailure) throw $error;
                        throw $sendBoundary->identityRejection() ?? $error;
                    }
                    if (($problem = $sendBoundary->authorizationRejection() ?? $sendBoundary->identityRejection()) !== null) {
                        throw $problem;
                    }
                    return $result;
                },
            );
            $providerDispatchStarted = $sendBoundary->started();
            $providerTerminalization = ProviderDispatchTerminalizationPolicy::succeeded();
            $latency = (int) round((microtime(true) - $startedAt) * 1000);
            $result['latency_ms'] = $latency;
            $completedAt = date('Y-m-d H:i:s');
            // Persist the private receipt before any mutable completion/usage
            // finalizer. A later local failure can therefore replay the exact
            // provider completion without dispatching a second provider call.
            $knowledgeOutcome = null;
            $agentOutcome = null;
            if ($receiptEnabled) {
                $structuredOutcome = $capabilityCode === 'structured_extraction'
                    ? KnowledgeProviderOutcome::fromProviderResult(
                        $result, $attribution, $provider, $model, $deployment, (new UsageMeter())->usageRouteSnapshot($routeSnapshot),
                    ) : null;
                $knowledgeOutcome = $knowledgeAnswerAttribution === null && !$meetingReceipt ? null : KnowledgeProviderOutcome::fromProviderResult(
                    $result, $attribution, $provider, $model, $deployment, (new UsageMeter())->usageRouteSnapshot($routeSnapshot),
                );
                if ($agentToolRequest !== null) {
                    $agentOutcome = AgentToolProviderOutcome::fromProviderResult($result, $attribution, $provider, $model, $deployment, (new UsageMeter())->usageRouteSnapshot($routeSnapshot));
                    $knowledgeOutcome = $agentOutcome->accounting;
                    $receiptPayload = $agentOutcome->encode();
                } elseif ($structuredOutcome !== null) {
                    $receiptPayload = StructuredProviderOutcome::encode($structuredOutcome);
                } else {
                    $receiptPayload = $knowledgeOutcome !== null
                        ? $knowledgeOutcome->encode()
                        : ($skillAttribution === null ? (string) $result['content'] : SkillProviderOutcome::fromProviderResult($result)->encode());
                }
                try {
                    $outcomeReceipts->write(
                        $receiptPayload,
                        $this->receiptContext($invocation, $environmentId, $receiptCapabilityCode, $requestFingerprint),
                        $outcomeReceiptRequest->maxPlaintextBytes,
                    );
                    $durableRecoverableOutcome = $skillAttribution !== null || $knowledgeOutcome !== null || $structuredOutcome !== null;
                } catch (ApiProblem $problem) {
                    if ($problem->errorCode === 'SAND_AI_SKILL_SOURCE_CONTENT_PURGED'
                        && $skillSourceSuggestionContext !== null && $knowledgeOutcome !== null) {
                        // The Provider result is known. A privacy tombstone is not
                        // a transport ambiguity, including if proof persistence fails.
                        $receivedPurgedSkillAccounting = true;
                        $knowledgeOutcome = SkillSourceSuggestionUsageProof::recordPurgedResult(
                            $skillSourceSuggestionContext, $invocation, $knowledgeOutcome,
                        );
                        $durableRecoverableAccounting = true;
                        $contentPurged = true;
                    } elseif (!in_array($problem->errorCode, [
                            'SAND_AI_WORKFLOW_CONTENT_PURGED',
                            'SAND_AI_AUDIO_CONTENT_PURGED',
                        ], true)
                        || $internalContext === null) {
                        throw $problem;
                    } else {
                        $contentPurged = true;
                    }
                }
            }
            try {
                if ($skillAttribution !== null) {
                    $usage = $this->finalizeSkillOutcome($invocation, SkillProviderOutcome::fromProviderResult($result), $deployment, $provider, $model, $attribution, $reservationId, $priceRevisionId, $pricingSnapshot, $routeSnapshot, $skillAttribution, $capabilityCode, false, $dispatch === null ? null : (int) $dispatch->id, $providerTerminalization);
                    $invocation = $usage['_invocation']; unset($usage['_invocation']);
                } elseif ($knowledgeOutcome !== null) {
                    $usage = $this->finalizeKnowledgeOutcome($invocation, $dispatch, $reservationId, $knowledgeOutcome, $attribution, $requestId, $modelCode, false);
                    $invocation = $usage['_invocation']; unset($usage['_invocation']);
                } else {
                $finalizer = new DispatchFinalizer();
                $usage = $finalizer->success(function () use ($finalizer, $invocation, $latency, $completedAt, $deployment, $provider, $model, $attribution, $environmentId, $result, $requestId, $modelCode, $reservationId, $priceRevisionId, $pricingSnapshot, $capabilityCode, $receiptEnabled, $outcomeReceipts, $requestFingerprint, $outcomeReceiptRequest, $dispatch, $internalContext, $routeSnapshot, $skillAttribution, $providerTerminalization): array {
                    $terminal = $dispatch === null ? null : $finalizer->lockInternalTerminal($reservationId, (int) $invocation->id, (int) $dispatch->id);
                    $completion = $terminal === null
                        ? ($reservationId === null ? ['expired' => false] : (new ChatBudgetGuard())->lockForCompletion($reservationId))
                        : $terminal['completion'];
                    if ($terminal !== null) {
                        $invocation = $terminal['invocation'];
                        $dispatch = $terminal['dispatch'];
                    }
                    // A provider outcome is factual once returned. An expired
                    // reservation is accounted by settle(), never rewritten as
                    // a failed invocation after the provider has succeeded.
                    if (!$providerTerminalization->isSucceeded()) {
                        throw new \LogicException('Provider success finalization requires a succeeded outcome');
                    }
                    $successMutation = DispatchFinalizer::lateMeasuredSuccessMutation(['state' => (string) $invocation->state]);
                    if ($successMutation === null || $providerTerminalization->invocationState !== $successMutation['state']) {
                        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Invocation cannot be finalized as succeeded');
                    }
                    $invocation->save($successMutation + ['latency_ms' => $latency, 'completed_at' => $completedAt]);
                    $usage = (new UsageMeter())->meter(
                        $invocation,
                        $deployment,
                        $provider,
                        $model,
                        $attribution,
                        (int) $result['input_tokens'],
                        (int) $result['output_tokens'],
                        (bool) ($result['usage_measured'] ?? true),
                        $pricingSnapshot,
                        $routeSnapshot,
                        $skillAttribution,
                    );
                    Db::connect('pgsql')->query(
                        'INSERT INTO sand_ai_usage (invocation_id, input_tokens, output_tokens, total_tokens, cost_amount, currency, source) VALUES (?, ?, ?, ?, CAST(? AS numeric(20,6)), ?, ?)',
                        [$invocation->id, $result['input_tokens'], $result['output_tokens'], $result['input_tokens'] + $result['output_tokens'], $usage['metered_amount'] === null ? null : (string) $usage['metered_amount'], $usage['metered_amount'] === null ? null : $usage['currency'], (string) $provider->adapter]
                    );
                    $audit = ModelCapabilityInvocationPolicy::audit($capabilityCode, $requestId, $modelCode);
                    AuditLog::create([
                        'actor_type' => 'api_credential',
                        'actor_ref' => (string) $environmentId,
                        'action' => $audit['action'],
                        'resource_type' => 'invocation',
                        'resource_id' => $invocation->id,
                        'summary' => $capabilityCode === 'chat' ? 'Chat completion succeeded' : 'Model capability completion succeeded',
                        'context' => $audit['context'],
                    ]);
                    if ($reservationId !== null) {
                        $budgetGuard = new ChatBudgetGuard();
                        if ($providerTerminalization->budgetAction === 'settle') {
                            $budgetGuard->settle($reservationId, $usage['metered_amount'] === null ? null : (string) $usage['metered_amount'], $usage['metered_amount'] === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered', $priceRevisionId, $capabilityCode);
                        } elseif ($providerTerminalization->releasesBudget()) {
                            $budgetGuard->release($reservationId, 'provider_not_dispatched');
                        } else {
                            $budgetGuard->settle($reservationId, null, 'dispatch_ambiguous', null, $capabilityCode);
                        }
                    }
                    if ($dispatch !== null) {
                        (new DispatchCoordinator())->finalizeSuccessForScope((int) $dispatch->id, $internalContext, $providerTerminalization);
                    }
                    $usage['_invocation'] = $invocation;
                    return $usage;
                });
                $invocation = $usage['_invocation'];
                unset($usage['_invocation']);
                }
            } catch (ApiProblem $problem) {
                if ($durableRecoverableOutcome || $durableRecoverableAccounting || $receivedPurgedSkillAccounting) {
                    \support\Log::warning('SandAI completion is recoverable', [
                        'invocation_id' => (int) $invocation->id,
                        'error_code' => $problem->errorCode,
                    ]);
                    throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Completion is durably recoverable but not finalized', true);
                }
                if ($dispatch !== null) {
                    throw $problem;
                }
                if ($reservationId !== null) {
                    $this->finalizeFailedReservation($reservationId, $providerTerminalization ?? ProviderDispatchTerminalizationPolicy::succeeded(), 'usage_persistence_failed', $capabilityCode);
                }
                $invocation->save(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => $problem->errorCode, 'error_summary' => $problem->getMessage()]);
                throw $problem;
            } catch (\Throwable $throwable) {
                if ($durableRecoverableOutcome || $durableRecoverableAccounting || $receivedPurgedSkillAccounting) {
                    \support\Log::error('SandAI completion finalization threw', [
                        'invocation_id' => (int) $invocation->id,
                        'exception_class' => $throwable::class,
                        'exception_code' => (string) $throwable->getCode(),
                        'exception_origin' => basename($throwable->getFile()) . ':' . $throwable->getLine(),
                    ]);
                    throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Completion is durably recoverable but not finalized', true);
                }
                if ($dispatch !== null) {
                    throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'The completion result could not be recorded', true);
                }
                if ($reservationId !== null) {
                    (new ChatBudgetGuard())->settle($reservationId, null, 'usage_persistence_failed', null, $capabilityCode);
                }
                $invocation->save(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => 'SAND_AI_USAGE_PERSISTENCE_FAILED', 'error_summary' => 'Usage event persistence failed']);
                throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'The completion result could not be recorded', true);
            }
            $usage['estimated_amount'] = $estimatedAmount;
            $response = ['idempotent_replay' => false, 'invocation' => $this->invocationSummary($invocation), 'invocation_id' => (int) $invocation->id, 'content' => $contentPurged ? '' : $result['content'], 'provider_outcome' => $contentPurged || $skillAttribution === null ? null : SkillProviderOutcome::fromProviderResult($result)->encode(), 'usage' => $usage['tokens'] + $usage];
            if ($agentOutcome !== null) { $response['response_kind'] = $agentOutcome->response['response_kind']; $response['tool_calls'] = $agentOutcome->response['tool_calls']; }
            if ($contentPurged) {
                if ($skillSourceSuggestionContext !== null) {
                    unset($response['content'], $response['provider_outcome']);
                }
                $response['content_purged'] = true;
            }
            return $lowCostRequest ? ['selected_model' => $modelCode] + $response : $response;
        } catch (MeasuredChatFailure $failure) {
            if (!$sendBoundary->started() || $dispatch === null) {
                throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Measured provider failure has no claimed dispatch');
            }
            try {
                $this->finalizeMeasuredChatFailure(
                    (int) $dispatch->id, $invocation, $reservationId, $internalContext, $executionScope,
                    $capabilityCode, $failure, $deployment, $provider, $model,
                    $attribution, $pricingSnapshot, $priceRevisionId, $routeSnapshot,
                    $skillAttribution, (int) round((microtime(true) - $startedAt) * 1000),
                );
            } catch (\Throwable $error) {
                \support\Log::error('SandAI measured provider failure could not be finalized', [
                    'invocation_id' => (int) $invocation->id,
                    'exception_class' => $error::class,
                    'exception_origin' => basename($error->getFile()) . ':' . $error->getLine(),
                ]);
                throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Measured provider failure could not be recorded safely', true);
            }
            throw $failure->problem ?? new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned no completion content');
        } catch (ApiProblem $problem) {
            if ($sendBoundary->rejectedAuthorization($problem)) {
                (new DispatchCoordinator())->finalizeRejectedSendAuthorization(
                    (int) $dispatch->id, $invocation, $reservationId,
                    $executionScope, $problem, $sendBoundary,
                );
                throw $problem;
            }
            // send() may throw after crossing the Provider-visible boundary.
            // Read the same boundary in this path before choosing the ledger
            // state so transport, 429, and decode failures remain held.
            $providerDispatchStarted = $providerDispatchStarted || $sendBoundary->started();
            $providerTerminalization = ProviderDispatchTerminalizationPolicy::failed($problem, $sendBoundary);
            if ($durableRecoverableOutcome || $durableRecoverableAccounting || $receivedPurgedSkillAccounting) {
                \support\Log::warning('SandAI completion is recoverable', [
                    'invocation_id' => (int) $invocation->id,
                    'error_code' => $problem->errorCode,
                ]);
                throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Completion is durably recoverable but not finalized', true);
            }
            // The success finalizer already committed this terminal budget state.
            // Do not reopen it from the outer provider-failure path.
            if ($dispatch !== null && $problem->errorCode === 'SAND_AI_BUDGET_RESERVATION_EXPIRED') {
                throw $problem;
            }
            if ($dispatch !== null && (new DispatchLedgerGateway())->isDispatching((int) $dispatch->id)) {
                $this->finalizeDispatchFailure((int) $dispatch->id, $invocation, $reservationId, $internalContext, $executionScope, $capabilityCode, $problem, $providerTerminalization);
                if ($providerTerminalization->isAmbiguous()) {
                    throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Provider dispatch outcome is ambiguous and requires manual review');
                }
                throw $problem;
            }
            // A stale worker may observe an already-finalized ledger. Its outer
            // error path must only report that committed state, never mutate
            // budget, invocation, or ledger independently.
            if ($dispatch !== null) {
                throw $problem;
            }
            if ($reservationId !== null) {
                $this->finalizeFailedReservation($reservationId, $providerTerminalization, 'provider_failure', $capabilityCode);
            }
            $invocation->save([
                'state' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'error_code' => $problem->errorCode,
                'error_summary' => $problem->getMessage(),
            ]);
            if ($providerTerminalization->isAmbiguous()) {
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Provider dispatch outcome is ambiguous and requires manual review');
            }
            throw $problem;
        } catch (\Throwable $throwable) {
            $providerDispatchStarted = $providerDispatchStarted || $sendBoundary->started();
            $providerTerminalization = ProviderDispatchTerminalizationPolicy::forFailure(new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider execution failed', true), $providerDispatchStarted);
            if ($durableRecoverableOutcome || $durableRecoverableAccounting || $receivedPurgedSkillAccounting) {
                \support\Log::error('SandAI completion finalization threw', [
                    'invocation_id' => (int) $invocation->id,
                    'exception_class' => $throwable::class,
                    'exception_code' => (string) $throwable->getCode(),
                    'exception_origin' => basename($throwable->getFile()) . ':' . $throwable->getLine(),
                ]);
                throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Completion is durably recoverable but not finalized', true);
            }
            if ($dispatch !== null && DispatchTerminalMutationPolicy::mayFinalize((new DispatchLedgerGateway())->isDispatching((int) $dispatch->id) ? 'dispatching' : null)) {
                $problem = new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider execution failed', true);
                $this->finalizeDispatchFailure((int) $dispatch->id, $invocation, $reservationId, $internalContext, $executionScope, $capabilityCode, $problem, $providerTerminalization);
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Provider dispatch outcome is ambiguous and requires manual review');
            }
            if ($dispatch !== null) {
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Provider dispatch outcome is already finalized and requires manual review');
            }
            if ($reservationId !== null) {
                (new ChatBudgetGuard())->settle($reservationId, null, 'provider_failure', null, $capabilityCode);
            }
            $invocation->save(['state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'), 'error_code' => 'SAND_AI_PROVIDER_FAILURE', 'error_summary' => 'Provider execution failed']);
            throw $dispatch !== null
                ? new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Provider dispatch outcome is ambiguous and requires manual review')
                : new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider could not complete this request', true);
        }
    }

    private function authorizePublic(?callable $authorize, int $invocationId): void
    {
        if ($authorize === null) {
            return;
        }
        try {
            $authorize($invocationId);
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable $failure) {
            // The stable refusal below hides the original defect. Keep its class,
            // code, and origin observable without exposing any caller payload.
            \support\Log::error('SandAI public authorization threw', [
                'invocation_id' => $invocationId,
                'exception_class' => $failure::class,
                'exception_code' => (string) $failure->getCode(),
                'exception_origin' => basename($failure->getFile()) . ':' . $failure->getLine(),
            ]);
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Service authorization is unavailable');
        }
    }

    private function finalizePreProviderFailure(int $dispatchId, Invocation $invocation, ?int $reservationId, DispatchExecutionScope $scope, ApiProblem $problem): void
    {
        (new DispatchCoordinator())->finalizeNotDispatchedForScope(
            $dispatchId,
            $invocation,
            $reservationId,
            $scope,
            $problem->errorCode,
            $problem->getMessage(),
        );
    }

    private function finalizeFailedReservation(int $reservationId, ProviderDispatchTerminalization $terminalization, string $fallbackReason, string $capabilityCode): void
    {
        $guard = new ChatBudgetGuard();
        if ($terminalization->releasesBudget()) {
            $guard->release($reservationId, 'provider_not_dispatched');
            return;
        }
        $guard->settle($reservationId, null, $fallbackReason, null, $capabilityCode);
    }

    private function finalizeDispatchFailure(int $dispatchId, Invocation $invocation, ?int $reservationId, ?InternalModelInvocationContext $internalContext, DispatchExecutionScope $scope, string $capabilityCode, ApiProblem $problem, ProviderDispatchTerminalization $outcome): void
    {
        if ($internalContext !== null) {
            (new DispatchCoordinator())->finalizeProviderOutcome($dispatchId, $invocation, $reservationId, $internalContext, $capabilityCode, $problem, $outcome);
            return;
        }
        (new DispatchCoordinator())->finalizeProviderOutcomeForScope($dispatchId, $invocation, $reservationId, $scope, $capabilityCode, $problem, $outcome);
    }

    private function finalizeMeasuredChatFailure(
        int $dispatchId, Invocation $invocation, ?int $reservationId,
        ?InternalModelInvocationContext $internalContext, DispatchExecutionScope $scope,
        string $capabilityCode,
        MeasuredChatFailure $failure, ModelDeployment $deployment, Provider $provider,
        AiModel $model, UsageAttribution $attribution, CallStartPricingSnapshot $pricingSnapshot,
        ?int $priceRevisionId, ?array $routeSnapshot, ?SkillAttribution $skillAttribution,
        int $latency,
    ): void {
        $finalizer = new DispatchFinalizer();
        $finalizer->success(function () use (
            $finalizer, $dispatchId, $invocation, $reservationId, $internalContext, $scope,
            $capabilityCode, $failure, $deployment, $provider, $model, $attribution,
            $pricingSnapshot, $priceRevisionId, $routeSnapshot, $skillAttribution, $latency,
        ): void {
            $terminal = $finalizer->lockInternalTerminal($reservationId, (int) $invocation->id, $dispatchId);
            $locked = $terminal['invocation'];
            $ledger = $terminal['dispatch'];
            if ((string) $ledger->state !== 'dispatching'
                || (string) $ledger->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $ledger->lease_token, $scope->leaseToken)
                || (int) $ledger->lease_fence !== $scope->leaseFence) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Measured failure dispatch lease changed');
            }
            $usage = (new UsageMeter())->meter(
                $locked, $deployment, $provider, $model, $attribution,
                $failure->inputTokens, $failure->outputTokens, true,
                $pricingSnapshot, $routeSnapshot, $skillAttribution,
            );
            Db::connect('pgsql')->query(
                'INSERT INTO sand_ai_usage (invocation_id,input_tokens,output_tokens,total_tokens,cost_amount,currency,source) VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?)',
                [
                    (int) $locked->id, $failure->inputTokens, $failure->outputTokens,
                    $failure->inputTokens + $failure->outputTokens,
                    $usage['metered_amount'] === null ? null : (string) $usage['metered_amount'],
                    $usage['currency'], (string) $provider->adapter,
                ],
            );
            if ($reservationId !== null) {
                (new ChatBudgetGuard())->settle(
                    $reservationId,
                    $usage['metered_amount'] === null ? null : (string) $usage['metered_amount'],
                    $usage['metered_amount'] === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered',
                    $priceRevisionId, $capabilityCode,
                );
            }
            $problem=$failure->problem??new ApiProblem('SAND_AI_PROVIDER_FAILURE','Provider returned no completion content');
            (new DispatchLedgerGateway())->terminal($dispatchId,'failed',$problem->errorCode,$problem->getMessage(),$internalContext);
            $locked->save([
                'state' => 'failed', 'completed_at' => date('Y-m-d H:i:s'),
                'latency_ms' => $latency, 'error_code' => $problem->errorCode,
                'error_summary' => $problem->getMessage(),
            ]);
            AuditLog::create([
                'environment_id' => $attribution->environmentId,
                'actor_type' => 'system_worker',
                'actor_ref' => $internalContext === null
                    ? 'environment:' . $attribution->environmentId
                    : (string) $internalContext->workflowRunId,
                'action' => 'model.dispatch.failed',
                'resource_type' => 'invocation', 'resource_id' => (int) $locked->id,
                'summary' => $problem->getMessage(),
                'context' => ['error_code' => $problem->errorCode, 'usage_measured' => true],
            ]);
        });
    }

    /** @return array<string,mixed> */
    /** @return array<string,mixed> */
    public function recoverPurgedSkillSourceAccounting(SkillSourceSuggestionUsageProof $proof): array
    {
        $invocation = Invocation::find($proof->invocationId);
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion accounting invocation is unavailable');
        }
        $proof->assertInvocation($invocation);
        $dispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', $proof->invocationId)->find();
        if ($dispatch === null || (string) $dispatch->execution_scope !== 'chat'
            || !hash_equals((string) $invocation->request_fingerprint, $proof->requestFingerprint)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill source suggestion accounting dispatch is invalid');
        }
        $attribution = UsageAttribution::fromCurrentPersisted(
            $proof->attribution, (int) $invocation->environment_id,
            $proof->attribution['source_kind'], $proof->attribution['service_action'],
        );
        $reservation = BudgetReservation::where('invocation_id', $proof->invocationId)->order('policy_id', 'asc')->order('id', 'asc')->find();
        $usage = $this->finalizeKnowledgeOutcome(
            $invocation, $dispatch, $reservation === null ? null : (int) $reservation->id,
            $proof, $attribution, (string) $invocation->request_id, $proof->model['code'], true,
        );
        $completed = $usage['_invocation'];
        unset($usage['_invocation']);
        return ['content_purged' => true, 'invocation_id' => (int) $completed->id,
            'invocation' => $this->invocationSummary($completed), 'usage' => $usage['tokens'] + $usage];
    }

    private function finalizeKnowledgeOutcome(Invocation $invocation, \app\Infrastructure\Persistence\Model\InvocationDispatch $dispatch, ?int $reservationId, KnowledgeProviderOutcome|SkillSourceSuggestionUsageProof $outcome, UsageAttribution $currentAttribution, string $requestId, string $modelCode, bool $recovered): array
    {
        $finalizer = new DispatchFinalizer();
        return $finalizer->success(function () use ($finalizer, $invocation, $dispatch, $reservationId, $outcome, $currentAttribution, $requestId, $modelCode, $recovered): array {
            $locked = $finalizer->lockKnowledgeTerminal($reservationId, (int) $invocation->id, (int) $dispatch->id);
            $invocation = $locked['invocation'];
            $dispatch = $locked['dispatch'];
            $attribution = UsageAttribution::fromCurrentPersisted(
                $outcome->attribution, (int) $invocation->environment_id,
                $outcome->attribution['source_kind'], $outcome->attribution['service_action'],
            );
            if ($attribution->toPersisted() != $currentAttribution->toPersisted()
                || $attribution->sourceKind !== $currentAttribution->sourceKind
                || $attribution->serviceAction !== $currentAttribution->serviceAction
                || (int) $dispatch->environment_id !== (int) $invocation->environment_id
                || (int) $dispatch->provider_id !== $outcome->provider['id']
                || (int) $dispatch->model_id !== $outcome->model['id']
                || (int) $dispatch->deployment_id !== $outcome->deployment['id']
                || (int) $dispatch->application_id !== (int) $attribution->applicationId
                || (string) $dispatch->service_action !== $attribution->serviceAction
                || (string) $dispatch->source_kind !== $attribution->sourceKind
                || (int) $invocation->model_id !== $outcome->model['id']
                || (int) $invocation->deployment_id !== $outcome->deployment['id']
                || $outcome->model['code'] !== $modelCode) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer receipt differs from frozen dispatch');
            }
            $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
            if (!is_array($route)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer route is invalid');
            }
            DispatchRouteSnapshot::assert($route, false);
            if ($route['provider_id'] !== $outcome->provider['id']
                || $route['model_id'] !== $outcome->model['id']
                || $route['deployment_id'] !== $outcome->deployment['id']
                || $route['provider_adapter'] !== $outcome->providerAdapter
                || $route['model_code'] !== $outcome->model['code']
                || $route['deployment_remote_model'] !== $outcome->deployment['remote_model']
                || $route['deployment_revision'] !== $outcome->deployment['revision']
                || (new UsageMeter())->usageRouteSnapshot($route) != $outcome->deployment['route']) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer route differs from receipt');
            }
            $pricing = CallStartPricingSnapshot::fromPersistence(
                $invocation->call_started_at, $invocation->call_start_pricing_state,
                $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
            );
            $dispatchPrice = is_object($dispatch->price_snapshot) ? (array) $dispatch->price_snapshot : $dispatch->price_snapshot;
            if (!is_array($dispatchPrice) || $dispatchPrice != $pricing->dispatchSnapshot()
                || !in_array([(string) $invocation->state, (string) $dispatch->state], [
                    ['running', 'dispatching'], ['succeeded', 'succeeded'],
                ], true)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Knowledge answer terminal state is invalid');
            }
            $alreadySucceeded = (string) $invocation->state === 'succeeded';
            if (!$alreadySucceeded) {
                $invocation->save([
                    'state' => 'succeeded', 'error_code' => null, 'error_summary' => null,
                    'latency_ms' => $outcome->latencyMs, 'completed_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $usage = (new UsageMeter())->meterFrozen(
                $invocation, $attribution, $outcome->inputTokens, $outcome->outputTokens,
                $outcome->usageMeasured, $pricing, $outcome->provider, $outcome->model, $outcome->deployment,
            );
            $expected = [
                'input_tokens' => $outcome->inputTokens, 'output_tokens' => $outcome->outputTokens,
                'total_tokens' => $outcome->inputTokens + $outcome->outputTokens,
                'cost_amount' => $usage['metered_amount'], 'currency' => $usage['currency'],
                'source' => $outcome->providerAdapter,
            ];
            if (!$alreadySucceeded) {
                Db::connect('pgsql')->query(
                    'INSERT INTO sand_ai_usage (invocation_id,input_tokens,output_tokens,total_tokens,cost_amount,currency,source) VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?) ON CONFLICT (invocation_id) DO NOTHING',
                    [(int) $invocation->id, ...array_values($expected)],
                );
            }
            $legacy = Db::connect('pgsql')->query(
                'SELECT input_tokens,output_tokens,total_tokens,cost_amount,currency,source FROM sand_ai_usage WHERE invocation_id=?',
                [(int) $invocation->id],
            )[0] ?? null;
            if (!is_array($legacy)
                || (int) $legacy['input_tokens'] !== $expected['input_tokens']
                || (int) $legacy['output_tokens'] !== $expected['output_tokens']
                || (int) $legacy['total_tokens'] !== $expected['total_tokens']
                || !\app\Application\Usage\UsagePricing::matchesLegacyCost($expected['cost_amount'], $legacy['cost_amount'] ?? null)
                || (string) $legacy['currency'] !== (string) $expected['currency']
                || (string) $legacy['source'] !== $expected['source']) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Knowledge answer usage differs from receipt');
            }
            if (!$alreadySucceeded) {
                if ($reservationId !== null) {
                    (new ChatBudgetGuard())->settle(
                        $reservationId, $usage['metered_amount'],
                        $usage['metered_amount'] === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered',
                        $pricing->priceRevisionId, 'chat',
                    );
                }
                (new DispatchCoordinator())->finalizeSuccessForScope((int) $dispatch->id, null, ProviderDispatchTerminalizationPolicy::succeeded());
                $audit = ModelCapabilityInvocationPolicy::audit('chat', $requestId, $modelCode);
                AuditLog::create([
                    'actor_type' => 'api_credential', 'actor_ref' => (string) $invocation->environment_id,
                    'action' => $audit['action'], 'resource_type' => 'invocation',
                    'resource_id' => (int) $invocation->id,
                    'summary' => 'Chat completion succeeded', 'context' => $audit['context'] + ['recovered' => $recovered],
                ]);
            }
            $usage['_invocation'] = $invocation;
            return $usage;
        });
    }

    /** @return array<string,mixed> */
    private function finalizeSkillOutcome(Invocation $invocation, SkillProviderOutcome $outcome, ModelDeployment $deployment, Provider $provider, AiModel $model, UsageAttribution $attribution, ?int $reservationId, ?int $priceRevisionId, CallStartPricingSnapshot $pricingSnapshot, ?array $routeSnapshot, SkillAttribution $skillAttribution, string $capabilityCode, bool $recovered, ?int $dispatchId, ProviderDispatchTerminalization $providerTerminalization): array
    {
        SkillFinalizationPolicy::lockOrder();
        return Db::connect('pgsql')->transaction(function () use ($invocation, $outcome, $deployment, $provider, $model, $attribution, $reservationId, $priceRevisionId, $pricingSnapshot, $routeSnapshot, $skillAttribution, $capabilityCode, $recovered, $dispatchId, $providerTerminalization): array {
            if ($dispatchId !== null) {
                $terminal = (new DispatchFinalizer())->lockKnowledgeTerminal($reservationId, (int) $invocation->id, $dispatchId);
                $locked = $terminal['invocation'];
            } else {
                if ($reservationId !== null) {
                    $guard = new ChatBudgetGuard();
                    foreach ($guard->lockReservationGroup($reservationId) as $member) {
                        if ((int) $member['reservation']->invocation_id !== (int) $invocation->id) {
                            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Budget reservation group belongs to another invocation');
                        }
                    }
                    $guard->lockForCompletion($reservationId);
                }
                $locked = Invocation::where('id', (int) $invocation->id)->lock(true)->find();
                if ($locked === null) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill invocation is unavailable');
            }
            if (!$providerTerminalization->isSucceeded()) {
                throw new \LogicException('Skill provider success finalization requires a succeeded outcome');
            }
            $successMutation = DispatchFinalizer::lateMeasuredSuccessMutation(['state' => (string) $locked->state]);
            if ($successMutation !== null) {
                if ($providerTerminalization->invocationState !== $successMutation['state']) {
                    throw new \LogicException('Skill provider success finalization requires a succeeded invocation');
                }
                $locked->save($successMutation + ['latency_ms' => $outcome->latencyMs, 'completed_at' => date('Y-m-d H:i:s')]);
            }
            if ((string) $locked->state !== 'succeeded') throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Skill invocation cannot be recovered');
            $usage = (new UsageMeter())->meter($locked, $deployment, $provider, $model, $attribution, $outcome->inputTokens, $outcome->outputTokens, $outcome->usageMeasured, $pricingSnapshot, $routeSnapshot, $skillAttribution);
            $inserted = Db::connect('pgsql')->query('INSERT INTO sand_ai_usage (invocation_id,input_tokens,output_tokens,total_tokens,cost_amount,currency,source) VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?) ON CONFLICT (invocation_id) DO NOTHING RETURNING input_tokens,output_tokens,total_tokens,cost_amount,currency,source', [$locked->id, $outcome->inputTokens, $outcome->outputTokens, $outcome->inputTokens + $outcome->outputTokens, $usage['metered_amount'] ?? null, $usage['currency'] ?? null, (string) $provider->adapter]);
            $legacy = $inserted[0] ?? Db::connect('pgsql')->query('SELECT input_tokens,output_tokens,total_tokens,cost_amount,currency,source FROM sand_ai_usage WHERE invocation_id=?', [(int) $locked->id])[0] ?? null;
            if (!is_array($legacy)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Legacy usage replay differs from provider outcome');
            }
            $expectedCost = $usage['metered_amount'] ?? null;
            $sameCost = \app\Application\Usage\UsagePricing::matchesLegacyCost($expectedCost, $legacy['cost_amount'] ?? null);
            if ((int) $legacy['input_tokens'] !== $outcome->inputTokens || (int) $legacy['output_tokens'] !== $outcome->outputTokens || (int) $legacy['total_tokens'] !== $outcome->inputTokens + $outcome->outputTokens || !$sameCost || (string) $legacy['currency'] !== (string) ($usage['currency'] ?? '') || (string) $legacy['source'] !== (string) $provider->adapter) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Legacy usage replay differs from provider outcome');
            }
            if ($reservationId !== null) {
                $budgetGuard = new ChatBudgetGuard();
                if ($providerTerminalization->budgetAction === 'settle') {
                    $budgetGuard->settle($reservationId, $usage['metered_amount'] ?? null, ($usage['metered_amount'] ?? null) === null ? 'usage_unmeasured_or_unpriced' : 'usage_metered', $priceRevisionId, $capabilityCode);
                } elseif ($providerTerminalization->releasesBudget()) {
                    $budgetGuard->release($reservationId, 'provider_not_dispatched');
                } else {
                    $budgetGuard->settle($reservationId, null, 'dispatch_ambiguous', null, $capabilityCode);
                }
            }
            if ($dispatchId !== null) (new DispatchCoordinator())->finalizeSuccessForScope($dispatchId, null, $providerTerminalization);
            $audit = SkillFinalizationPolicy::audit($recovered, $attribution->environmentId);
            if (AuditLog::where('action', $audit['action'])->where('resource_type', 'invocation')->where('resource_id', (int) $locked->id)->find() === null) {
                AuditLog::create(['actor_type' => $audit['actor_type'], 'actor_ref' => $audit['actor_ref'], 'action' => $audit['action'], 'resource_type' => 'invocation', 'resource_id' => $locked->id, 'summary' => $recovered ? 'Skill completion recovered from encrypted provider outcome' : 'Skill completion succeeded', 'context' => ['recovered' => $audit['recovered']]]);
            }
            $usage['_invocation'] = $locked;
            return $usage;
        });
    }

    /** @return array<string, mixed> */
    public function invocation(int $environmentId, string $requestId, callable $authorizeRead): array
    {
        $invocation = Invocation::where('environment_id', $environmentId)->where('request_id', $requestId)->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Invocation not found');
        }
        $authorizeRead($invocation);
        return $this->invocationSummary($invocation);
    }

    /** @return array<string, mixed> */
    private function invocationSummary(Invocation $invocation): array
    {
        return ['request_id' => (string) $invocation->request_id, 'state' => (string) $invocation->state, 'latency_ms' => $invocation->latency_ms, 'error_code' => $invocation->error_code];
    }

    /** @return array{model_code:string,deployment_id:int} */
    private function frozenLowCostRoute(Invocation $invocation): array
    {
        $dispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        $route = $dispatch === null ? null : $dispatch->route_snapshot;
        $route = is_object($route) ? (array) $route : $route;
        if (!is_array($route) || $dispatch === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Frozen low-cost route is unavailable');
        }
        return LowCostRouteReplayPolicy::selected(
            $route, (int) $invocation->model_id, (int) $invocation->deployment_id,
            (int) $dispatch->model_id, (int) $dispatch->deployment_id,
        );
    }

    /** Bind the SDK contract to the persisted meeting node, original scope and current worker lease. */
    private function assertMeetingOutput(int $environmentId, string $modelCode, array $messages,
        string $requestId, ?UsageAttribution $attribution, DispatchExecutionScope $scope,
        ?MeetingSummaryOutputContract $supplied): void
    {
        Db::connect('pgsql')->transaction(function () use ($environmentId, $modelCode, $messages, $requestId, $attribution, $scope, $supplied): void {
            $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $environmentId)->lock(true)->find();
            $input = $task === null ? null : (is_object($task->input) ? (array) $task->input : $task->input);
            if (!is_array($input) || !is_array($input['_meeting_summary'] ?? null)) {
                if ($supplied !== null) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting output has no frozen task');
                return;
            }
            $meeting = $input['_meeting_summary'];
            // Absence is the frozen legacy plain protocol, including historical receipt recovery.
            if (!array_key_exists('output_contract', $meeting) && $supplied === null) return;
            $node = MeetingSummaryNode::where('task_id', $scope->taskRunId)
                ->where('task_step_id', $scope->taskStepId)->where('node_code', $scope->logicalNode)->lock(true)->find();
            $step = TaskStep::where('id', $scope->taskStepId)->where('task_id', $scope->taskRunId)->lock(true)->find();
            $nodeInput = $node === null ? null : (is_object($node->input_snapshot) ? (array) $node->input_snapshot : $node->input_snapshot);
            $wireInput = $nodeInput;
            if ($task !== null && $node !== null && is_array($nodeInput)) {
                $correction = \app\Application\Audio\MeetingOutputCorrection::marker($task, $node, $scope->attemptNo);
                if ($correction !== null) $wireInput = \app\Application\Audio\MeetingOutputCorrection::input($nodeInput, $correction);
            }
            $seed = 'ai_task:' . $scope->taskRunId . ':' . $scope->taskStepId . ':' . $scope->logicalNode . ':' . $scope->attemptNo;
            if ((string) $task->task_type !== 'ai_completion' || (string) $task->resource_type !== 'ai_task'
                || (int) $task->resource_id !== $scope->taskRunId || $task->content_purged_time !== null
                || !in_array((string) $task->state, ['running', 'recovery_pending'], true)
                || (string) $task->lease_owner !== $scope->leaseOwner
                || !hash_equals((string) $task->lease_token, $scope->leaseToken) || (int) $task->lease_fence !== $scope->leaseFence
                || !hash_equals(substr(hash('sha256', $seed), 0, 48), $scope->logicalExecutionId)
                || !hash_equals(hash('sha256', 'snapshot:' . $seed), $scope->executionSnapshotSha256)
                || $scope->workflowRunId !== null || $scope->workflowRunFileId !== null
                || $node === null || $step === null || !is_array($nodeInput)
                || !is_array($nodeInput['messages'] ?? null)
                || (string) $step->step_code !== $scope->logicalNode || (int) $step->attempt_count !== $scope->attemptNo
                || !in_array((string) $step->state, ['running', 'failed'], true)
                || !hash_equals((string) $node->input_sha256, RequestPayloadFingerprint::fingerprint($nodeInput, []))
                || (string) $node->transcript_sha256 !== ($meeting['sha256'] ?? null)
                || (int) $node->review_revision !== ($meeting['review_revision'] ?? null)
                || (string) $node->planner_version !== ($meeting['planner_version'] ?? null)
                || ($input['model'] ?? null) !== $modelCode || $attribution === null
                || $requestId !== sprintf('task_%d_%s_attempt_%d', $scope->taskRunId, $scope->logicalNode, $scope->attemptNo)
                || !RequestPayloadFingerprint::same($messages, $wireInput['messages'] ?? [], [])) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting output differs from its task node');
            }
            $frozenIdentity = \app\Application\Task\AiTaskGateway::attributionFromPersistedInput($input, $environmentId);
            if ($attribution->canonicalTrustedIdentity() !== $frozenIdentity->canonicalTrustedIdentity()
                || (int) $task->organization_id !== $attribution->organizationId
                || (int) $task->application_id !== $attribution->applicationId
                || (int) $task->workload_client_id !== $attribution->workloadClientId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting output owner differs from its task');
            }
            $zone = \app\Application\Task\TaskLeasePolicy::runningTimezone();
            if (TaskRun::where('id', $scope->taskRunId)
                ->whereRaw('lease_expire_time > (clock_timestamp() AT TIME ZONE ?)', [$zone])->value('id') === null) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Meeting output worker lease expired');
            }
            $frozen = MeetingSummaryOutputContract::forNode($input, (string) $node->node_kind, $wireInput);
            if (($frozen === null) !== ($supplied === null)
                || ($frozen !== null && !RequestPayloadFingerprint::same($frozen->fingerprint(), $supplied->fingerprint(), []))) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Meeting output contract differs from its frozen node');
            }
        });
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function prepareProviderCall(Provider $provider, ModelDeployment $deployment, array $messages, ChatCompletionOptions $options, ProviderDispatchContext $dispatchContext, bool $streamSegments = false, ?callable $acceptSegment = null, bool $useNeuron = false, ?AgentToolChatRequest $agentToolRequest = null, ?callable $acceptAgentChunk = null): PreparedChatProviderCall
    {
        return match ((string) $provider->adapter) {
            'fake' => $this->prepareFakeProviderCall($messages, $options),
            'openai_compatible' => $this->openAiCompatibleProvider()->prepare(
                (new ProviderSecretDecryptor())->decrypt($this->providerConfig($provider)),
                (string) $deployment->remote_model,
                $messages,
                max(1000, (int) $provider->timeout_ms),
                $options,
                $dispatchContext,
                $streamSegments,
                $acceptSegment,
                $useNeuron,
                $agentToolRequest,
                $acceptAgentChunk,
            ),
            default => throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'No supported provider is available for this deployment'),
        };
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function prepareFakeProviderCall(array $messages, ChatCompletionOptions $options): PreparedChatProviderCall
    {
        FakeProviderPolicy::assertExecutable(
            filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
            filter_var(env('SAND_AI_ALLOW_FAKE_PROVIDER', false), FILTER_VALIDATE_BOOL),
        );
        return new PreparedChatProviderCall(static function (ProviderSendBoundary $boundary) use ($messages, $options): array {
            $boundary->markStarted();
            return (new FakeChatProvider())->complete($messages, $options);
        });
    }

    private function openAiCompatibleProvider(): OpenAiCompatibleChatProvider
    {
        return $this->openAiCompatibleProvider ?? new OpenAiCompatibleChatProvider();
    }

    /** @return array<string, mixed> */
    private function providerConfig(Provider $provider): array
    {
        $config = $provider->encrypted_config;
        $config = is_object($config) ? (array) $config : $config;
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is unavailable');
        }
        return $config;
    }

    /** @return array<string,mixed> */
    private function providerRuntimeConfig(Provider $provider): array
    {
        if ((string) $provider->adapter === 'fake') {
            return [];
        }

        return (new ProviderSecretDecryptor())->decrypt($this->providerConfig($provider));
    }

    private function providerConfigDigest(Provider $provider): string
    {
        return hash('sha256', json_encode($this->providerConfig($provider), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertSkillExecutionSnapshot(SkillAttribution $attribution, AiModel $model, ModelDeployment $deployment, Provider $provider, ChatCompletionOptions $options): void
    {
        $snapshot = $attribution->executionSnapshot;
        // Frozen snapshots must survive superseding republications: the row
        // flips to archived on every republish, while integrity below is
        // enforced by the immutable published ConfigRevision and freeze-hash.
        // Status 0 still means disabled and must stay rejected.
        $profile = \app\Infrastructure\Persistence\Model\CapabilityProfile::where('id', $snapshot['profile_id'])
            ->where('revision', $snapshot['profile_revision'])->whereIn('status', [1, 2])->find();
        $route = \app\Infrastructure\Persistence\Model\CapabilityRoute::where('id', $snapshot['route_id'])
            ->where('profile_id', $snapshot['profile_id'])->where('capability_code', $snapshot['capability_code'])
            ->where('deployment_id', $snapshot['deployment_id'])->where('status', 1)->find();
        $revision = ConfigRevision::where('id', $snapshot['config_revision_id'])->where('resource_type', 'model_deployment')
            ->where('resource_id', (int) $deployment->id)->where('revision', $snapshot['config_revision'])
            ->where('state', 'published')->where('status', 1)->find();
        $profileRevision = ConfigRevision::where('id', $snapshot['profile_config_revision_id'])->where('resource_type', 'capability_profile')
            ->where('resource_id', $snapshot['profile_id'])->where('revision', $snapshot['profile_revision'])
            ->where('state', 'published')->where('status', 1)->find();
        $drifted = $profile === null || $route === null || $revision === null || $profileRevision === null
            || !DispatchRouteSnapshot::matches($snapshot, $profile, $route, $deployment, $model, $provider, $revision, $profileRevision, true)
            || $options->maxOutputTokens !== $snapshot['max_output_tokens'];
        if ($drifted || (in_array($snapshot['data_egress_policy'], ['local_only', 'private_only'], true) && (string) $provider->adapter !== 'fake')) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Frozen Skill execution configuration is unavailable');
        }
    }

    private function providerIdempotencyKey(ProviderIdempotencyCapability $capability, string $logicalExecutionId, string $bodyDigest): ?string
    {
        if ($capability->mode === 'none') {
            return null;
        }
        $secret = env('SAND_AI_DISPATCH_IDEMPOTENCY_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider idempotency requires a configured dispatch secret');
        }

        return hash_hmac('sha256', $logicalExecutionId . ':' . $bodyDigest, $secret);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $items = is_object($value) ? (array) $value : $value;
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter($items, 'is_string'));
    }

    private function assertChatModelCapability(string $capabilityCode): void
    {
        $capability = CapabilityDriverRegistry::capability($capabilityCode);
        if (($capability['route_kind'] ?? null) !== 'model_deployment' || !in_array('chat', $capability['model_types'] ?? [], true)) {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'The capability does not support chat model execution');
        }
    }

    /** Pure original receipt verification; no provider, accounting or state mutation. */
    public function verifyAgentReceipt(Invocation $invocation): void
    {
        if (!is_string($invocation->request_fingerprint)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original Agent receipt fingerprint is unavailable');
        }
        $context = $this->receiptContext($invocation, (int) $invocation->environment_id, 'agent_tool', $invocation->request_fingerprint);
        AgentToolProviderOutcome::decode($this->outcomeReceipts()->replay($context));
    }

    private function outcomeReceipts(): OutcomeReceiptService
    {
        if ($this->outcomeReceipts === null) {
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) {
                throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Outcome receipt encryption is not configured');
            }
            $this->outcomeReceipts = new OutcomeReceiptService(
                new PostgresOutcomeReceiptStore(),
                new OutcomeReceiptCipher($config),
                $config['ttl_seconds'] ?? 0,
            );
        }
        return $this->outcomeReceipts;
    }

    /** @return array<string,mixed> */
    private function replayExisting(Invocation $invocation, int $environmentId, string $receiptCapabilityCode, string $requestFingerprint, bool $receiptEnabled, bool $allowIncompleteReceiptReplay, ?OutcomeReceiptService $outcomeReceipts): array
    {
        $dispatch = \app\Infrastructure\Persistence\Model\InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($dispatch !== null && in_array((string) $dispatch->state, ['prepared', 'dispatching', 'ambiguous'], true)) {
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'A provider dispatch is not replayable until its ledger is resolved');
        }
        $result = ['idempotent_replay' => true, 'invocation' => $this->invocationSummary($invocation), 'invocation_id' => (int) $invocation->id];
        if (!$receiptEnabled || $outcomeReceipts === null
            || (!$allowIncompleteReceiptReplay && !OutcomeReceiptReplayPolicy::shouldReplayContent(true, (string) $invocation->state))) {
            return $result;
        }
        $receipt = $outcomeReceipts->replay($this->receiptContext($invocation, $environmentId, $receiptCapabilityCode, $requestFingerprint));
        if ($receiptCapabilityCode === 'skill') {
            $outcome = SkillProviderOutcome::decode($receipt);
            $result['content'] = $outcome->content;
            $result['provider_outcome'] = $outcome->encode();
            $result['usage'] = [
                'input_tokens' => $outcome->usageMeasured ? $outcome->inputTokens : null,
                'output_tokens' => $outcome->usageMeasured ? $outcome->outputTokens : null,
                'total_tokens' => $outcome->usageMeasured ? $outcome->inputTokens + $outcome->outputTokens : null,
                'usage_measured' => $outcome->usageMeasured,
            ];
            return $result;
        }
        if (in_array($receiptCapabilityCode, ['meeting_summary', 'skill_source_suggestion'], true)) {
            $result['content'] = KnowledgeProviderOutcome::decode($receipt)->content;
            return $result;
        }
        if ($receiptCapabilityCode === 'knowledge_answer') {
            try {
                $result['content'] = KnowledgeProviderOutcome::decode($receipt)->content;
            } catch (ApiProblem $problem) {
                // Earlier successful knowledge invocations stored content only.
                // They remain replayable until expiry; running legacy receipts
                // are never accepted by the recovery path.
                if ($problem->errorCode !== 'SAND_AI_RECEIPT_INVALID') {
                    throw $problem;
                }
                $payload = json_decode($receipt, true);
                if (is_array($payload) && (array_key_exists('version', $payload) || array_key_exists('provider_adapter', $payload))) {
                    throw $problem;
                }
                $result['content'] = $receipt;
            }
        } else {
            $result['content'] = $receiptCapabilityCode === 'structured_extraction'
                ? StructuredProviderOutcome::content($receipt) : $receipt;
        }
        return $result;
    }

    /** @return array{environment_id:int,invocation_id:int,request_fingerprint:string,capability_code:string,model_id:int,deployment_id:int,version:string} */
    private function receiptContext(Invocation $invocation, int $environmentId, string $capabilityCode, string $requestFingerprint): array
    {
        if ((int) $invocation->environment_id !== $environmentId || (int) $invocation->model_id < 1 || (int) $invocation->deployment_id < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Outcome receipt context is invalid');
        }
        return [
            'environment_id' => $environmentId,
            'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => $requestFingerprint,
            'capability_code' => $capabilityCode,
            'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $invocation->deployment_id,
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ];
    }
}
