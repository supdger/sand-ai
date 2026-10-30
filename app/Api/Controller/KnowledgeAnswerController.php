<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Api\Support\IdentityContextProblem;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\OutcomeReceiptRequest;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Knowledge\KnowledgeAnswerPolicy;
use app\Application\Knowledge\KnowledgeAnswerAttribution;
use app\Application\Knowledge\KnowledgeBaseQueryGateway;
use app\Application\Knowledge\KnowledgeConversationGateway;
use app\Application\Knowledge\KnowledgeConversationSummaryGateway;
use app\Application\Knowledge\KnowledgeEmbeddingIndexGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Knowledge\KnowledgeQueryEmbeddingGateway;
use app\Application\Knowledge\KnowledgeRerankGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use app\Identity\Infrastructure\KnowledgePublicationAccessGuard;
use app\Identity\Infrastructure\KnowledgePublicationFacts;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;
use support\Log;
use think\facade\Db;
use Workerman\Timer;
use Workerman\Protocols\Http\ServerSentEvents;

final class KnowledgeAnswerController
{
    public function answer(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            return ApiResponder::success($this->runAnswer($request, $code, 'knowledge-answer', []), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function createConversation(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if ($request->post() !== []) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation creation has no request fields');
            }
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $key = RequestIdempotencyKey::required($request, 'knowledge-conversation-create', $knowledge['context']);
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $result = (new KnowledgeConversationGateway())->create($publication, $knowledge['context'], $key);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function listConversations(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $before = $request->get('before');
            if ($before !== null && (!is_string($before) || preg_match('/^[a-f0-9]{32}$/D', $before) !== 1)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation cursor is invalid');
            }
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $this->assertSourcesAvailable($publication, 'conversation');
            $result = (new KnowledgeConversationGateway())->list($publication, $knowledge['context'], $before);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function readCreatedConversation(Request $request, string $code): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $key = RequestIdempotencyKey::required($request, 'knowledge-conversation-create', $knowledge['context']);
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $this->assertSourcesAvailable($publication, 'conversation');
            $result = (new KnowledgeConversationGateway())->readCreated($publication, $knowledge['context'], $key);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function readConversation(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $before = $request->get('before');
            if ($before !== null && (!is_string($before) || preg_match('/^[1-9][0-9]*$/D', $before) !== 1
                || filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation cursor is invalid');
            }
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $this->assertSourcesAvailable($publication, 'conversation');
            $result = (new KnowledgeConversationGateway())->read(
                $conversationId, $publication, $knowledge['context'], $before === null ? null : (int) $before,
            );
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function readConversationTurnByRequest(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $key = RequestIdempotencyKey::requiredForResource(
                $request, 'knowledge-turn', $knowledge['context'], $conversationId,
            );
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $this->assertSourcesAvailable($publication, 'conversation');
            $result = (new KnowledgeConversationGateway())->readTurnByRequest(
                $conversationId, $publication, $knowledge['context'], $key,
            );
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function streamConversationTurn(Request $request, string $code, string $conversationId, string $sequenceNo): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if (preg_match('/\A[1-9][0-9]*\z/D', $sequenceNo) !== 1
                || (string) (int) $sequenceNo !== $sequenceNo
                || (int) $sequenceNo >= PHP_INT_MAX) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation turn number is invalid');
            }
            $connection = $request->connection;
            if ($connection === null || $request->protocolVersion() !== '1.1') {
                throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Conversation events require HTTP/1.1');
            }
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $resourceKey = (int) $publication['id'] . ':' . $clientId;
            $this->assertPublicationFacts($publication, $resourceKey);
            $gateway = new KnowledgeConversationGateway();
            $readTurn = static function () use ($gateway, $conversationId, $publication, $knowledge, $sequenceNo): array {
                $page = $gateway->read($conversationId, $publication, $knowledge['context'], (int) $sequenceNo + 1);
                foreach ($page['turns'] as $turn) {
                    if ($turn['sequence_no'] === (int) $sequenceNo) {
                        return $turn;
                    }
                }
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Conversation turn not found');
            };
            $this->assertSourcesAvailable($publication, 'conversation');
            $readTurn();
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $afterSegment = trim((string) $request->header('last-event-id', ''));
            if ($afterSegment !== '' && (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $afterSegment) !== 1
                || filter_var($afterSegment, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
                || (int) $afterSegment > 16)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation segment cursor is invalid');
            }
            $lastSegment = $afterSegment === '' ? 0 : (int) $afterSegment;

            $timerId = null;
            $startedAt = time();
            $lastPayload = null;
            $lastHeartbeat = 0;
            $timerId = Timer::add(1.0, function () use (
                $request, $connection, $knowledge, $publication, $clientId, $access, $decision,
                $requestId, $resourceKey, $readTurn, $sequenceNo, $conversationId, $startedAt,
                &$timerId, &$lastPayload, &$lastHeartbeat, &$lastSegment, $gateway,
            ): void {
                if ($connection->getStatus() === $connection::STATUS_CLOSED) {
                    Timer::del($timerId);
                    return;
                }
                try {
                    $this->assertPublicationFacts($publication, $resourceKey);
                    $this->assertSourcesAvailable($publication, 'conversation');
                    $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                    $turn = $readTurn();
                    $previewInterrupted = in_array($turn['execution_state'], ['outcome_unknown', 'recovery_required'], true);
                    if (!$previewInterrupted && ($turn['state'] === 'pending' || $turn['state'] === 'answered')) {
                        foreach ($gateway->readSegments(
                            $conversationId, $publication, $knowledge['context'], (int) $sequenceNo, $lastSegment,
                        ) as $segment) {
                            $connection->send((string) new ServerSentEvents([
                                'event' => 'segment', 'id' => (string) $segment['segment_no'],
                                'data' => json_encode([
                                    'conversation_id' => $conversationId,
                                    'sequence_no' => (int) $sequenceNo,
                                    'segment_no' => $segment['segment_no'],
                                    'text' => $segment['text'],
                                    'source_block_ids' => $segment['source_block_ids'],
                                    'provisional' => true,
                                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                            ]), true);
                            $lastSegment = $segment['segment_no'];
                        }
                    }
                    $payload = json_encode([
                        'conversation_id' => $conversationId,
                        'turn' => $turn,
                    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($payload !== $lastPayload) {
                        $connection->send((string) new ServerSentEvents([
                            'event' => 'turn', 'data' => $payload,
                        ]), true);
                        $lastPayload = $payload;
                        $lastHeartbeat = time();
                    } elseif (time() - $lastHeartbeat >= 15) {
                        $connection->send((string) new ServerSentEvents(['' => 'keepalive']), true);
                        $lastHeartbeat = time();
                    }
                    if ($turn['state'] !== 'pending' || $previewInterrupted || time() - $startedAt >= 90) {
                        if ($turn['state'] === 'pending' && !$previewInterrupted) {
                            $connection->send((string) new ServerSentEvents(['event' => 'reconnect', 'data' => '{}']), true);
                        }
                        Timer::del($timerId);
                        $connection->close();
                    }
                } catch (\Throwable $failure) {
                    $code = $failure instanceof ApiProblem ? $failure->errorCode : 'SAND_AI_CONVERSATION_UNAVAILABLE';
                    $connection->send((string) new ServerSentEvents([
                        'event' => 'error', 'data' => json_encode(['error_code' => $code], JSON_THROW_ON_ERROR),
                    ]), true);
                    Timer::del($timerId);
                    $connection->close();
                }
            });
            return (new Response())->withHeaders([
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function closeConversation(Request $request, string $code, string $conversationId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if ($request->post() !== []) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation close has no request fields');
            }
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $result = (new KnowledgeConversationGateway())->close($conversationId, $publication, $knowledge['context']);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function cancelConversationTurn(Request $request, string $code, string $conversationId, string $sequenceNo): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            if ($request->post() !== [] || preg_match('/\A[1-9][0-9]*\z/D', $sequenceNo) !== 1
                || (string) (int) $sequenceNo !== $sequenceNo) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation cancellation input is invalid');
            }
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $this->assertSourcesAvailable($publication, 'conversation');
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $result = (new KnowledgeConversationGateway())->cancelPending(
                $conversationId, $publication, $knowledge['context'], (int) $sequenceNo,
            );
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    public function answerConversation(Request $request, string $code, string $conversationId): Response
    {
        return $this->handleConversationAnswer($request, $code, $conversationId, null);
    }

    public function resumeConversation(Request $request, string $code, string $conversationId, string $sequenceNo): Response
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $sequenceNo) !== 1
            || (string) (int) $sequenceNo !== $sequenceNo) {
            return ApiResponder::error(new ApiProblem(
                'SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation turn number is invalid',
            ), RequestAuditId::ensure($request));
        }
        return $this->handleConversationAnswer($request, $code, $conversationId, (int) $sequenceNo);
    }

    private function handleConversationAnswer(Request $request, string $code, string $conversationId, ?int $resumeSequenceNo): Response
    {
        $requestId = RequestAuditId::ensure($request);
        $prepared = null;
        $key = null;
        $gateway = null;
        $publication = null;
        $knowledge = null;
        try {
            [$knowledge, , $publication, $clientId, $access, $decision] = $this->authorizeConversation($request, $code, $requestId);
            $gateway = new KnowledgeConversationGateway();
            if ($resumeSequenceNo === null) {
                $input = KnowledgeAnswerPolicy::normalize($request->post(), true);
                $key = RequestIdempotencyKey::requiredForResource($request, 'knowledge-turn', $knowledge['context'], $conversationId);
            } else {
                if ($request->post() !== []) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Conversation resume accepts an empty body');
                }
                $pending = $gateway->pendingForResume(
                    $conversationId, $publication, $knowledge['context'], $resumeSequenceNo,
                );
                $input = $pending['input'];
                $key = $pending['request_id'];
            }
            $this->assertPublicationFacts($publication, (int) $publication['id'] . ':' . $clientId);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $prepared = $gateway->prepare($conversationId, $publication, $knowledge['context'], $key, $input);
            if ($prepared['result'] !== null) {
                $this->assertSourcesAvailable($publication, $input['query']);
                $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                return ApiResponder::success(array_replace($prepared['result'], [
                    'conversation_id' => $conversationId, 'idempotent_replay' => true,
                ]), $requestId);
            }
            $result = $this->runAnswer(
                $request, $code, 'knowledge-turn', $prepared['history'], $conversationId,
                function () use ($gateway, $conversationId, $publication, $knowledge, $prepared, $key): void {
                    $gateway->assertClaim(
                        $conversationId, $publication, $knowledge['context'],
                        $prepared['turn_id'], $key, $prepared['claim_token'],
                    );
                }, $prepared['earlier_questions'],
                $resumeSequenceNo === null ? null : $input,
                $resumeSequenceNo === null ? null : $key,
                function (int $environmentId, string $model, UsageAttribution $attribution, KnowledgeAnswerAttribution $source, callable $authorizeInvocation) use (
                    $conversationId, $publication, $knowledge, $prepared, $key,
                ): array {
                    return (new KnowledgeConversationSummaryGateway())->ensure(
                        $conversationId, $publication, $knowledge['context'],
                        $prepared['turn_id'], $key, $prepared['claim_token'],
                        $environmentId, $model, $attribution, $source, $authorizeInvocation,
                    );
                },
                function (array $segment, int $invocationId, int $dispatchId) use (
                    $gateway, $conversationId, $publication, $knowledge, $prepared, $key,
                ): void {
                    $gateway->appendSegment(
                        $conversationId, $publication, $knowledge['context'],
                        $prepared['turn_id'], $key, $prepared['claim_token'],
                        $invocationId, $dispatchId, $segment['text'], $segment['source_block_ids'],
                    );
                },
            );
            $coverage = $prepared['context_coverage'];
            $summaryCoverage = $result['summary_coverage'] ?? null;
            unset($result['summary_coverage']);
            if (is_array($summaryCoverage)) {
                $through = (int) $summaryCoverage['through_sequence_no'];
                $coverage['earlier_question_sequences'] = array_values(array_filter(
                    $coverage['earlier_question_sequences'],
                    static fn (int $sequence): bool => $sequence > $through,
                ));
                $coverage['earlier_answer_excerpt_sequences'] = array_values(array_filter(
                    $coverage['earlier_answer_excerpt_sequences'],
                    static fn (int $sequence): bool => $sequence > $through,
                ));
                $totalOlder = $coverage['omitted_answered_count']
                    + count($prepared['earlier_questions']);
                $coverage['omitted_answered_count'] = max(
                    0, $totalOlder - (int) $summaryCoverage['answered_count']
                        - count($coverage['earlier_answer_excerpt_sequences']),
                );
                $coverage['has_omitted_earlier_answered'] = $coverage['omitted_answered_count'] > 0;
                $coverage['summary_through_sequence_no'] = $through;
                $coverage['summary_answered_count'] = (int) $summaryCoverage['answered_count'];
                $coverage['summary_text'] = $summaryCoverage['summary'];
                $coverage['summary_state'] = $summaryCoverage['state'];
                $coverage['summary_used_for_model'] = $summaryCoverage['summary'] !== null
                    && $result['invocation'] !== null;
            }
            $result['context_coverage'] = $coverage + ['used_for_model' => $result['invocation'] !== null];
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $saved = $gateway->complete($conversationId, $publication, $knowledge['context'], $prepared['turn_id'], $key, $prepared['claim_token'], $result);
            return ApiResponder::success($saved + ['conversation_id' => $conversationId], $requestId);
        } catch (ApiProblem $problem) {
            if ($prepared !== null && $prepared['claim_token'] !== null
                && $gateway !== null && $publication !== null && $knowledge !== null && $key !== null) {
                try {
                    $gateway->failIfDefinitive(
                        $conversationId, $publication, $knowledge['context'],
                        $prepared['turn_id'], $key, $prepared['claim_token'], $problem,
                    );
                } catch (\Throwable $failure) {
                    Log::warning('SandAI knowledge conversation failure seal failed', [
                        'error_code' => $problem->errorCode, 'exception_type' => $failure::class,
                    ]);
                }
            }
            return ApiResponder::error($problem, $requestId);
        }
    }

    /**
     * @param list<array{query:string,answer:string}> $history
     * @param list<array{sequence_no:int,query:string,answer_excerpt:string}> $earlierQuestions
     * @param array{query:string,model:string,max_output_tokens:int,rerank:bool}|null $trustedInput
     * @return array<string,mixed>
     */
    private function runAnswer(Request $request, string $code, string $idempotencyDomain, array $history, ?string $conversationId = null, ?\Closure $authorizeTurn = null, array $earlierQuestions = [], ?array $trustedInput = null, ?string $trustedInvocationKey = null, ?\Closure $prepareSummary = null, ?\Closure $appendSegment = null): array
    {
            $requestId = RequestAuditId::ensure($request);
            $authenticator = new SandIamContextAuthenticator();
            $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
            $chat = $authenticator->authenticate($request, 'sand_ai.chat.complete');
            $this->sameConsumer($knowledge['context'], $chat['context']);
            $invocationKey = $trustedInvocationKey ?? ($conversationId === null
                ? RequestIdempotencyKey::required($request, $idempotencyDomain, $chat['context'])
                : RequestIdempotencyKey::requiredForResource($request, $idempotencyDomain, $chat['context'], $conversationId));
            $input = KnowledgeAnswerPolicy::normalize($trustedInput ?? $request->post(), $conversationId !== null);
            if ($input['rerank'] && $conversationId === null) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Rerank requires a conversation turn');
            }
            $publication = (new KnowledgePublicationGateway())->findForConsumer($knowledge['environment_id'], $code);
            $clientId = filter_var($knowledge['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($clientId === false) {
                throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
            }
            $clientId = (int) $clientId;
            $resourceKey = (int) $publication['id'] . ':' . $clientId;
            $source = KnowledgeAnswerAttribution::fromPublication($publication);
            $access = new KnowledgePublicationAccessGuard();
            $decision = $access->authorize($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId);
            $retrievalLimit = $input['rerank'] ? 10 : 5;
            $embedding = null;
            $authorizeEmbedding = function (int $invocationId) use (
                $publication, $resourceKey, $access, $request, $knowledge,
                $clientId, $requestId, $decision, $authorizeTurn,
            ): void {
                $this->assertPublicationFacts($publication, $resourceKey);
                $access->revalidate(
                    $request, $knowledge['context'], (int) $publication['id'],
                    $clientId, $requestId, $decision,
                );
                $authorizeTurn?->__invoke();
            };
            if ((new KnowledgeEmbeddingIndexGateway())->hasActiveIndex($publication)) {
                $embedding = (new KnowledgeQueryEmbeddingGateway())->embed(
                    $publication, $input['query'], $invocationKey,
                    UsageAttribution::fromIdentity(
                        $knowledge['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding',
                    ),
                    $authorizeEmbedding,
                );
            } else {
                (new KnowledgeQueryEmbeddingGateway())->assertNoOrphanedOriginalKey(
                    $publication, $invocationKey,
                    UsageAttribution::fromIdentity(
                        $knowledge['context'], 'knowledge_query', 'sand_ai.knowledge.query', 'embedding',
                    ),
                    $authorizeEmbedding,
                );
            }
            $retrieved = Db::connect('pgsql')->transaction(function () use (
                $publication, $input, $resourceKey, $embedding, $retrievalLimit,
            ): array {
                $gateway = new KnowledgeBaseQueryGateway();
                $search = ['query' => $input['query'], 'limit' => $retrievalLimit];
                $result = $embedding === null
                    ? $gateway->query($publication, $search)
                    : $gateway->queryWithVector(
                        $publication, $search, $embedding['vector'], $embedding['index_id'],
                    );
                $this->assertPublicationFacts($publication, $resourceKey);
                return $result;
            });
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            if ($retrieved['citations'] === []) {
                return [
                    'answer_state' => 'no_sources', 'answer' => null, 'citations' => [],
                    'review_required' => true, 'invocation' => null, 'usage' => null,
                    'rerank_invocation' => null, 'rerank_usage' => null, 'rerank_state' => 'no_sources',
                    'embedding_invocation' => $embedding['invocation'] ?? null,
                    'embedding_usage' => $embedding['usage'] ?? null,
                    'knowledge_base' => $retrieved['knowledge_base'], 'query' => $input['query'],
                ];
            }
            $summary = null;
            if ($prepareSummary !== null) {
                $summary = $prepareSummary(
                    $chat['environment_id'], $input['model'],
                    UsageAttribution::fromIdentity($chat['context'], 'chat', 'sand_ai.chat.complete'),
                    $source,
                    function (int $invocationId) use (
                        $publication, $resourceKey, $access, $request, $knowledge, $clientId,
                        $requestId, $decision, $chat, $input, $retrieved, $embedding,
                        $retrievalLimit, $authorizeTurn,
                    ): void {
                        $this->assertPublicationFacts($publication, $resourceKey);
                        $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
                        $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                        $authorizeTurn?->__invoke();
                        (new ChatInvocationAccessGuard())->authorize($request, $chat['context'], $invocationId, $requestId);
                    },
                );
                $earlierQuestions = array_values(array_filter(
                    $earlierQuestions,
                    static fn (array $turn): bool => $turn['sequence_no'] > $summary['through_sequence_no'],
                ));
            }
            $rerank = ['citations' => $retrieved['citations'], 'invocation' => null, 'usage' => null,
                'state' => $input['rerank'] ? 'single_source' : 'not_requested'];
            if ($input['rerank'] && count($retrieved['citations']) > 1) {
                $rerank = (new KnowledgeRerankGateway())->rerank(
                    $chat['environment_id'], $input['model'], $input['query'], $retrieved['citations'],
                    $invocationKey,
                    UsageAttribution::fromIdentity($chat['context'], 'chat', 'sand_ai.chat.complete'),
                    $source,
                    function (int $invocationId) use ($publication, $resourceKey, $access, $request, $knowledge, $clientId, $requestId, $decision, $chat, $input, $retrieved, $embedding, $retrievalLimit, $authorizeTurn): void {
                        $this->assertPublicationFacts($publication, $resourceKey);
                        $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
                        $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                        $authorizeTurn?->__invoke();
                        (new ChatInvocationAccessGuard())->authorize($request, $chat['context'], $invocationId, $requestId);
                    },
                );
            }
            $selected = array_slice($rerank['citations'], 0, 5);
            $this->assertPublicationFacts($publication, $resourceKey);
            $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            $messages = KnowledgeAnswerPolicy::messages(
                $input['query'], $selected, $history, $earlierQuestions, $summary['summary'] ?? null,
                $input['stream_segments'] ?? false,
            );
            $streamedLength = 0;
            $chatDecision = null;
            $acceptSegment = ($input['stream_segments'] ?? false) && $appendSegment !== null
                ? function (array $rawSegment, int $invocationId, int $dispatchId) use (
                    $publication, $resourceKey, $access, $request, $knowledge, $chat, $clientId,
                    $requestId, $decision, $input, $retrieved, $embedding, $retrievalLimit,
                    $authorizeTurn, $selected, $appendSegment, &$streamedLength, &$chatDecision,
                ): array {
                    $this->assertPublicationFacts($publication, $resourceKey);
                    $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
                    $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                    $authorizeTurn?->__invoke();
                    if (!is_array($chatDecision)) {
                        throw new ApiProblem('SAND_AI_DISPATCH_NOT_AUTHORIZED', 'Chat authorization was not recorded');
                    }
                    (new ChatInvocationAccessGuard())->revalidate(
                        $request, $chat['context'], $invocationId, $requestId, $chatDecision,
                    );
                    $segment = KnowledgeAnswerPolicy::segment($rawSegment, $selected);
                    $nextLength = $streamedLength + mb_strlen($segment['text'], 'UTF-8')
                        + ($streamedLength === 0 ? 0 : 1);
                    if ($nextLength > 4000) {
                        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider answer is too long', true);
                    }
                    $appendSegment($segment, $invocationId, $dispatchId);
                    $streamedLength = $nextLength;
                    return $segment;
                }
                : null;
            $invocation = (new ChatGateway())->complete(
                $chat['environment_id'], $input['model'], $messages, $invocationKey,
                UsageAttribution::fromIdentity($chat['context'], 'chat', 'sand_ai.chat.complete'),
                $input['max_output_tokens'],
                outcomeReceiptRequest: new OutcomeReceiptRequest(262144),
                authorizePublicInvocation: function (int $invocationId) use ($publication, $resourceKey, $access, $request, $knowledge, $clientId, $requestId, $decision, $chat, $input, $retrieved, $embedding, $retrievalLimit, $authorizeTurn, &$chatDecision): void {
                    $this->assertPublicationFacts($publication, $resourceKey);
                    $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
                    $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
                    $authorizeTurn?->__invoke();
                    $chatDecision = (new ChatInvocationAccessGuard())->authorize(
                        $request, $chat['context'], $invocationId, $requestId,
                    );
                },
                knowledgeAnswerAttribution: $source,
                acceptSegment: $acceptSegment,
            );
            $this->assertPublicationFacts($publication, $resourceKey);
            $this->assertResultsCurrent($publication, $input['query'], $retrieved, $embedding, $retrievalLimit);
            $access->revalidate($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId, $decision);
            if (($invocation['invocation']['state'] ?? null) !== 'succeeded') {
                throw new ApiProblem('SAND_AI_INVOCATION_NOT_SUCCEEDED', 'Knowledge answer invocation did not succeed');
            }
            $answer = KnowledgeAnswerPolicy::result($invocation['content'] ?? null, $selected);
            return $answer + [
                'knowledge_base' => $retrieved['knowledge_base'],
                'query' => $input['query'],
                'invocation' => $invocation['invocation'] ?? null,
                'usage' => $invocation['usage'] ?? null,
                'rerank_invocation' => $rerank['invocation'],
                'rerank_usage' => $rerank['usage'],
                'rerank_state' => $rerank['state'],
                'embedding_invocation' => $embedding['invocation'] ?? null,
                'embedding_usage' => $embedding['usage'] ?? null,
                'summary_invocations' => $summary['invocations'] ?? [],
                'summary_usage' => $summary['usage'] ?? [],
                'summary_coverage' => $summary,
                'idempotent_replay' => $invocation['idempotent_replay'] ?? false,
            ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:int,4:KnowledgePublicationAccessGuard,5:array<string,mixed>} */
    private function authorizeConversation(Request $request, string $code, string $requestId): array
    {
        $authenticator = new SandIamContextAuthenticator();
        $knowledge = $authenticator->authenticate($request, 'sand_ai.knowledge.query');
        $chat = $authenticator->authenticate($request, 'sand_ai.chat.complete');
        $this->sameConsumer($knowledge['context'], $chat['context']);
        $publication = (new KnowledgePublicationGateway())->findForConsumer($knowledge['environment_id'], $code);
        $clientId = filter_var($knowledge['context']->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
        }
        $clientId = (int) $clientId;
        $access = new KnowledgePublicationAccessGuard();
        $decision = $access->authorize($request, $knowledge['context'], (int) $publication['id'], $clientId, $requestId);
        return [$knowledge, $chat, $publication, $clientId, $access, $decision];
    }

    /** @param array<string,mixed> $publication */
    private function assertSourcesAvailable(array $publication, string $query): void
    {
        (new KnowledgeBaseQueryGateway())->query($publication, ['query' => $query, 'limit' => 1]);
    }

    /** @param array<string,mixed> $publication @param array<string,mixed> $retrieved */
    private function assertResultsCurrent(
        array $publication, string $query, array $retrieved,
        ?array $embedding, int $retrievalLimit,
    ): void
    {
        $gateway = new KnowledgeBaseQueryGateway();
        $search = ['query' => $query, 'limit' => $retrievalLimit];
        $current = $embedding === null
            ? $gateway->query($publication, $search)
            : $gateway->queryWithVector(
                $publication, $search, $embedding['vector'], $embedding['index_id'],
            );
        if ($current['citations'] !== $retrieved['citations']) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge answer sources changed during execution');
        }
    }

    private function sameConsumer(IdentityContext $knowledge, IdentityContext $chat): void
    {
        if ($knowledge->organizationId !== $chat->organizationId
            || $knowledge->applicationId !== $chat->applicationId
            || $knowledge->environmentId !== $chat->environmentId
            || $knowledge->workloadClientId !== $chat->workloadClientId
            || $knowledge->audience !== $chat->audience) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
        }
    }

    /** @param array<string,mixed> $publication */
    private function assertPublicationFacts(array $publication, string $resourceKey): void
    {
        try {
            $facts = (new KnowledgePublicationFacts())->resolve('sand_ai', 'sand_ai.knowledge.query', $resourceKey);
            $version = Db::connect('pgsql')->query(
                'SELECT source_scope_sha256,data_class FROM sand_ai_knowledge_base_version WHERE id=?',
                [(int) $publication['active_version_id']],
            );
            if ($facts['resource_ref'] !== $publication['id'] . ':' . $publication['active_version_id']
                || $facts['data_class'] !== ($publication['data_class'] ?? null)
                || count($version) !== 1
                || (string) $version[0]['source_scope_sha256'] !== (string) $publication['source_scope_sha256']
                || $version[0]['data_class'] !== ($publication['data_class'] ?? null)) {
                throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
            }
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }
}
