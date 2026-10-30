<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\OutcomeReceiptRequest;
use app\Infrastructure\Agent\NeuronAgentCaller;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use app\Infrastructure\Provider\AgentToolChatRequest;
use app\Application\Channel\ChannelBindingGateway;
use app\Application\Channel\ChannelConversationGateway;
use app\Application\Channel\ChannelConversationPolicy;
use app\Application\Gateway\DispatchExecutionScope;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Application\Retrieval\RetrievalGateway;
use app\Application\Knowledge\KnowledgeBaseQueryGateway;
use app\Application\Knowledge\KnowledgeEmbeddingIndexGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Knowledge\KnowledgeQueryEmbeddingGateway;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskExecutionLease;
use app\Application\Task\TaskState;
use app\Identity\Infrastructure\AgentRetrievedSourceGuard;
use app\Identity\Infrastructure\AgentKnowledgeAccessGuard;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use app\Infrastructure\Persistence\Model\Invocation;
use think\facade\Db;
use support\Log;
use Throwable;

final class AgentGateway
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function submit(int $environmentId, array $input, string $requestId, ?UsageAttribution $attribution = null, ?\Closure $authorizeTask = null, ?array $definitionBinding = null, ?array $channelBinding = null): array
    {
        if ($attribution === null || $attribution->organizationId === null
            || $attribution->applicationId === null || $attribution->workloadClientId === null
            || $authorizeTask === null) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent authorization facts are unavailable');
        }
        $input = self::preparedInput($input, $attribution);
        if ($definitionBinding === null && $input['knowledge_base_code'] !== null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Knowledge base binding requires a published agent');
        }
        if (array_key_exists('conversation_id', $input) && ($definitionBinding === null || $channelBinding !== null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Generic conversations require a published agent without a channel');
        }
        if ($definitionBinding !== null) $input['_agent_definition'] = $definitionBinding;
        if ($channelBinding !== null) {
            self::assertDefinitionChannelPair($definitionBinding, $channelBinding);
            $input['_channel'] = $channelBinding;
        }
        $prepared = Db::connect('pgsql')->transaction(function () use ($environmentId, $input, $requestId, $attribution): array {
            if (isset($input['_agent_definition'])) (new AgentDefinitionGateway())->assertCurrent($environmentId, $input['_agent_definition']);
            if (isset($input['_channel'])) (new ChannelBindingGateway())->assertCurrent($environmentId, $input['_channel']);
            if (isset($input['_channel'])) (new ChannelConversationGateway())->lock($environmentId, $input['_channel']);
            if (isset($input['_channel'])) (new ChannelConversationGateway())->lockMessageRequest($requestId);
            if (isset($input['_channel'])) (new ChannelConversationGateway())->assertMessageRequestAvailable($requestId);
            if (isset($input['conversation_id'])) {
                $conversation = new AgentConversationGateway();
                $conversation->lock($conversation->scope($attribution, $input['_agent_definition'], $input['conversation_id']));
            }
            $existing = TaskRun::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->environment_id !== $environmentId || (string) $existing->task_type !== 'agent_run') {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another task');
                }
                self::assertReplayInput($existing->input, $input);
                return ['idempotent_replay' => true, 'task_id' => (int) $existing->id];
            }
            if (isset($input['_channel'])) (new ChannelConversationGateway())->assertAvailable($environmentId, $input['_channel']);
            if (isset($input['conversation_id'])) {
                $input['_agent_conversation'] = (new AgentConversationGateway())->freeze(
                    $attribution, $input['_agent_definition'], $input['conversation_id'], $input['context_turn_limit']);
            }
            $readonlySelections = $input['readonly_tools'] ?? [];
            $readonlyBindings = isset($input['_agent_definition'])
                ? ($input['_agent_definition']['readonly_tool_bindings'] ?? [])
                : (new AgentReadonlyToolCatalog())->freeze($environmentId, $readonlySelections);
            if (AgentReadonlyToolSelection::normalize(array_column($readonlyBindings, 'selection')) !== $readonlySelections) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent tool selection differs from its published version');
            }
            if ($readonlyBindings !== []) {
                (new AgentReadonlyToolCatalog())->assertCurrent($environmentId, $readonlyBindings);
                $input['_agent_readonly_tools'] = $readonlyBindings;
                $input['_agent_readonly_protocol'] = AgentReadonlyToolProtocol::LIMITS;
            }
            $input['_resource_data_class'] = null;
            $task = TaskRun::create([
                'environment_id' => $environmentId,
                'organization_id' => $attribution->organizationId,
                'application_id' => $attribution->applicationId,
                'workload_client_id' => $attribution->workloadClientId,
                'data_class' => null,
                'task_type' => 'agent_run',
                'resource_type' => 'agent_run',
                'resource_id' => 0,
                'request_id' => $requestId,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'max_attempts' => max(1, (int) config('sand_ai_task.max_attempts', 3)),
                'available_at' => date('Y-m-d H:i:s'),
                'input' => $input,
                'status' => 1,
            ]);
            $input['_agent_checkpoint_version'] = 1;
            $task->save(['resource_id' => (int) $task->id, 'input' => $input]);
            TaskStep::create([
                'task_id' => (int) $task->id,
                'step_code' => 'agent_run',
                'sequence_no' => 1,
                'state' => TaskState::AUTHORIZATION_PENDING,
                'attempt_count' => 0,
                'context' => ['tool_count' => count($input['tools']), 'review_required' => $input['review_required']],
                'status' => 1,
            ]);
            $this->audit($environmentId, 'agent.authorization_pending', (int) $task->id, 'SandAI agent task awaits authorization', [
                'tools' => $input['tools'], 'review_required' => $input['review_required'],
            ]);
            return ['idempotent_replay' => false, 'task_id' => (int) $task->id];
        });
        try {
            $authorization = $authorizeTask($prepared['task_id']);
            if (!is_array($authorization)) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Agent authorization is unavailable');
            }
        } catch (Throwable $failure) {
            if (!$prepared['idempotent_replay']) {
                try {
                    $this->rejectPendingAuthorization($prepared['task_id'], $failure instanceof ApiProblem
                        ? $failure->errorCode : 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
                } catch (Throwable $cleanupFailure) {
                    Log::warning('SandAI agent authorization cleanup failed', [
                        'task_id' => $prepared['task_id'],
                        'exception_type' => $cleanupFailure::class,
                    ]);
                }
            }
            throw $failure;
        }
        return $this->activateAuthorizedTask(
            $prepared['task_id'], $environmentId, $authorization, $prepared['idempotent_replay'],
        );
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function activateAuthorizedTask(int $taskId, int $environmentId, array $authorization, bool $replay): array
    {
        return Db::connect('pgsql')->transaction(function () use ($taskId, $environmentId, $authorization, $replay): array {
            $task = TaskRun::where('id', $taskId)->where('environment_id', $environmentId)
                ->where('task_type', 'agent_run')->lock(true)->find();
            if ($task === null || (string) $task->resource_type !== 'agent_run'
                || (int) $task->resource_id !== $taskId) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized agent task changed before enqueue');
            }
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            if (is_array($input) && isset($input['_agent_definition'])) (new AgentDefinitionGateway())->assertCurrent($environmentId, $input['_agent_definition']);
            if (is_array($input) && isset($input['_channel'])) (new ChannelBindingGateway())->assertCurrent($environmentId, $input['_channel']);
            if (!is_array($input) || !array_key_exists('_resource_data_class', $input)
                || $input['_resource_data_class'] !== null || $task->data_class !== null) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized agent task facts changed');
            }
            $state = (string) $task->state;
            $pending = $state === TaskState::AUTHORIZATION_PENDING
                || ($state === TaskState::FAILED && (int) $task->attempt_count === 0
                    && !isset($input['iam_authorization']));
            if (in_array($state, [TaskState::QUEUED, TaskState::RETRYING], true)) {
                $originalAuthorization = $input['iam_authorization'] ?? null;
                if (!is_array($originalAuthorization)) {
                    throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued agent authorization is unavailable');
                }
                // The queued execution still belongs to its original context, even when a newer caller replays it.
                (new TaskRunAccessGuard())->revalidateQueued($originalAuthorization, $task);
            }
            if ($pending) {
                $saved = $task->save([
                    'input' => [...$input, 'iam_authorization' => $authorization],
                    ...($pending ? [
                        'state' => TaskState::QUEUED,
                        'available_at' => date('Y-m-d H:i:s'),
                        'completed_at' => null,
                        'error_code' => null,
                        'error_summary' => null,
                    ] : []),
                ]);
                if ($saved !== true) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized agent task changed before enqueue');
                }
                if ($pending) {
                    $updated = TaskStep::where('task_id', $taskId)
                        ->whereIn('state', [TaskState::AUTHORIZATION_PENDING, TaskState::FAILED])
                        ->update([
                            'state' => TaskState::QUEUED, 'completed_at' => null,
                            'error_code' => null, 'error_summary' => null,
                        ]);
                    if ($updated !== 1) {
                        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized agent step changed');
                    }
                    $this->audit($environmentId, 'agent.queued', $taskId, 'Queued SandAI agent task', []);
                }
            }
            $summary = (new TaskGateway())->summary($task);
            // Validate the caller after constructing the result; pending writes roll back on failure.
            (new TaskRunAccessGuard())->revalidateQueued($authorization, $task);
            return ['idempotent_replay' => $replay, 'task' => $summary];
        });
    }

    private function rejectPendingAuthorization(int $taskId, string $errorCode): void
    {
        Db::connect('pgsql')->transaction(function () use ($taskId, $errorCode): void {
            $task = TaskRun::where('id', $taskId)->where('state', TaskState::AUTHORIZATION_PENDING)->lock(true)->find();
            if ($task === null) return;
            $now = date('Y-m-d H:i:s');
            $task->save([
                'state' => TaskState::FAILED, 'completed_at' => $now,
                'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused',
            ]);
            $updated = TaskStep::where('task_id', $taskId)->where('state', TaskState::AUTHORIZATION_PENDING)
                ->update([
                    'state' => TaskState::FAILED, 'completed_at' => $now,
                    'error_code' => $errorCode, 'error_summary' => 'SandIAM invocation authorization was refused',
                ]);
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent authorization state changed');
            }
            $this->audit((int) $task->environment_id, 'agent.authorization_denied', $taskId,
                'SandAI agent task authorization was refused', ['error_code' => $errorCode]);
        });
    }

    /** @return array<string, mixed> */
    public function executeTask(TaskRun $task, TaskExecutionLease $lease): array
    {
        $persistedInput = $task->input;
        $persistedInput = is_object($persistedInput) ? (array) $persistedInput : $persistedInput;
        if (!is_array($persistedInput)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The agent task payload is invalid');
        }
        $receiptOnly = \app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task);
        $attribution = self::attributionFromPersistedInput($persistedInput, (int) $task->environment_id);
        self::assertPersistedTrustedIdentityScope($persistedInput, $attribution);
        $binding = $persistedInput['_agent_definition'] ?? null;
        $channel = $persistedInput['_channel'] ?? null;
        if ($channel !== null) self::assertDefinitionChannelPair($binding, $channel);
        if ($channel !== null) (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $channel);
        if ($binding !== null) (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $binding);
        $input = AgentPolicy::normalizeInput(self::businessInput($persistedInput));
        $readonlyBindings = $persistedInput['_agent_readonly_tools'] ?? [];
        if (!is_array($readonlyBindings) || !array_is_list($readonlyBindings)
            || AgentReadonlyToolSelection::normalize(array_column($readonlyBindings, 'selection')) !== ($input['readonly_tools'] ?? [])) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Queued agent readonly tool bindings are invalid');
        }
        (new AgentReadonlyToolCatalog())->assertCurrent((int) $task->environment_id, $readonlyBindings);
        if ($readonlyBindings !== [] && !\app\Application\Agent\AgentReadonlyToolProtocol::valid($persistedInput['_agent_readonly_protocol'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent readonly execution limits changed');
        }

        $authorization = AgentContinuationGateway::executionAuthorization($task);
        AgentContinuationGateway::revalidateAdmission($task);
        if (!is_array($authorization)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued agent authorization is unavailable');
        }
        $accessGuard = new TaskRunAccessGuard();
        $queued = $accessGuard->revalidateQueued($authorization, $task);
        if (!in_array('retrieval.search', $input['tools'], true)) {
            throw new ApiProblem('SAND_AI_AGENT_TOOL_FORBIDDEN', 'The agent requires the retrieval.search tool');
        }
        $knowledgeCode = $binding !== null ? $input['knowledge_base_code'] : null;
        if ($binding !== null && $knowledgeCode === null) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Published agent has no knowledge base');
        }
        $publication = $knowledgeCode === null ? null
            : self::currentKnowledgePublication((int) $task->environment_id, $knowledgeCode);
        $knowledgeGuard = new AgentKnowledgeAccessGuard();
        $knowledgeDecision = $publication === null ? null : $knowledgeGuard->authorizeQueued($publication, $queued);
        $retrieveSources = function (string $query) use (
            $task, $lease, $binding, $channel, $publication, $knowledgeCode,
            $accessGuard, $authorization, $knowledgeGuard, $knowledgeDecision, $queued, $attribution, $input, $receiptOnly,
        ): array {
            if ($receiptOnly) throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Channel receipt recovery cannot start retrieval');
            if (trim($query) === '' || mb_strlen($query, 'UTF-8') > 500) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent retrieval query is invalid');
            }
            self::assertRunningLease($task, $lease);
        $embedding = null;
        if ($publication === null) {
            $retrieval = (new RetrievalGateway())->search(
                (int) $task->environment_id, ['query' => $query, 'limit' => 8],
                RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            );
            $retrieval['citations'] = $retrieval['matches'];
        } else {
            $queryGateway = new KnowledgeBaseQueryGateway();
            $queryInput = ['query' => $query, 'limit' => 8];
            $queryHash = hash('sha256', $query);
            $childKey = self::embeddingChildKey((int) $task->id)
                . ($query === $input['query'] ? '' : ':' . substr($queryHash, 0, 32));
            $childRequestId = KnowledgeQueryEmbeddingGateway::childRequestId(
                (int) $task->environment_id, (int) $publication['id'], $childKey,
            );
            $hasChild = Invocation::where('request_id', $childRequestId)->find() !== null;
            if ($hasChild || (new KnowledgeEmbeddingIndexGateway())->hasActiveIndex($publication)) {
                $embeddingAttribution = UsageAttribution::fromIdentity(
                    $queued['identity'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding',
                );
                if ($embeddingAttribution->canonicalTrustedIdentity() !== $attribution->canonicalTrustedIdentity()) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent knowledge identity changed');
                }
                $retrievalStep = $this->embeddingRetrievalStep($task, $queryHash, $childRequestId);
                $embedding = (new KnowledgeQueryEmbeddingGateway())->embed(
                    $publication, $query, $childKey, $embeddingAttribution,
                    function (int $invocationId) use (
                        $task, $lease, $binding, $channel, $publication, $knowledgeCode,
                        $accessGuard, $authorization, $knowledgeGuard, $knowledgeDecision,
                    ): void {
                        self::assertRunningLease($task, $lease);
                        if ($binding !== null) (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $binding);
                        if ($channel !== null) (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $channel);
                        self::assertKnowledgePublication((int) $task->environment_id, $knowledgeCode, $publication);
                        $current = $accessGuard->revalidateQueued($authorization, $task);
                        $knowledgeGuard->revalidateQueued($publication, $current, $knowledgeDecision);
                    },
                    $lease,
                );
                $retrievalStep->save(['state' => TaskState::SUCCEEDED, 'completed_at' => date('Y-m-d H:i:s')]);
                $retrieval = $queryGateway->queryWithVector(
                    $publication, $queryInput, $embedding['vector'], $embedding['index_id'],
                );
            } else {
                $retrieval = $queryGateway->query($publication, $queryInput);
            }

        }
            return $retrieval;
        };
        $retrievalCache = [];
        $sources = [];
        $sourceGuard = new AgentRetrievedSourceGuard();
        $sourceGroups = array_chunk($sources, 8);
        $sourceGrants = array_map(static fn (array $group): array => $sourceGuard->authorize($group, $task, $attribution, $queued), $sourceGroups);
        $history = ['messages' => [], 'source_groups' => [], 'source_references' => []];
        $historyGrants = [];
        if ($channel !== null) {
            $history = (new ChannelConversationGateway())->history(
                $task, $channel, $binding, $attribution, self::trustedIdentityScope($attribution),
                $input['context_turn_limit'],
            );
        }
        if (isset($persistedInput['_agent_conversation'])) {
            $history = (new AgentConversationGateway())->history($task, $attribution, $persistedInput, $input['context_turn_limit']);
        }
        $readonlyHistoryIds = array_values(array_unique([...(is_array($history['covered_task_ids'] ?? null) ? $history['covered_task_ids'] : []), ...array_column($history['messages'], 'task_id')]));
        $historyToolRevalidate = (new AgentReadonlyToolReadGuard())->authorizeQueuedHistory($queued, (int) $task->id, $readonlyHistoryIds);
        $historyToolRevalidate();
        // Freeze the complete source closure before dispatch, including inherited answers.
        $references = ChannelConversationPolicy::sourceReferences([...$history['source_references'], ...$sources]);
        if ($history['source_groups'] !== []) {
            foreach ($history['source_groups'] as $historicalSources) {
                $historyGrants[] = $historicalSources === [] ? []
                    : $sourceGuard->authorize($historicalSources, $task, $attribution, $queued);
            }
        }
        (new AgentFileUsageGateway())->record($task, $attribution, $references);
        $checkpointToolReads = [];
        $revalidate = function () use (
            $accessGuard, $authorization, $task, $lease, $sourceGuard, &$sources,
            $attribution, &$sourceGroups, &$sourceGrants, $history, $historyGrants, $binding,
            $channel, $publication, $knowledgeCode, $knowledgeGuard, $knowledgeDecision, $historyToolRevalidate, &$checkpointToolReads, $queued,
        ): void {
            self::assertRunningLease($task, $lease);
            AgentContinuationGateway::revalidateAdmission($task);
            $historyToolRevalidate();
            foreach ($checkpointToolReads as $toolRead) {
                (new \app\Application\Tool\ToolRunGateway())->readQueuedContinuation($lease, $toolRead['step_id'],
                    $toolRead['binding'], $toolRead['input'], $queued);
            }
            if ($channel !== null) (new ChannelBindingGateway())->assertCurrent((int) $task->environment_id, $channel);
            if ($binding !== null) (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $binding);
            if ($publication !== null) self::assertKnowledgePublication((int) $task->environment_id, $knowledgeCode, $publication);
            $current = $accessGuard->revalidateQueued($authorization, $task);
            if ($publication !== null) $knowledgeGuard->revalidateQueued($publication, $current, $knowledgeDecision);
            foreach ($sourceGroups as $index => $group) {
                foreach ($group as $source) self::assertCheckpointSource($source);
                $sourceGuard->revalidate($group, $task, $attribution, $current, $sourceGrants[$index]);
            }
            foreach ($history['source_groups'] as $index => $historicalSources) {
                if ($historicalSources !== []) $sourceGuard->revalidate($historicalSources, $task, $attribution, $current, $historyGrants[$index]);
            }
        };
        $retrieve = function (string $query) use (
            $retrieveSources, &$retrievalCache, &$sources, &$references, &$sourceGroups, &$sourceGrants,
            $sourceGuard, $accessGuard, $authorization, $task, $attribution, $history, $revalidate,
        ): array {
            $revalidate();
            $found = $retrievalCache[$query] ?? $retrieveSources($query);
            $retrievalCache[$query] = $found;
            $union = [];
            foreach ([...$sources, ...$found['citations']] as $source) {
                $union[(int) $source['source_block_id']] = $source;
            }
            $sources = array_values($union);
            $current = $accessGuard->revalidateQueued($authorization, $task);
            $sourceGroups = array_chunk($sources, 8);
            $sourceGrants = array_map(static fn (array $group): array => $sourceGuard->authorize($group, $task, $attribution, $current), $sourceGroups);
            $references = ChannelConversationPolicy::sourceReferences([...$history['source_references'], ...$sources]);
            (new AgentFileUsageGateway())->record($task, $attribution, $references);
            return ['query' => $query, 'citations' => $found['citations']];
        };
        $dispatchRound = function (int $round, array $messages, AgentToolChatRequest $toolRequest, ?\Closure $acceptChunk = null) use (
            $task, $lease, $input, $attribution, $revalidate, &$references, $receiptOnly,
        ): array {
            if ($round < 1 || $round > (empty($input['readonly_tools']) ? 3 : 4)) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Agent model round limit exceeded');
            $revalidate();
            $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'agent_round_' . $round)->find();
            if ($receiptOnly && $step === null) throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Channel receipt recovery has no original model round');
            $now = date('Y-m-d H:i:s');
            if ($step === null) {
                $step = TaskStep::create([
                    'task_id' => (int) $task->id, 'step_code' => 'agent_round_' . $round,
                    'sequence_no' => $round === 4 ? 6 : $round + 1, 'state' => TaskState::RUNNING,
                    'attempt_count' => max(1, (int) $task->attempt_count), 'started_at' => $now,
                    'context' => ['framework' => 'neuron', 'round' => $round], 'status' => 1,
                ]);
            } else {
                $step->save(['state' => TaskState::RUNNING, 'started_at' => $now, 'completed_at' => null,
                    ...($receiptOnly ? [] : ['attempt_count' => max(1, (int) $task->attempt_count)]),
                    'error_code' => null, 'error_summary' => null]);
            }
            $textMask = new AgentStreamTextMask(AgentStreamNativeTrace::forRound($task, $lease, $round));
            $nativeChunkNo = 0;
            $modelIdentity = $receiptOnly ? self::modelRequestIdentity($task, 'agent_round_' . $round)
                : self::persistModelRequestIdentity($task, $step, 'agent_round_' . $round);
            $roundRequest = $modelIdentity['request_id'];
            $response = (new ChatGateway())->complete(
                (int) $task->environment_id, $input['model'], $messages,
                $roundRequest,
                $attribution, $input['max_output_tokens'],
                outcomeReceiptRequest: new OutcomeReceiptRequest(262144),
                executionScope: DispatchExecutionScope::task('agent', (int) $task->id, (int) $step->id,
                    'agent_round_' . $round, $modelIdentity['attempt_no'], $lease),
                authorizePublicInvocation: static function (int $invocationId) use ($revalidate): void { $revalidate(); },
                agentToolRequest: $toolRequest,
                receiptOnly: $receiptOnly,
                acceptAgentChunk: $acceptChunk === null ? null : function (StreamChunk $chunk, int $invocationId, int $dispatchId) use (
                    $task, $lease, $round, $revalidate, &$references, $acceptChunk, $textMask, &$nativeChunkNo,
                ): void {
                    $revalidate();
                    $nativeChunkNo++;
                    if ($chunk instanceof TextChunk && $chunk->content !== '') {
                        $safeText = $textMask->push($chunk->content, $nativeChunkNo);
                        while ($safeText !== '') {
                            $part = mb_strcut($safeText, 0, 8192, 'UTF-8');
                            (new AgentStreamEventGateway())->append($task, $lease, $round, $invocationId, $dispatchId,
                                $part, $references);
                            $safeText = substr($safeText, strlen($part));
                        }
                    }
                    $revalidate();
                    $acceptChunk($chunk);
                },
            );
            $revalidate();
            if ($acceptChunk !== null && ($response['response_kind'] ?? null) === 'text'
                && is_string($response['content'] ?? null) && is_int($response['invocation_id'] ?? null)) {
                (new AgentStreamEventGateway())->finishRound($task, $lease, $round,
                    $response['invocation_id'], $response['content'], $references);
                $revalidate();
            }
            $step->save(['state' => TaskState::SUCCEEDED, 'completed_at' => date('Y-m-d H:i:s'),
                'context' => ['framework' => 'neuron', 'round' => $round,
                    'invocation' => $response['invocation'] ?? null, 'response_kind' => $response['response_kind'] ?? null,
                    'model_request_identity' => $modelIdentity]]);
            return $response;
        };
        $dispatchSummary = function (array $messages, AgentToolChatRequest $summaryRequest, array $coverage) use (
            $task, $lease, $input, $attribution, $revalidate, $receiptOnly,
        ): array {
            $revalidate();
            $coverageHash = $coverage['coverage_sha256'] ?? null;
            if (!is_string($coverageHash) || preg_match('/\A[a-f0-9]{64}\z/D', $coverageHash) !== 1) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary coverage is invalid');
            }
            $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'agent_summary_1')->find();
            if ($receiptOnly && $step === null) throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Channel receipt recovery has no original summary');
            $now = date('Y-m-d H:i:s');
            $context = ['framework' => 'neuron', 'purpose' => 'history_summary', 'coverage' => $coverage];
            if ($step === null) {
                $step = TaskStep::create(['task_id' => (int) $task->id, 'step_code' => 'agent_summary_1',
                    'sequence_no' => 5, 'state' => TaskState::RUNNING,
                    'attempt_count' => max(1, (int) $task->attempt_count), 'started_at' => $now,
                    'context' => $context, 'status' => 1]);
            } else {
                $prior = is_object($step->context) ? (array) $step->context : $step->context;
                if (!is_array($prior) || ($prior['coverage']['coverage_sha256'] ?? null) !== $coverageHash) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary coverage changed during recovery');
                }
                $step->save(['state' => TaskState::RUNNING, 'started_at' => $now, 'completed_at' => null,
                    ...($receiptOnly ? [] : ['attempt_count' => max(1, (int) $task->attempt_count)]),
                    'error_code' => null, 'error_summary' => null]);
            }
            $modelIdentity = $receiptOnly ? self::modelRequestIdentity($task, 'agent_summary_1', $coverageHash)
                : self::persistModelRequestIdentity($task, $step, 'agent_summary_1', $coverageHash);
            $context['model_request_identity'] = $modelIdentity + ['coverage_sha256' => $coverageHash];
            $response = (new ChatGateway())->complete((int) $task->environment_id, $input['model'], $messages,
                $modelIdentity['request_id'],
                $attribution, $input['max_output_tokens'],
                outcomeReceiptRequest: new OutcomeReceiptRequest(262144),
                executionScope: DispatchExecutionScope::task('agent', (int) $task->id, (int) $step->id,
                    'agent_summary_1', $modelIdentity['attempt_no'], $lease),
                authorizePublicInvocation: static function (int $invocationId) use ($revalidate): void { $revalidate(); },
                agentToolRequest: $summaryRequest, receiptOnly: $receiptOnly);
            $revalidate();
            $step->save(['state' => TaskState::SUCCEEDED, 'completed_at' => date('Y-m-d H:i:s'),
                'context' => $context + ['invocation' => $response['invocation'] ?? null]]);
            return $response;
        };
        $threadId = hash('sha256', json_encode([
            self::trustedIdentityScope($attribution), $binding,
            $channel === null ? ['task_id' => (int) $task->id, 'conversation_scope' => $persistedInput['_agent_conversation']['scope_sha256'] ?? null] : array_intersect_key($channel, array_flip([
                'channel_binding_id', 'revision', 'conversation_scope', 'delivery_platform',
            ])),
        ], JSON_THROW_ON_ERROR));
        $readonlySdkTools = [];
        foreach ($readonlyBindings as $toolIndex => $toolBinding) {
            $readonlySdkTools[] = new \app\Infrastructure\Agent\NeuronAuthorizedReadonlyTool($toolBinding,
                function (array|\stdClass $toolInput) use ($task, $lease, $toolBinding, $toolIndex, $revalidate, $queued, $receiptOnly): array {
                    $revalidate();
                    if ($receiptOnly) throw new ApiProblem('SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'Channel receipt recovery cannot execute another tool');
                    $stepCode = 'agent_managed_tool_' . ($toolIndex + 1);
                    $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', $stepCode)->find();
                    $inputHash = \app\Application\Tool\ToolFingerprint::for('agent_readonly_input', ['input' => $toolInput]);
                    if ($step === null) {
                        $step = TaskStep::create([
                            'task_id' => (int) $task->id, 'step_code' => $stepCode, 'sequence_no' => 20 + $toolIndex,
                            'state' => TaskState::RUNNING, 'attempt_count' => 1, 'started_at' => date('Y-m-d H:i:s'),
                            'context' => ['readonly_binding_sha256' => $toolBinding['snapshot_sha256'], 'input_sha256' => $inputHash], 'status' => 1,
                        ]);
                        $childKey = 'agent-tool:' . $task->id . ':' . $step->id . ':' . substr($inputHash, 0, 32);
                        $step->save(['context' => $step->context + ['child_request_id' => $childKey]]);
                    } else {
                        if (($step->context['input_sha256'] ?? null) !== $inputHash
                            || ($step->context['readonly_binding_sha256'] ?? null) !== $toolBinding['snapshot_sha256']) {
                            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Agent readonly tool input changed after its first execution');
                        }
                        if (isset($task->input['_agent_continuation'])) $step = AgentReadonlyToolContinuationNode::latest($task, $step);
                        $existingRun = \app\Infrastructure\Persistence\Model\ToolRun::where('parent_task_run_id', (int) $task->id)
                            ->where('parent_task_step_id', (int) $step->id)->find();
                        if (isset($task->input['_agent_continuation']) && $existingRun !== null && (string) $existingRun->state === 'succeeded') {
                            $result = (new \app\Application\Tool\ToolRunGateway())->readQueuedContinuation($lease, (int) $step->id, $toolBinding, $toolInput, $queued);
                            $revalidate();
                            return $result;
                        }
                        if ($existingRun !== null || !in_array((string) $step->state, [TaskState::QUEUED, TaskState::RUNNING], true)) {
                            throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Agent tool successor is not ready for dispatch');
                        }
                        $step->save(['state' => TaskState::RUNNING, 'attempt_count' => 1, 'started_at' => date('Y-m-d H:i:s')]);
                    }
                    try {
                        $result = (new \app\Application\Tool\ToolRunGateway())->runQueued($lease, (int) $step->id, $toolBinding, $toolInput);
                        $revalidate();
                        $step->save(['state' => TaskState::SUCCEEDED, 'completed_at' => date('Y-m-d H:i:s'),
                            'context' => $step->context + ['tool_run_id' => $result['run_id']]]);
                        return $result;
                    } catch (ApiProblem $problem) {
                        $unresolved = in_array($problem->errorCode, ['SAND_AI_TOOL_FINALIZATION_PENDING', 'SAND_AI_TOOL_RECEIPT_UNAVAILABLE', 'SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'SAND_AI_TOOL_RUN_IN_PROGRESS'], true);
                        $step->save(['state' => $unresolved ? TaskState::RECOVERY_PENDING : TaskState::FAILED,
                            'error_code' => $problem->errorCode]);
                        throw $problem;
                    }
                });
        }
        $checkpoint = null;
        if (($persistedInput['_agent_checkpoint_version'] ?? null) === 1) {
            $checkpoint = new \app\Infrastructure\Agent\NeuronAgentCheckpoint(
                (new AgentContinuationGateway())->checkpointStore($task), $threadId, self::checkpointRunId($task),
                $receiptOnly || isset($persistedInput['_agent_continuation']),
                function (array $runtime) use ($task, $lease, $queued, $attribution, $sourceGuard, &$sources,
                    &$sourceGroups, &$sourceGrants, &$references, $history, $revalidate, $readonlyBindings, &$checkpointToolReads): void {
                    AgentContinuationGateway::revalidateAdmission($task);
                    $restored = [];
                    foreach (($runtime['retrieval'] ?? []) as $retrieval) {
                        if (!is_array($retrieval) || !is_array($retrieval['output']['citations'] ?? null)) {
                            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent checkpoint sources are unavailable');
                        }
                        foreach ($retrieval['output']['citations'] as $source) {
                            self::assertCheckpointSource($source);
                            $restored[$source['source_block_id']] = $source;
                        }
                    }
                    $sources = array_values($restored);
                    $sourceGroups = array_chunk($sources, 8);
                    $sourceGrants = array_map(static fn (array $group): array => $sourceGuard->authorize($group, $task, $attribution, $queued), $sourceGroups);
                    $references = ChannelConversationPolicy::sourceReferences([...$history['source_references'], ...$sources]);
                    foreach (($runtime['readonly'] ?? []) as $execution) {
                        $bound = null;
                        foreach ($readonlyBindings as $binding) {
                            if (\app\Infrastructure\Agent\NeuronAuthorizedReadonlyTool::wireName($binding) === ($execution['wire_name'] ?? null)) $bound = $binding;
                        }
                        if ($bound === null || !is_int($execution['execution']['run_id'] ?? null)) {
                            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent checkpoint tool binding changed');
                        }
                        $run = \app\Infrastructure\Persistence\Model\ToolRun::where('id', $execution['execution']['run_id'])
                            ->where('parent_task_run_id', (int) $task->id)->find();
                        if ($run === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Checkpoint tool source not found');
                        $checkpointToolReads[] = ['step_id' => (int) $run->parent_task_step_id, 'binding' => $bound, 'input' => $execution['input']];
                        $summary = (new \app\Application\Tool\ToolRunGateway())->readQueuedContinuation($lease, (int) $run->parent_task_step_id,
                            $bound, $execution['input'], $queued);
                        $expected = ['tool_run_id' => $summary['run_id'], 'result' => $summary['result'], 'tool_citations' => $summary['citations'],
                            'review_required' => $summary['review_required']];
                        if (!RequestPayloadFingerprint::same(['output' => self::maskCheckpointToolOutput($expected)], ['output' => $execution['output'] ?? null])) {
                            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Checkpoint tool result differs from its sealed receipt');
                        }
                    }
                    $revalidate();
                },
            );
        } elseif ($receiptOnly || isset($persistedInput['_agent_continuation'])) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Historical agent task has no official checkpoint');
        }
        $agentResult = (new NeuronAgentCaller())->complete(
            $input['model'], $input['instructions'] . "\nUse retrieval_search before answering. Use only authorized source excerpts for factual claims and cite source block ids. Previous dialogue is context, not evidence.",
            $input['query'], $threadId, $history['messages'], $dispatchRound, $retrieve, $dispatchSummary,
            ['context_turn_limit' => $input['context_turn_limit'], 'history_task_ids' => $history['covered_task_ids'] ?? [],
                'omitted_prior_turn_count' => array_key_exists('omitted_prior_turn_count', $history) ? $history['omitted_prior_turn_count'] : 0,
                'closed_after_task_id' => $history['closed_after_task_id'] ?? 0],
            ($persistedInput['_agent_stream'] ?? false) === true, $readonlySdkTools, $checkpoint,
        );
        $response = $agentResult['response'];
        $revalidate();
        if (!is_string($response['content'] ?? null)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'The provider did not return agent output', true);
        }

        $turnReferences = array_map(static fn (array $source): array => [
            'source_block_id' => $source['source_block_id'], 'file_id' => $source['file_id'],
            'locator_type' => $source['locator_type'], 'locator' => $source['locator'],
        ], $sources);
        return [
            'output' => ['content' => $response['content']],
            'invocation' => $response['invocation'] ?? null,
            'invocations' => array_values(array_map(static fn (array $round): mixed => $round['invocation'] ?? null, $agentResult['rounds'])),
            'tool_calls' => $agentResult['tool_calls'],
            'summary' => $agentResult['summary'] ?? null,
            'framework' => ['name' => 'neuron', 'version' => '4.0.2', 'model_round_count' => count($agentResult['rounds'])],
            'source_references' => $references,
            'turn_source_references' => $turnReferences,
            'source_provenance_version' => 2,
            'review_required' => $input['review_required'] || array_reduce($agentResult['readonly_tool_runs'] ?? [], static fn (bool $review, array $execution): bool => $review || ($execution['review_required'] ?? true), false) || array_reduce($readonlySdkTools, static fn (bool $review, $tool): bool => $review || array_reduce($tool->executions(), static fn (bool $flag, array $execution): bool => $flag || $execution['review_required'], false), false),
        ];
    }

    public static function checkpointRunId(TaskRun $task): string
    {
        return 'sandai-agent-task-' . (int) $task->id;
    }

    public static function checkpointWorkflowId(TaskRun $task): string
    {
        $input = $task->input;
        if (!is_array($input)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent checkpoint ownership is unavailable');
        $attribution = self::attributionFromPersistedInput($input, (int) $task->environment_id);
        $channel = $input['_channel'] ?? null;
        return hash('sha256', json_encode([
            self::trustedIdentityScope($attribution), $input['_agent_definition'] ?? null,
            $channel === null ? ['task_id' => (int) $task->id, 'conversation_scope' => $input['_agent_conversation']['scope_sha256'] ?? null]
                : array_intersect_key($channel, array_flip(['channel_binding_id', 'revision', 'conversation_scope', 'delivery_platform'])),
        ], JSON_THROW_ON_ERROR));
    }

    /** Logical model attempts are independent of worker claims and lease recovery. */
    public static function modelRequestIdentity(TaskRun $task, string $logicalNode, ?string $coverageHash = null,
        ?int $persistedAttempt = null): array
    {
        if ($persistedAttempt !== null) return self::canonicalModelIdentity($task, $logicalNode, $coverageHash, $persistedAttempt);
        $input = $task->input;
        if (\app\Application\Channel\ChannelReceiptRecoveryGateway::receiptOnly($task)) {
            $entry = $input['_channel_receipt_recovery']['model_requests'][$logicalNode] ?? null;
            if (!is_array($entry) || !is_int($entry['basis_invocation_id'] ?? null)) self::invalidModelIdentity();
            $identity = self::frozenModelIdentity($task, $logicalNode, $coverageHash, $entry);
            $rows = self::modelInvocationRows($task, $logicalNode, $entry['basis_invocation_id']);
            if (count($rows) !== 1 || $rows[0]['request_id'] !== $identity['request_id']
                || (int) $rows[0]['attempt_no'] !== $identity['attempt_no']) self::invalidModelIdentity();
            return $identity;
        }
        $admission = is_array($input) ? ($input['_agent_continuation'] ?? null) : null;
        if (is_array($admission) && array_key_exists('model_request_version', $admission)) {
            if ($admission['model_request_version'] !== 1 || !is_array($admission['model_requests'] ?? null)) self::invalidModelIdentity();
            $entry = $admission['model_requests'][$logicalNode] ?? null;
            if ($entry !== null) {
                $identity = self::frozenModelIdentity($task, $logicalNode, $coverageHash, $entry);
                $basisId = $entry['basis_invocation_id'] ?? null;
                if ($basisId !== null) {
                    $basis = self::modelInvocationRows($task, $logicalNode, (int) $basisId);
                    if (count($basis) !== 1) self::invalidModelIdentity();
                    self::assertModelDispatchIdentity($task, $basis[0]);
                    $previous = $basis[0];
                    $reused = $identity['attempt_no'] === (int) $previous['attempt_no']
                        && $previous['state'] === 'succeeded' && $previous['dispatch_state'] === 'succeeded';
                    $next = $identity['attempt_no'] === (int) $previous['attempt_no'] + 1
                        && $previous['state'] === 'failed' && in_array($previous['dispatch_state'], ['failed', 'not_dispatched'], true);
                    if (!$reused && !$next) self::invalidModelIdentity();
                } elseif ($identity['attempt_no'] !== 1) self::invalidModelIdentity();
                return $identity;
            }
            // A summary first introduced after admission has no previous model call.
            if ($logicalNode !== 'agent_summary_1') self::invalidModelIdentity();
        }
        $step = TaskStep::where('task_id', (int) $task->id)->where('step_code', $logicalNode)->find();
        $context = $step === null ? null : $step->context;
        if (is_object($context)) $context = (array) $context;
        if (is_array($context) && isset($context['model_request_identity'])) {
            return self::frozenModelIdentity($task, $logicalNode, $coverageHash, $context['model_request_identity']);
        }
        // Legacy recovery can adopt an existing actual request, but cannot allocate
        // a new model attempt from a changed worker counter or missing journal.
        $actual = self::modelInvocationRows($task, $logicalNode);
        if ($actual !== []) {
            $latest = $actual[array_key_last($actual)];
            self::assertModelDispatchIdentity($task, $latest);
            return ['request_id' => $latest['request_id'], 'attempt_no' => (int) $latest['attempt_no']];
        }
        return self::canonicalModelIdentity($task, $logicalNode, $coverageHash, 1);
    }

    /** Called only inside the explicit continuation transaction while its failed parent is locked. */
    public static function continuationModelRequests(TaskRun $task): array
    {
        if ((string) $task->state !== TaskState::FAILED) self::invalidModelIdentity();
        $maximumRound = empty($task->input['_agent_readonly_tools']) ? 3 : 4;
        $nodes = array_map(static fn (int $round): string => 'agent_round_' . $round, range(1, $maximumRound));
        $summary = TaskStep::where('task_id', (int) $task->id)->where('step_code', 'agent_summary_1')->find();
        if ($summary !== null) $nodes[] = 'agent_summary_1';
        $result = [];
        foreach ($nodes as $node) {
            $context = $node === 'agent_summary_1' ? $summary->context : null;
            if (is_object($context)) $context = (array) $context;
            $coverageHash = $node === 'agent_summary_1' && is_array($context) ? ($context['coverage']['coverage_sha256'] ?? null) : null;
            if ($node === 'agent_summary_1' && !is_string($coverageHash)) self::invalidModelIdentity();
            $rows = self::modelInvocationRows($task, $node);
            $attempt = 1;
            $basisId = null;
            if ($rows !== []) {
                foreach ($rows as $row) self::assertModelDispatchIdentity($task, $row);
                $latest = $rows[array_key_last($rows)];
                $basisId = (int) $latest['invocation_id'];
                if ($latest['state'] === 'succeeded' && $latest['dispatch_state'] === 'succeeded') {
                    $attempt = (int) $latest['attempt_no'];
                } elseif ($latest['state'] === 'failed' && in_array($latest['dispatch_state'], ['failed', 'not_dispatched'], true)) {
                    $attempt = (int) $latest['attempt_no'] + 1;
                } else {
                    throw new ApiProblem('SAND_AI_TOOL_FINALIZATION_PENDING', 'Agent model request is not safely resumable');
                }
            }
            // No worker attempt is used here. This explicit admission owns the
            // next key before RETRYING, and successful prior calls keep their key.
            $base = self::modelRequestBase($task, $node, $coverageHash);
            $result[$node] = ['request_id' => $attempt === 1 ? $base : $base . '_continuation_' . $attempt,
                'attempt_no' => $attempt, 'basis_invocation_id' => $basisId]
                + ($coverageHash === null ? [] : ['coverage_sha256' => $coverageHash]);
        }
        return $result;
    }

    /** Durable before HTTP; a lease reclaim preserves this model identity rather than issuing a new one. */
    private static function persistModelRequestIdentity(TaskRun $task, TaskStep $step, string $node, ?string $coverageHash = null): array
    {
        return Db::connect('pgsql')->transaction(function () use ($task, $step, $node, $coverageHash): array {
            $current = TaskRun::where('id', (int) $task->id)->where('environment_id', (int) $task->environment_id)->lock(true)->find();
            $stored = TaskStep::where('id', (int) $step->id)->where('task_id', (int) $task->id)->lock(true)->find();
            if ($current === null || $stored === null || (string) $current->state !== TaskState::RUNNING
                || (string) $current->lease_owner !== (string) $task->lease_owner
                || (string) $current->lease_token !== (string) $task->lease_token
                || (int) $current->lease_fence !== (int) $task->lease_fence
                || (string) $stored->step_code !== $node || (string) $stored->state !== TaskState::RUNNING
                || (int) $stored->attempt_count !== (int) $current->attempt_count) {
                throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent model admission lost its task lease');
            }
            $identity = self::modelRequestIdentity($current, $node, $coverageHash);
            $context = is_object($stored->context) ? (array) $stored->context : $stored->context;
            if (!is_array($context)) self::invalidModelIdentity();
            $context['model_request_identity'] = $identity + ($coverageHash === null ? [] : ['coverage_sha256' => $coverageHash]);
            $stored->save(['context' => $context]);
            return $identity;
        });
    }

    private static function frozenModelIdentity(TaskRun $task, string $node, ?string $coverageHash, mixed $entry): array
    {
        if (!is_array($entry) || !is_string($entry['request_id'] ?? null) || !is_int($entry['attempt_no'] ?? null)
            || ($entry['coverage_sha256'] ?? null) !== $coverageHash) self::invalidModelIdentity();
        $identity = self::canonicalModelIdentity($task, $node, $coverageHash, $entry['attempt_no']);
        if ($entry['request_id'] !== $identity['request_id']) self::invalidModelIdentity();
        return $identity;
    }

    private static function modelRequestBase(TaskRun $task, string $node, ?string $coverageHash): string
    {
        if ($node === 'agent_summary_1' && is_string($coverageHash) && preg_match('/\A[a-f0-9]{64}\z/D', $coverageHash) === 1) {
            return 'agent_' . (int) $task->id . '_summary_' . substr($coverageHash, 0, 32);
        }
        if ($coverageHash === null && preg_match('/\Aagent_round_[1-4]\z/D', $node) === 1) {
            return 'agent_' . (int) $task->id . '_round_' . substr($node, -1);
        }
        self::invalidModelIdentity();
    }

    private static function canonicalModelIdentity(TaskRun $task, string $node, ?string $coverageHash, int $attempt): array
    {
        $base = self::modelRequestBase($task, $node, $coverageHash);
        if ($attempt < 1 || $attempt > max(1, (int) $task->attempt_count)) self::invalidModelIdentity();
        if ($attempt > 1) {
            $original = Invocation::where('request_id', $base)->find();
            $input = $task->input;
            $continuation = is_array($input) ? ($input['_agent_continuation'] ?? null) : null;
            $records = is_array($input) ? ($input['_agent_continuations'] ?? null) : null;
            if ($original === null || (string) $original->state !== 'failed'
                || (int) $original->environment_id !== (int) $task->environment_id
                || (int) $original->organization_id !== (int) $task->organization_id
                || (int) $original->application_id !== (int) $task->application_id
                || (int) $original->workload_client_id !== (int) $task->workload_client_id
                || !is_array($continuation) || ($continuation['version'] ?? null) !== 1
                || ($continuation['task_id'] ?? null) !== (int) $task->id
                || ($continuation['run_id'] ?? null) !== self::checkpointRunId($task)
                || !is_int($continuation['lease_fence'] ?? null) || $continuation['lease_fence'] < 1
                || $continuation['lease_fence'] > (int) $task->lease_fence
                || !is_array($records) || !array_is_list($records)) self::invalidModelIdentity();
            $recorded = false;
            foreach ($records as $record) if (is_array($record) && RequestPayloadFingerprint::same($record, $continuation, [])) $recorded = true;
            if (!$recorded) self::invalidModelIdentity();
        }
        return ['request_id' => $attempt === 1 ? $base : $base . '_continuation_' . $attempt, 'attempt_no' => $attempt];
    }

    private static function modelInvocationRows(TaskRun $task, string $node, ?int $invocationId = null): array
    {
        return Db::connect('pgsql')->query("SELECT i.id invocation_id,i.request_id,i.state,d.state dispatch_state,
                d.parent_task_run_id,d.parent_task_step_id,d.workflow_node_code,d.attempt_no,
                d.logical_request_id,d.logical_execution_id,d.execution_snapshot_sha256
            FROM sand_ai_invocation i JOIN sand_ai_invocation_dispatch d ON d.invocation_id=i.id
            WHERE d.parent_task_run_id=? AND d.workflow_node_code=? AND d.execution_scope='agent' AND d.capability_code='chat'
              AND i.environment_id=? AND i.organization_id=? AND i.application_id=? AND i.workload_client_id=?
              AND i.model_id=d.model_id AND i.deployment_id=d.deployment_id AND i.status=1 AND i.delete_time IS NULL"
              . ($invocationId === null ? '' : ' AND i.id=?') . ' ORDER BY d.attempt_no,i.id',
            [(int) $task->id, $node, (int) $task->environment_id, (int) $task->organization_id,
                (int) $task->application_id, (int) $task->workload_client_id, ...($invocationId === null ? [] : [$invocationId])]);
    }

    private static function invalidModelIdentity(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent admitted model identity is unavailable');
    }

    /** Same immutable task/step/attempt seed for protocol gates, stream events and historical projections. */
    public static function assertModelDispatchIdentity(TaskRun $task, array $dispatch): void
    {
        $stepId = (int) ($dispatch['parent_task_step_id'] ?? 0);
        $node = $dispatch['workflow_node_code'] ?? null;
        $step = TaskStep::where('id', $stepId)->where('task_id', (int) $task->id)->find();
        if ((int) ($dispatch['parent_task_run_id'] ?? 0) !== (int) $task->id || $step === null
            || !is_string($node) || (string) $step->step_code !== $node) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent dispatch parent is unavailable');
        }
        $context = is_object($step->context) ? (array) $step->context : $step->context;
        $coverageHash = $node === 'agent_summary_1' && is_array($context) ? ($context['coverage']['coverage_sha256'] ?? null) : null;
        if ($node === 'agent_summary_1' && !is_string($coverageHash)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent summary coverage is unavailable');
        }
        $identity = self::modelRequestIdentity($task, $node, $coverageHash, (int) ($dispatch['attempt_no'] ?? 0));
        $seed = 'agent:' . (int) $task->id . ':' . $stepId . ':' . $node . ':' . $identity['attempt_no'];
        if (($dispatch['request_id'] ?? null) !== $identity['request_id']
            || ($dispatch['logical_request_id'] ?? null) !== hash('sha256', $identity['request_id'])
            || ($dispatch['logical_execution_id'] ?? null) !== substr(hash('sha256', $seed), 0, 48)
            || ($dispatch['execution_snapshot_sha256'] ?? null) !== hash('sha256', 'snapshot:' . $seed)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent model dispatch identity changed');
        }
    }

    private static function summaryAttempt(TaskRun $task, string $coverageHash): int
    {
        return self::modelRequestIdentity($task, 'agent_summary_1', $coverageHash)['attempt_no'];
    }

    private static function summaryRequestId(TaskRun $task, string $coverageHash): string
    {
        return self::modelRequestIdentity($task, 'agent_summary_1', $coverageHash)['request_id'];
    }

    private static function roundAttempt(TaskRun $task, int $round): int
    {
        return self::modelRequestIdentity($task, 'agent_round_' . $round)['attempt_no'];
    }

    private static function roundRequestId(TaskRun $task, int $round): string
    {
        return self::modelRequestIdentity($task, 'agent_round_' . $round)['request_id'];
    }

    private static function assertCheckpointSource(array $source): void
    {
        $block = \app\Infrastructure\Persistence\Model\SourceBlock::where('id', $source['source_block_id'] ?? 0)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($block === null || (int) $block->file_id !== ($source['file_id'] ?? null)
            || (string) $block->locator_type !== ($source['locator_type'] ?? null)
            || !RequestPayloadFingerprint::same(['locator' => $block->locator], ['locator' => $source['locator'] ?? null])
            || !hash_equals((string) $block->content_sha256, hash('sha256', (string) $block->content))
            || !is_string($source['excerpt'] ?? null)
            || $source['excerpt'] !== mb_strcut(trim((string) $block->content), 0, 500, 'UTF-8')) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Checkpoint source changed or is unavailable');
        }
    }

    private static function maskCheckpointToolOutput(mixed $value): mixed
    {
        if (is_string($value)) return \app\Application\Knowledge\KnowledgeConversationContextMask::text($value);
        if (is_array($value)) return array_map(self::maskCheckpointToolOutput(...), $value);
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) $copy->{$key} = self::maskCheckpointToolOutput($item);
            return $copy;
        }
        return $value;
    }

    /** A bounded task-owned trace for each actual model-backed tool retrieval. */
    private function embeddingRetrievalStep(TaskRun $task, string $queryHash, string $requestId): TaskStep
    {
        $steps = TaskStep::where('task_id', (int) $task->id)->whereIn('step_code', ['agent_retrieval_1', 'agent_retrieval_2'])->order('sequence_no')->select();
        foreach ($steps as $step) {
            $context = is_object($step->context) ? (array) $step->context : $step->context;
            if (is_array($context) && ($context['query_sha256'] ?? null) === $queryHash) {
                if (($context['child_request_id'] ?? null) !== $requestId) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent retrieval request changed');
                }
                return $step;
            }
        }
        if (count($steps) >= 2) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Agent retrieval limit exceeded');
        $number = count($steps) + 1;
        return TaskStep::create([
            'task_id' => (int) $task->id, 'step_code' => 'agent_retrieval_' . $number,
            'sequence_no' => 10 + $number, 'state' => TaskState::RUNNING,
            'attempt_count' => 1, 'started_at' => date('Y-m-d H:i:s'),
            'context' => ['query_sha256' => $queryHash, 'child_request_id' => $requestId], 'status' => 1,
        ]);
    }

    public static function embeddingChildKey(int $taskId): string
    {
        if ($taskId < 1) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'Agent task identity is invalid');
        }
        return 'agent-task:' . $taskId . ':retrieval.search';
    }

    /**
     * A retrieval query the agent rewrote keeps its own child identity
     * (see the childKey suffix in retrieveSources), so a dispatch claim must
     * not assume the plain task key. Ownership is still derived, never
     * trusted: besides the original-query key, only a retrieval step of this
     * task whose recorded hash reproduces the presented request id counts.
     */
    public static function ownsEmbeddingChildRequest(TaskRun $task, int $publicationId, string $requestId): bool
    {
        if ($publicationId < 1 || $requestId === '' || (string) $task->task_type !== 'agent_run') return false;
        $environmentId = (int) $task->environment_id;
        $base = self::embeddingChildKey((int) $task->id);
        if (hash_equals(KnowledgeQueryEmbeddingGateway::childRequestId($environmentId, $publicationId, $base), $requestId)) {
            return true;
        }
        $steps = TaskStep::where('task_id', (int) $task->id)
            ->whereIn('step_code', ['agent_retrieval_1', 'agent_retrieval_2'])->order('sequence_no')->select();
        if (count($steps) > 2) return false;
        foreach ($steps as $step) {
            $context = is_object($step->context) ? (array) $step->context : $step->context;
            $hash = is_array($context) ? ($context['query_sha256'] ?? null) : null;
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) continue;
            $candidate = KnowledgeQueryEmbeddingGateway::childRequestId(
                $environmentId, $publicationId, $base . ':' . substr($hash, 0, 32),
            );
            if (hash_equals($candidate, $requestId) && ($context['child_request_id'] ?? null) === $requestId) {
                return true;
            }
        }
        return false;
    }

    private static function assertRunningLease(TaskRun $task, TaskExecutionLease $lease): void
    {
        $current = TaskRun::where('id', (int) $task->id)
            ->where('environment_id', (int) $task->environment_id)->find();
        if ($lease->taskId !== (int) $task->id || $current === null
            || (string) $current->task_type !== 'agent_run'
            || (string) $current->state !== TaskState::RUNNING
            || (string) $current->lease_owner !== $lease->owner
            || !hash_equals((string) $current->lease_token, $lease->token)
            || (int) $current->lease_fence !== $lease->fence
            || $current->canceled_at !== null) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent task lease changed');
        }
    }

    /** @return array<string,mixed> */
    private static function currentKnowledgePublication(int $environmentId, string $code): array
    {
        $publication = (new KnowledgePublicationGateway())->findForConsumer($environmentId, $code);
        if ((int) $publication['owner_environment_id'] !== $environmentId) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent knowledge belongs to another environment');
        }
        return $publication;
    }

    /** @param array<string,mixed> $expected */
    private static function assertKnowledgePublication(int $environmentId, string $code, array $expected): void
    {
        $current = self::currentKnowledgePublication($environmentId, $code);
        foreach (['id', 'active_version_id', 'source_scope_sha256', 'data_class'] as $field) {
            if ($current[$field] !== $expected[$field]) {
                throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent knowledge publication changed');
            }
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function preparedInput(array $input, ?UsageAttribution $attribution): array
    {
        $input = AgentPolicy::normalizeInput(array_diff_key($input, ['_usage_attribution' => true]));
        $input['_agent_neuron'] = true;
        $input['_agent_stream'] = true;
        if ($attribution !== null) {
            $input['_usage_attribution'] = $attribution->toPersisted();
            $input['_trusted_identity_scope'] = self::trustedIdentityScope($attribution);
        }

        return $input;
    }

    /** @param mixed $definition @param mixed $channel */
    private static function assertDefinitionChannelPair(mixed $definition, mixed $channel): void
    {
        if (!is_array($definition) || !is_array($channel)
            || !is_int($definition['definition_id'] ?? null)
            || !is_int($channel['agent_definition_id'] ?? null)
            || $definition['definition_id'] !== $channel['agent_definition_id']) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Channel agent binding is invalid');
        }
    }

    /** @param mixed $existingInput @param array<string,mixed> $currentInput */
    public static function assertReplayInput(mixed $existingInput, array $currentInput): void
    {
        $existing = is_object($existingInput) ? (array) $existingInput : $existingInput;
        if (!is_array($existing)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different agent input');
        }
        self::assertTrustedIdentityScope($existing, $currentInput);
        if (!RequestPayloadFingerprint::same(
            ['binding' => $existing['_agent_definition'] ?? null],
            ['binding' => $currentInput['_agent_definition'] ?? null],
        )) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another agent version');
        }
        if (!RequestPayloadFingerprint::same(
            ['channel' => $existing['_channel'] ?? null],
            ['channel' => $currentInput['_channel'] ?? null],
        )) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another channel message');
        }
        try {
            $existing = AgentPolicy::normalizeInput(self::businessInput($existing));
            $current = AgentPolicy::normalizeInput(self::businessInput($currentInput));
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different agent input');
        }
        if (!RequestPayloadFingerprint::same($existing, $current)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to a different agent input');
        }
    }

    /** @param array<string, mixed> $persistedInput */
    public static function attributionFromPersistedInput(array $persistedInput, int $environmentId): UsageAttribution
    {
        $value = $persistedInput['_usage_attribution'] ?? null;
        if (!is_array($value)
            || !is_int($value['environment_id'] ?? null)
            || (int) $value['environment_id'] !== $environmentId
            || !is_array($value['identity_snapshot'] ?? null)
            || !is_string($value['identity_snapshot']['application_ref'] ?? null)
            || trim($value['identity_snapshot']['application_ref']) === ''
            || !is_string($value['identity_snapshot']['workload_client_ref'] ?? null)
            || trim($value['identity_snapshot']['workload_client_ref']) === '') {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The agent trusted usage attribution is invalid');
        }
        try {
            return UsageAttribution::fromCurrentPersisted($value, $environmentId, 'agent', 'sand_ai.agent.run');
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The agent trusted usage attribution is invalid');
        }
    }

    private static function trustedIdentityScope(UsageAttribution $attribution): string
    {
        return RequestIdempotencyKey::trustedScopeForAttribution($attribution);
    }

    /** @param array<string,mixed> $persistedInput */
    private static function assertPersistedTrustedIdentityScope(array $persistedInput, UsageAttribution $attribution): void
    {
        $persistedScope = $persistedInput['_trusted_identity_scope'] ?? null;
        $currentScope = self::trustedIdentityScope($attribution);
        if (!is_string($persistedScope) || preg_match('/\A[a-f0-9]{64}\z/', $persistedScope) !== 1 || !hash_equals($persistedScope, $currentScope)) {
            throw new ApiProblem('SAND_AI_TASK_INVALID', 'The agent trusted workload identity is invalid');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function businessInput(array $input): array
    {
        return array_diff_key($input, [
            '_usage_attribution' => true,
            '_agent_definition' => true,
            '_agent_conversation' => true,
            '_channel' => true,
            '_trusted_identity_scope' => true,
            '_resource_data_class' => true,
            '_agent_readonly_protocol' => true,
            '_agent_readonly_tools' => true,
            '_agent_neuron' => true,
            '_agent_stream' => true,
            '_agent_checkpoint_version' => true,
            '_agent_continuation' => true,
            '_agent_continuations' => true,
            '_channel_receipt_recovery' => true,
            '_channel_receipt_recoveries' => true,
            'iam_authorization' => true,
        ]);
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $current */
    private static function assertTrustedIdentityScope(array $existing, array $current): void
    {
        $left = $existing['_trusted_identity_scope'] ?? null;
        $right = $current['_trusted_identity_scope'] ?? null;
        if ($left === null && $right === null) return; // pre-1.46 persisted task
        if (!is_string($left) || !is_string($right) || preg_match('/\A[a-f0-9]{64}\z/', $left) !== 1 || !hash_equals($left, $right)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The request id belongs to another trusted workload identity');
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(int $environmentId, string $action, int $taskId, string $summary, array $context): void
    {
        AuditLog::create([
            'actor_type' => 'api_credential',
            'actor_ref' => (string) $environmentId,
            'action' => $action,
            'resource_type' => 'task',
            'resource_id' => $taskId,
            'summary' => $summary,
            'context' => $context,
        ]);
    }
}
