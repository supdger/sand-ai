<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\PostgresOutcomeReceiptStore;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\CallStartPricingSnapshot;
use app\Application\Usage\UsageGateway;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageMeter;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Events\DocumentsRetrievedEvent;
use NeuronAI\RAG\Nodes\PostProcessNode;

/** Reorders already authorized source candidates; it never adds a new source. */
final class KnowledgeRerankGateway
{
    public static function childRequestId(string $answerRequestId): string
    {
        return 'knowledge-rerank:' . hash('sha256', $answerRequestId);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array{citations:list<array<string,mixed>>,invocation:array<string,mixed>|null,usage:array<string,mixed>|null,state:string}
     */
    public function rerank(
        int $environmentId,
        string $model,
        string $query,
        array $candidates,
        string $answerRequestId,
        UsageAttribution $attribution,
        KnowledgeAnswerAttribution $source,
        callable $authorizeInvocation,
    ): array {
        return $this->processCandidates(
            $environmentId, $model, $query, $candidates, $answerRequestId,
            $attribution, $source, $authorizeInvocation, null,
        );
    }

    /**
     * Reapply only the original completed ordering receipt to verified candidates.
     * @param list<array<string,mixed>> $candidates
     * @return array{citations:list<array<string,mixed>>,invocation:array<string,mixed>|null,usage:array<string,mixed>|null,state:string}
     */
    public function readOriginal(
        int $environmentId, string $model, string $query, array $candidates,
        string $answerRequestId, UsageAttribution $attribution,
        KnowledgeAnswerAttribution $source, callable $authorizeInvocation,
    ): array {
        return $this->processCandidates(
            $environmentId, $model, $query, $candidates, $answerRequestId,
            $attribution, $source, $authorizeInvocation,
            function (array $messages) use ($environmentId, $model, $answerRequestId, $attribution, $source, $authorizeInvocation): array {
                return $this->readReply(
                    $environmentId, $model, $messages, $answerRequestId,
                    $attribution, $source, $authorizeInvocation,
                );
            },
        );
    }

    /** @param list<array<string,mixed>> $candidates */
    private function processCandidates(
        int $environmentId, string $model, string $query, array $candidates,
        string $answerRequestId, UsageAttribution $attribution,
        KnowledgeAnswerAttribution $source, callable $authorizeInvocation,
        ?\Closure $readReply,
    ): array {
        if (count($candidates) < 2 || count($candidates) > 10) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Rerank candidates are invalid');
        }
        $documents = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['source_block_id'] ?? null;
            $excerpt = $candidate['excerpt'] ?? null;
            if (!is_int($id) || $id < 1 || !is_string($excerpt) || strlen($excerpt) > 500) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Rerank source is invalid');
            }
            $documents[] = (new Document($excerpt))->setId($id)->setSourceType('sand_ai_private')
                ->setSourceName('knowledge_chat_rerank')->setMetadata(['citation' => $candidate]);
        }
        $processor = new NeuronKnowledgeChatRerankPostProcessor(
            $environmentId, $model, $query, $answerRequestId, $attribution,
            $source, \Closure::fromCallable($authorizeInvocation), $readReply,
        );
        // Execute the SDK's actual post-processing node directly. No RAG agent,
        // graph, dispatcher, external observer or parallel model call is created.
        $processed = (new PostProcessNode([$processor]))(
            new DocumentsRetrievedEvent(new UserMessage($query), $documents),
            new AgentState(),
        );
        $citations = array_map(
            static fn (Document $document): array => $document->getMetadata()['citation'],
            $processed->documents,
        );
        return ['citations' => $citations] + $processor->outcome();
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    private function readReply(
        int $environmentId, string $model, array $messages, string $answerRequestId,
        UsageAttribution $attribution, KnowledgeAnswerAttribution $source,
        callable $authorizeInvocation,
    ): array {
        $requestId = self::childRequestId($answerRequestId);
        $fingerprint = RequestPayloadFingerprint::fingerprint([
            'environment_id' => $environmentId,
            'trusted_identity_scope' => RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            'model' => $model, 'messages' => $messages, 'max_output_tokens' => 256,
            'knowledge_answer' => [
                'publication_id' => $source->publicationId, 'version_id' => $source->versionId,
                'source_scope_sha256' => $source->sourceScopeSha256, 'data_class' => $source->dataClass,
            ],
        ], []);
        $invocation = Invocation::where('request_id', $requestId)->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original knowledge query rerank is unavailable');
        }
        if ($attribution->environmentId !== $environmentId || $attribution->sourceKind !== 'chat'
            || $attribution->serviceAction !== 'sand_ai.chat.complete' || $attribution->capabilityCode !== 'chat'
            || (int) $invocation->environment_id !== $environmentId
            || (int) $invocation->organization_id !== $attribution->organizationId
            || (int) $invocation->application_id !== $attribution->applicationId
            || (int) $invocation->workload_client_id !== $attribution->workloadClientId
            || (int) $invocation->knowledge_publication_id !== $source->publicationId
            || (int) $invocation->knowledge_base_version_id !== $source->versionId
            || (string) $invocation->knowledge_source_scope_sha256 !== $source->sourceScopeSha256
            || $invocation->data_class !== $source->dataClass
            || !hash_equals((string) $invocation->request_fingerprint, $fingerprint)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original knowledge query rerank intent changed');
        }
        $authorizeInvocation((int) $invocation->id);
        if ((string) $invocation->state !== 'succeeded') {
            if ((string) $invocation->state === 'failed') {
                $error = is_string($invocation->error_code)
                    && preg_match('/\ASAND_AI_[A-Z0-9_]+\z/D', $invocation->error_code) === 1
                    ? $invocation->error_code : 'SAND_AI_INVOCATION_NOT_SUCCEEDED';
                throw new ApiProblem($error, 'Original knowledge query rerank did not succeed');
            }
            throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS', 'Original knowledge query rerank is still unresolved', true);
        }
        $dispatch = InvocationDispatch::where('invocation_id', (int) $invocation->id)->find();
        if ($dispatch === null || (string) $dispatch->state !== 'succeeded'
            || (string) $dispatch->execution_scope !== 'chat'
            || (string) $dispatch->source_kind !== 'chat'
            || (string) $dispatch->service_action !== 'sand_ai.chat.complete'
            || (int) $dispatch->environment_id !== $environmentId
            || (int) $dispatch->application_id !== $attribution->applicationId
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (int) $dispatch->deployment_id !== (int) $invocation->deployment_id) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query rerank dispatch is not complete');
        }
        $config = config('sand_ai_outcome_receipt');
        if (!is_array($config)) {
            throw new ApiProblem('SAND_AI_RECEIPT_UNAVAILABLE', 'Knowledge query rerank encryption is unavailable');
        }
        $receipts = new OutcomeReceiptService(
            new PostgresOutcomeReceiptStore(), new OutcomeReceiptCipher($config), $config['ttl_seconds'] ?? 0,
        );
        $outcome = KnowledgeProviderOutcome::decode($receipts->replay([
            'environment_id' => $environmentId, 'invocation_id' => (int) $invocation->id,
            'request_fingerprint' => $fingerprint, 'capability_code' => 'knowledge_answer',
            'model_id' => (int) $invocation->model_id, 'deployment_id' => (int) $invocation->deployment_id,
            'version' => OutcomeReceiptCipher::RECEIPT_VERSION,
        ]));
        if ($outcome->attribution !== $attribution->toPersisted() + [
                'source_kind' => $attribution->sourceKind, 'service_action' => $attribution->serviceAction,
            ] || $outcome->model['code'] !== $model
            || $outcome->model['id'] !== (int) $invocation->model_id
            || $outcome->deployment['id'] !== (int) $invocation->deployment_id
            || $outcome->provider['id'] !== (int) $dispatch->provider_id) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query rerank receipt changed');
        }
        $route = is_object($dispatch->route_snapshot) ? (array) $dispatch->route_snapshot : $dispatch->route_snapshot;
        $price = is_object($dispatch->price_snapshot) ? (array) $dispatch->price_snapshot : $dispatch->price_snapshot;
        if (!is_array($route) || !is_array($price)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query rerank snapshots are unavailable');
        }
        DispatchRouteSnapshot::assert($route, false);
        $pricing = CallStartPricingSnapshot::fromPersistence(
            $invocation->call_started_at, $invocation->call_start_pricing_state,
            $invocation->call_start_price_revision_id, $invocation->call_start_price_snapshot,
        );
        if ($route['provider_id'] !== $outcome->provider['id']
            || $route['model_id'] !== $outcome->model['id']
            || $route['deployment_id'] !== $outcome->deployment['id']
            || $route['provider_adapter'] !== $outcome->providerAdapter
            || $route['model_code'] !== $outcome->model['code']
            || $route['deployment_remote_model'] !== $outcome->deployment['remote_model']
            || $route['deployment_revision'] !== $outcome->deployment['revision']
            || (new UsageMeter())->usageRouteSnapshot($route) != $outcome->deployment['route']
            || $price != $pricing->dispatchSnapshot()) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Original knowledge query rerank snapshots changed');
        }
        $usage = (new UsageGateway())->invocationCost(
            $environmentId, $requestId,
            static function (Invocation $read) use ($invocation, $authorizeInvocation): void {
                if ((int) $read->id !== (int) $invocation->id || (string) $read->state !== 'succeeded') {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original knowledge query rerank usage is unavailable');
                }
                $authorizeInvocation((int) $read->id);
            },
        );
        if (($usage['usage']['state'] ?? null) === 'missing_event') {
            throw new ApiProblem('SAND_AI_USAGE_PERSISTENCE_FAILED', 'Original knowledge query rerank usage event is unavailable', true);
        }
        $authorizeInvocation((int) $invocation->id);
        return [
            'content' => $outcome->content,
            'invocation' => ['request_id' => $requestId, 'state' => 'succeeded',
                'latency_ms' => $invocation->latency_ms, 'error_code' => $invocation->error_code],
            'usage' => $usage['tokens'] + $usage,
        ];
    }
}
