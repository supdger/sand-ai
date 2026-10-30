<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Api\Support\ExpectedEnvironmentGuard;
use app\Api\Support\IdentityContextProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Knowledge\KnowledgeBasePolicy;
use app\Application\Knowledge\KnowledgeAnswerAttribution;
use app\Application\Knowledge\KnowledgeQueryIntentGateway;
use app\Application\Knowledge\KnowledgeRerankGateway;
use app\Application\Knowledge\KnowledgeProfessionalRerankGateway;
use app\Application\Knowledge\KnowledgeBaseQueryGateway;
use app\Application\Knowledge\KnowledgeEmbeddingIndexGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Knowledge\KnowledgeQueryEmbeddingGateway;
use app\Application\Knowledge\KnowledgeSourceGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use app\Identity\Infrastructure\KnowledgePublicationAccessGuard;
use app\Identity\Infrastructure\KnowledgePublicationFacts;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;
use think\facade\Db;

final class KnowledgeBaseController
{
    public function source(Request $request, string $code, string $version_no, string $source_block_id): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.knowledge.query');
            (new ExpectedEnvironmentGuard())->assert($request, $authenticated['environment_id']);
            $versionNo = $this->sourceId($version_no);
            $blockId = $this->sourceId($source_block_id);
            if ($request->get() !== []) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge source query parameters are not supported');
            }
            $publication = (new KnowledgePublicationGateway())->findSourceForConsumer($authenticated['environment_id'], $code, $versionNo);
            $clientId = filter_var($authenticated['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($clientId === false) throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
            $versionId = (int) $publication['active_version_id'];
            $resourceKey = (int) $publication['id'] . ':' . $clientId . ':' . $versionId;
            $access = new KnowledgePublicationAccessGuard();
            $decision = $access->authorize($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId, $versionId);
            $result = Db::connect('pgsql')->transaction(function () use (
                $request, $authenticated, $publication, $versionNo, $blockId,
                $clientId, $versionId, $resourceKey, $access, $decision, $requestId,
            ): array {
                $gateway = new KnowledgeSourceGateway();
                $source = $gateway->read($publication, $versionNo, $blockId);
                $this->assertPublicationFacts($publication, $resourceKey);
                if ($gateway->read($publication, $versionNo, $blockId) !== $source) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source changed during read');
                }
                $this->assertPublicationFacts($publication, $resourceKey);
                $access->revalidate(
                    $request, $authenticated['context'], (int) $publication['id'],
                    $clientId, $requestId, $decision, $versionId,
                );
                return $source;
            });
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Lists only current published professional routes under this knowledge grant. */
    public function rerankModels(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.knowledge.query');
            (new ExpectedEnvironmentGuard())->assert($request, $authenticated['environment_id']);
            if ($request->get() !== []) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Rerank model query parameters are not supported');
            }
            $publications = new KnowledgePublicationGateway();
            $publication = $publications->findForConsumer($authenticated['environment_id'], $code);
            $clientId = filter_var($authenticated['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($clientId === false) throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
            $resourceKey = (int) $publication['id'] . ':' . $clientId;
            $access = new KnowledgePublicationAccessGuard();
            $decision = $access->authorize($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId);
            $revalidate = function () use ($publications, $publication, $code, $resourceKey, $access, $request, $authenticated, $clientId, $requestId, $decision): void {
                $this->assertPublicationFacts($publication, $resourceKey);
                if ($publications->findForConsumer($authenticated['environment_id'], $code) !== $publication) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge publication changed during model read');
                }
                $access->revalidate($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                $this->assertPublicationFacts($publication, $resourceKey);
            };
            $revalidate();
            $gateway = new KnowledgeProfessionalRerankGateway();
            $models = $gateway->availableModels($authenticated['environment_id']);
            $revalidate();
            if ($gateway->availableModels($authenticated['environment_id']) !== $models) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Published rerank models changed during read');
            }
            $revalidate();
            return ApiResponder::success(['data' => $models], $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    public function query(Request $request, string $code, ?\Closure $keywordCompleted = null): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $authenticated = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            (new ExpectedEnvironmentGuard())->assert($request, $authenticated['environment_id']);
            $input = KnowledgeBasePolicy::normalizeConsumerQuery($request->post());
            $professional = ($input['rerank_mode'] ?? null) === 'professional';
            $chat = null;
            if ($input['rerank'] && !$professional) {
                $chat = $authenticator->authenticate($request, 'sand_ai.chat.complete');
                (new ExpectedEnvironmentGuard())->assert($request, $chat['environment_id']);
                $this->sameConsumer($authenticated['context'], $chat['context']);
            }
            $publication = (new KnowledgePublicationGateway())->findForConsumer($authenticated['environment_id'], $code);
            $clientId = filter_var($authenticated['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($clientId === false) throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
            $resourceKey = (int) $publication['id'] . ':' . $clientId;
            $access = new KnowledgePublicationAccessGuard();
            $decision = $access->authorize($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId);
            $indexGateway = new KnowledgeEmbeddingIndexGateway();
            $activeIndex = $indexGateway->hasActiveIndex($publication);
            $indexId = $activeIndex ? (int) $indexGateway->currentIndex($publication)['id'] : null;
            $revalidate = function () use ($publication, $resourceKey, $access, $request, $authenticated, $clientId, $requestId, $decision, $indexId): void {
                $this->assertPublicationFacts($publication, $resourceKey);
                $access->revalidate($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                $this->assertPublicationFacts($publication, $resourceKey);
                $this->assertIndexCurrent($publication, $indexId);
            };
            if ($professional) {
                // Reject an unavailable professional model before paid query embedding.
                $revalidate();
                (new KnowledgeProfessionalRerankGateway())->assertAvailable($authenticated['environment_id'], $input['model']);
                $revalidate();
            }
            $embedding = null;
            $hasKey = is_string($request->header('Idempotency-Key')) && trim($request->header('Idempotency-Key')) !== '';
            $key = ($activeIndex || $input['rerank'] || $hasKey)
                ? RequestIdempotencyKey::required($request, 'knowledge-query', $authenticated['context']) : null;
            $intent = new KnowledgeQueryIntentGateway();
            if ($key !== null) {
                // Persist the complete paid intent before either stage can create an invocation.
                $intent->admit($publication, $authenticated['context'], $input, $key, $indexId, $revalidate);
            }
            $authorizeEmbedding = static function (int $invocationId) use ($revalidate): void { $revalidate(); };
            if ($activeIndex) {
                $embedding = (new KnowledgeQueryEmbeddingGateway())->embed(
                    $publication, $input['query'], $key,
                    UsageAttribution::fromIdentity($authenticated['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding'),
                    $authorizeEmbedding,
                );
            } elseif ($key !== null) {
                (new KnowledgeQueryEmbeddingGateway())->assertNoOrphanedOriginalKey(
                    $publication, $key,
                    UsageAttribution::fromIdentity($authenticated['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding'),
                    $authorizeEmbedding,
                );
            }
            $search = ['query' => $input['query'], 'limit' => $input['limit']];
            $result = Db::connect('pgsql')->transaction(function () use ($publication, $search, $embedding, $revalidate): array {
                $result = $this->retrieve($publication, $search, $embedding);
                $revalidate();
                return $result;
            });
            $revalidateResults = function () use ($publication, $search, $result, $embedding, $revalidate): void {
                $revalidate();
                if ($this->retrieve($publication, $search, $embedding) !== $result) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Original knowledge query sources changed');
                }
                $revalidate();
            };
            if ($key !== null) {
                $intent->bindRetrieval($publication, $authenticated['context'], $input, $key, $indexId, $result['citations'], $revalidateResults);
            }
            $rerank = ['citations' => $result['citations'], 'invocation' => null, 'usage' => null,
                'state' => !$input['rerank'] ? 'not_requested' : ($result['citations'] === [] ? 'no_sources' : 'single_source')];
            $chatDecision = null;
            $chatInvocationId = null;
            $chatAccess = new ChatInvocationAccessGuard();
            if ($professional) {
                $rerank = (new KnowledgeProfessionalRerankGateway())->rerank(
                    $authenticated['environment_id'], $input['model'], $input['query'], $result['citations'], $key,
                    UsageAttribution::fromIdentity($authenticated['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'rerank'),
                    KnowledgeAnswerAttribution::fromPublication($publication),
                    static function (int $invocationId) use ($revalidateResults): void { $revalidateResults(); },
                );
            } elseif ($input['rerank'] && count($result['citations']) > 1) {
                $rerank = (new KnowledgeRerankGateway())->rerank(
                    $chat['environment_id'], $input['model'], $input['query'], $result['citations'], $key,
                    UsageAttribution::fromIdentity($chat['context'], 'chat', 'sand_ai.chat.complete'),
                    KnowledgeAnswerAttribution::fromPublication($publication),
                    function (int $invocationId) use ($revalidateResults, $chatAccess, $request, $chat, $requestId, &$chatDecision, &$chatInvocationId): void {
                        $revalidateResults();
                        $chatDecision = $chatAccess->authorize($request, $chat['context'], $invocationId, $requestId);
                        $chatInvocationId = $invocationId;
                        $revalidateResults();
                    },
                );
            }
            $revalidateResults();
            if ($chatDecision !== null && $chatInvocationId !== null) {
                $chatAccess->revalidate($request, $chat['context'], $chatInvocationId, $requestId, $chatDecision);
                $revalidateResults();
            }
            $result['citations'] = $rerank['citations'];
            $result['embedding_invocation'] = $embedding['invocation'] ?? null;
            $result['embedding_usage'] = $embedding['usage'] ?? null;
            $result['rerank_invocation'] = $rerank['invocation'];
            $result['rerank_usage'] = $rerank['usage'];
            $result['rerank_state'] = $rerank['state'];
            if ($professional) {
                $result['rerank_mode'] = 'professional';
                $result['budget'] = $this->professionalBudget($rerank);
            }
            if ($keywordCompleted !== null && !$activeIndex && $indexId === null
                && !$input['rerank'] && !$professional && $key !== null) {
                // Internal execution witness only; neither request fields nor
                // historical result reads can produce this completion proof.
                $retrieval = $intent->readOriginal($publication, $authenticated['context'], $input, $key, null);
                $revalidateResults();
                $keywordCompleted([
                    'schema' => 'sand_ai.keyword_no_provider/1',
                    'environment_id' => $authenticated['context']->environmentId,
                    'organization_id' => $authenticated['context']->organizationId,
                    'application_id' => $authenticated['context']->applicationId,
                    'workload_client_id' => $authenticated['context']->workloadClientId,
                    'query_request_id' => $key,
                    'publication_id' => (int) $publication['id'],
                    'knowledge_version_id' => (int) $publication['active_version_id'],
                    'source_scope_sha256' => (string) $publication['source_scope_sha256'],
                    'retrieval_snapshot_sha256' => $retrieval,
                ]);
            }
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /** Read only the complete original result; never admit or resume a query. */
    public function queryByRequestKey(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $authenticator = new SandIamContextAuthenticator();
            $authenticated = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            (new ExpectedEnvironmentGuard())->assert($request, $authenticated['environment_id']);
            $input = KnowledgeBasePolicy::normalizeConsumerQuery($request->post());
            $key = RequestIdempotencyKey::required($request, 'knowledge-query', $authenticated['context']);
            $professional = ($input['rerank_mode'] ?? null) === 'professional';
            $chat = null;
            if ($input['rerank'] && !$professional) {
                $chat = $authenticator->authenticate($request, 'sand_ai.chat.complete');
                (new ExpectedEnvironmentGuard())->assert($request, $chat['environment_id']);
                $this->sameConsumer($authenticated['context'], $chat['context']);
            }
            $publication = (new KnowledgePublicationGateway())->findForConsumer($authenticated['environment_id'], $code);
            $clientId = filter_var($authenticated['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($clientId === false) throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
            $resourceKey = (int) $publication['id'] . ':' . $clientId;
            $access = new KnowledgePublicationAccessGuard();
            $decision = $access->authorize($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId);
            $indexGateway = new KnowledgeEmbeddingIndexGateway();
            $indexId = $indexGateway->hasActiveIndex($publication)
                ? (int) $indexGateway->currentIndex($publication)['id'] : null;
            $revalidate = function () use ($publication, $resourceKey, $access, $request, $authenticated, $clientId, $requestId, $decision, $indexId): void {
                $this->assertPublicationFacts($publication, $resourceKey);
                $access->revalidate($request, $authenticated['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                $this->assertPublicationFacts($publication, $resourceKey);
                $this->assertIndexCurrent($publication, $indexId);
            };
            $revalidate();
            $intent = new KnowledgeQueryIntentGateway();
            $snapshot = $intent->readOriginal($publication, $authenticated['context'], $input, $key, $indexId);
            $embedding = $indexId === null ? null : (new KnowledgeQueryEmbeddingGateway())->readOriginal(
                $publication, $input['query'], $key,
                UsageAttribution::fromIdentity($authenticated['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding'),
                static function (int $invocationId) use ($revalidate): void { $revalidate(); },
            );
            $search = ['query' => $input['query'], 'limit' => $input['limit']];
            $result = $this->retrieve($publication, $search, $embedding);
            if (!hash_equals($snapshot, KnowledgeBasePolicy::fingerprint($result['citations']))) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Original knowledge query sources changed');
            }
            $revalidateResults = function () use ($publication, $search, $result, $embedding, $revalidate, $intent, $authenticated, $input, $key, $indexId, $snapshot): void {
                $revalidate();
                if ($this->retrieve($publication, $search, $embedding) !== $result
                    || !hash_equals($snapshot, $intent->readOriginal($publication, $authenticated['context'], $input, $key, $indexId))) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Original knowledge query sources changed');
                }
                $revalidate();
            };
            $revalidateResults();
            $rerank = ['citations' => $result['citations'], 'invocation' => null, 'usage' => null,
                'state' => !$input['rerank'] ? 'not_requested' : ($result['citations'] === [] ? 'no_sources' : 'single_source')];
            $chatDecision = null;
            $chatInvocationId = null;
            $chatAccess = new ChatInvocationAccessGuard();
            if ($professional) {
                $rerank = (new KnowledgeProfessionalRerankGateway())->readOriginal(
                    $authenticated['environment_id'], $input['model'], $input['query'], $result['citations'], $key,
                    UsageAttribution::fromIdentity($authenticated['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'rerank'),
                    KnowledgeAnswerAttribution::fromPublication($publication),
                    static function (int $invocationId) use ($revalidateResults): void { $revalidateResults(); },
                );
            } elseif ($input['rerank'] && count($result['citations']) > 1) {
                $rerank = (new KnowledgeRerankGateway())->readOriginal(
                    $chat['environment_id'], $input['model'], $input['query'], $result['citations'], $key,
                    UsageAttribution::fromIdentity($chat['context'], 'chat', 'sand_ai.chat.complete'),
                    KnowledgeAnswerAttribution::fromPublication($publication),
                    function (int $invocationId) use ($revalidateResults, $chatAccess, $request, $chat, $requestId, &$chatDecision, &$chatInvocationId): void {
                        $revalidateResults();
                        $chatDecision = $chatAccess->authorize($request, $chat['context'], $invocationId, $requestId);
                        $chatInvocationId = $invocationId;
                        $revalidateResults();
                    },
                );
            }
            if ($chatDecision !== null && $chatInvocationId !== null) {
                $chatAccess->revalidate($request, $chat['context'], $chatInvocationId, $requestId, $chatDecision);
            }
            $revalidateResults();
            $result['citations'] = $rerank['citations'];
            $result['embedding_invocation'] = $embedding['invocation'] ?? null;
            $result['embedding_usage'] = $embedding['usage'] ?? null;
            $result['rerank_invocation'] = $rerank['invocation'];
            $result['rerank_usage'] = $rerank['usage'];
            $result['rerank_state'] = $rerank['state'];
            if ($professional) {
                $result['rerank_mode'] = 'professional';
                $result['budget'] = $this->professionalBudget($rerank);
            }
            return ApiResponder::success($result, $requestId)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }

    /**
     * Project only the current budget state supplied by the professional gateway.
     * This response guard never reads or changes financial state.
     * @param array<string,mixed> $rerank
     * @return array{state:string,warnings:list<array{code:string}>}|null
     */
    private function professionalBudget(array $rerank): ?array
    {
        if (!array_key_exists('budget', $rerank)) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank budget state is unavailable');
        }
        $budget = $rerank['budget'];
        if ($budget === null) return null;
        if (!is_array($budget) || count($budget) !== 2
            || array_diff(array_keys($budget), ['state', 'warnings']) !== []
            || !is_string($budget['state'] ?? null)
            || !in_array($budget['state'], ['soft_cost_pending', 'held', 'settled', 'released'], true)
            || !is_array($budget['warnings'] ?? null) || !array_is_list($budget['warnings'])) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank budget state is invalid');
        }
        $warnings = in_array($budget['state'], ['soft_cost_pending', 'held'], true)
            ? [['code' => 'SAND_AI_BUDGET_COST_PENDING']] : [];
        if ($budget['warnings'] !== $warnings) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank budget warnings are invalid');
        }
        return ['state' => $budget['state'], 'warnings' => $warnings];
    }

    /** @param array<string,mixed> $publication @param array{query:string,limit:int} $search @param array<string,mixed>|null $embedding */
    private function retrieve(array $publication, array $search, ?array $embedding): array
    {
        $gateway = new KnowledgeBaseQueryGateway();
        return $embedding === null ? $gateway->query($publication, $search)
            : $gateway->queryWithVector($publication, $search, $embedding['vector'], $embedding['index_id']);
    }

    /** @param array<string,mixed> $publication */
    private function assertIndexCurrent(array $publication, ?int $indexId): void
    {
        $gateway = new KnowledgeEmbeddingIndexGateway();
        $current = $gateway->hasActiveIndex($publication)
            ? (int) $gateway->currentIndex($publication)['id'] : null;
        if ($current !== $indexId) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original knowledge query index changed');
        }
    }

    private function sameConsumer(IdentityContext $knowledge, IdentityContext $chat): void
    {
        if ($knowledge->organizationId !== $chat->organizationId || $knowledge->applicationId !== $chat->applicationId
            || $knowledge->environmentId !== $chat->environmentId || $knowledge->workloadClientId !== $chat->workloadClientId
            || $knowledge->audience !== $chat->audience) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
        }
    }

    /** @param array<string,mixed> $publication */
    private function assertPublicationFacts(array $publication, string $resourceKey): void
    {
        try {
            $facts = (new KnowledgePublicationFacts())->resolve(
                'sand_ai', 'sand_ai.knowledge.query', $resourceKey,
            );
            if ($facts['resource_ref'] !== $publication['id'] . ':' . $publication['active_version_id']
                || $facts['data_class'] !== ($publication['data_class'] ?? null)) {
                throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function sourceId(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge source path id is invalid');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge source path id is invalid');
        }
        return $id;
    }
}
