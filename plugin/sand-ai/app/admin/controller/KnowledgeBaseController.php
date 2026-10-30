<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeBaseGateway;
use app\Application\Knowledge\KnowledgeBaseTrialGateway;
use app\Application\Knowledge\KnowledgeEmbeddingBuildGateway;
use app\Application\Knowledge\KnowledgeEmbeddingIndexGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Knowledge\KnowledgeSourcePurgeGateway;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class KnowledgeBaseController extends SandAiAdminController
{
    #[Permission('SandAI 知识库列表', 'sand_ai:knowledge:index')]
    public function index(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgeBaseGateway())->index(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '知识库已加载');
    }

    #[Permission('SandAI 可用知识来源', 'sand_ai:knowledge:index')]
    public function eligibleFiles(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgeBaseGateway())->eligibleFiles(
            $this->queryInt($request->get('environment_id')),
            $request->get('before') === null ? null : $this->queryInt($request->get('before')),
        ), '知识来源已加载');
    }

    #[Permission('SandAI 建立知识向量索引', 'sand_ai:knowledge:index:build')]
    public function startEmbeddingIndex(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'knowledge_base_version_id']);
            return (new KnowledgeEmbeddingIndexGateway())->begin(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['knowledge_base_version_id'] ?? null),
                $this->idempotencyKey($request),
            );
        }, '向量索引已准备');
    }

    #[Permission('SandAI 构建知识向量索引', 'sand_ai:knowledge:index:build')]
    public function advanceEmbeddingIndex(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'index_id']);
            return (new KnowledgeEmbeddingBuildGateway())->buildNext(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['index_id'] ?? null),
            );
        }, '向量索引构建状态已更新');
    }

    #[Permission('SandAI 查询知识向量索引', 'sand_ai:knowledge:index:build')]
    public function embeddingIndexStatus(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgeEmbeddingIndexGateway())->status(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('index_id')),
        ), '向量索引状态已加载');
    }

    #[Permission('SandAI 找回知识向量索引', 'sand_ai:knowledge:index:build')]
    public function embeddingIndexByRequestKey(Request $request): Response
    {
        try {
            return $this->guard(function () use ($request): array {
                $query = $request->get();
                if (!is_array($query) || count($query) !== 3
                    || array_diff(array_keys($query), ['environment_id', 'knowledge_base_version_id', 'request_id']) !== []
                    || $request->rawBody() !== '' || !is_string($query['request_id'] ?? null)) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Index lookup requires only the original target and request key');
                }
                $actor = $this->authenticatedAdminId();
                $result = (new KnowledgeEmbeddingIndexGateway())->readByRequestKey(
                    $this->queryInt($query['environment_id']), $this->queryInt($query['knowledge_base_version_id']), $query['request_id'],
                );
                if ($actor !== $this->authenticatedAdminId()) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Current index viewing identity changed');
                }
                return $result;
            }, '原知识向量索引已找回')->header('Cache-Control', 'no-store');
        } catch (ApiException $failure) {
            $response = $failure->render($request);
            if (!$response instanceof Response) throw $failure;
            return $response->header('Cache-Control', 'no-store');
        }
    }

    #[Permission('SandAI 清理停用知识库向量', 'sand_ai:knowledge:disable')]
    public function purgeEmbeddingIndex(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'knowledge_base_id']);
            return (new KnowledgeEmbeddingIndexGateway())->purgeDisabled(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['knowledge_base_id'] ?? null),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '停用知识库向量已清理');
    }

    #[Permission('SandAI 清理停用知识库来源引用', 'sand_ai:knowledge:disable')]
    public function purgeSources(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'knowledge_base_id']);
            return (new KnowledgeSourcePurgeGateway())->purgeDisabled(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['knowledge_base_id'] ?? null),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '停用知识库来源引用已清理');
    }

    #[Permission('SandAI 创建知识库草稿', 'sand_ai:knowledge:create')]
    public function createDraft(Request $request): Response { return $this->guard(function () use ($request): array { $body = $this->body($request, ['environment_id', 'code', 'name', 'description', 'files', 'replace_draft']); $environmentId = $this->positiveInt($body['environment_id']); unset($body['environment_id']); return (new KnowledgeBaseGateway())->createDraft($environmentId, $body, $this->idempotencyKey($request), (string) $this->authenticatedAdminId()); }, '知识库草稿已创建'); }
    #[Permission('SandAI 找回知识库草稿', 'sand_ai:knowledge:create')]
    public function draftByRequestKey(Request $request): Response
    {
        try {
            return $this->guard(function () use ($request): array {
                $query = $request->get();
                if (!is_array($query) || count($query) !== 3
                    || array_diff(['environment_id', 'request_key', 'request_fingerprint'], array_keys($query)) !== []
                    || $request->rawBody() !== '' || !is_string($query['request_key'] ?? null)
                    || !is_string($query['request_fingerprint'] ?? null)) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Draft lookup requires original identifiers only');
                }
                $actor = $this->authenticatedAdminId();
                $result = (new KnowledgeBaseGateway())->draftByRequestKey(
                    $this->queryInt($query['environment_id']), $query['request_key'], $query['request_fingerprint'], (string) $actor,
                );
                if ($actor !== $this->authenticatedAdminId()) {
                    throw new ApiProblem('SAND_AI_FACTS_UNVERIFIED', 'Original knowledge draft proof is unavailable', 404);
                }
                return $result;
            }, '原草稿关联已找回，当前版本状态已读取')->header('Cache-Control', 'no-store');
        } catch (ApiException $failure) {
            $response = $failure->render($request);
            if (!$response instanceof Response) throw $failure;
            return $response->header('Cache-Control', 'no-store');
        }
    }

    #[Permission('SandAI 发布知识库', 'sand_ai:knowledge:publish')]
    public function publish(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'knowledge_base_id', 'expected_snapshot_sha256']);
            $snapshot = $body['expected_snapshot_sha256'] ?? null;
            if (array_key_exists('expected_snapshot_sha256', $body) && (!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/D', $snapshot) !== 1)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Expected snapshot SHA256 is invalid');
            }
            return (new KnowledgeBaseGateway())->publish(
                $this->positiveInt($body['environment_id']), $this->positiveInt($body['knowledge_base_id']),
                $this->idempotencyKey($request), (string) $this->authenticatedAdminId(), $snapshot,
            );
        }, '知识库已发布');
    }

    #[Permission('SandAI 找回知识发布结果', 'sand_ai:knowledge:publish')]
    public function publishByRequestKey(Request $request): Response
    {
        try {
            return $this->guard(function () use ($request): array {
                $query = $request->get();
                if (!is_array($query) || !in_array(count($query), [3, 4], true)
                    || array_diff(['environment_id', 'knowledge_base_id', 'request_key'], array_keys($query)) !== []
                    || array_diff(array_keys($query), ['environment_id', 'knowledge_base_id', 'request_key', 'expected_snapshot_sha256']) !== []
                    || $request->rawBody() !== '' || !is_string($query['request_key'] ?? null)
                    || trim($query['request_key']) === '' || strlen($query['request_key']) > 128) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge publish lookup requires only original identifiers');
                }
                $snapshot = $query['expected_snapshot_sha256'] ?? null;
                if (array_key_exists('expected_snapshot_sha256', $query) && (!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/D', $snapshot) !== 1)) {
                    throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Expected snapshot SHA256 is invalid');
                }
                $actor = $this->authenticatedAdminId();
                $result = (new KnowledgeBaseGateway())->publishByRequestKey(
                    $this->queryInt($query['environment_id']), $this->queryInt($query['knowledge_base_id']),
                    trim($query['request_key']), (string) $actor, $snapshot,
                );
                if ($actor !== $this->authenticatedAdminId()) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge publish operation not found');
                }
                return $result;
            }, '原知识发布结果已找回')->header('Cache-Control', 'no-store');
        } catch (ApiException $failure) {
            $response = $failure->render($request);
            if (!$response instanceof Response) throw $failure;
            return $response->header('Cache-Control', 'no-store');
        }
    }
    #[Permission('SandAI 停用知识库', 'sand_ai:knowledge:disable')]
    public function disable(Request $request): Response { return $this->guard(function () use ($request): array { $body = $this->body($request, ['environment_id', 'knowledge_base_id']); return (new KnowledgeBaseGateway())->disable($this->positiveInt($body['environment_id']), $this->positiveInt($body['knowledge_base_id']), $this->idempotencyKey($request), (string) $this->authenticatedAdminId()); }, '知识库已停用'); }
    #[Permission('SandAI 知识库检索试用', 'sand_ai:knowledge:query:test')]
    public function trial(Request $request): Response { return $this->guard(function () use ($request): array { $body = $this->body($request, ['owner_environment_id', 'knowledge_base_version_id', 'query', 'limit']); return (new KnowledgeBaseTrialGateway())->query($this->positiveInt($body['owner_environment_id'] ?? null), $this->positiveInt($body['knowledge_base_version_id'] ?? null), array_diff_key($body, array_flip(['owner_environment_id', 'knowledge_base_version_id']))); }, '知识库检索已完成'); }
    #[Permission('SandAI 开放知识库给应用', 'sand_ai:knowledge:publication:publish')]
    public function publishPublication(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['owner_environment_id', 'knowledge_base_id', 'consumer_environment_id', 'code']);
            return (new KnowledgePublicationGateway())->publish(
                $this->positiveInt($body['owner_environment_id'] ?? null),
                $this->positiveInt($body['knowledge_base_id'] ?? null),
                $this->positiveInt($body['consumer_environment_id'] ?? null),
                (string) ($body['code'] ?? ''),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '知识库已开放');
    }
    #[Permission('SandAI 撤销知识库应用访问', 'sand_ai:knowledge:publication:revoke')]
    public function revokePublication(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['owner_environment_id', 'publication_id']);
            return (new KnowledgePublicationGateway())->revoke(
                $this->positiveInt($body['owner_environment_id'] ?? null),
                $this->positiveInt($body['publication_id'] ?? null),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '知识库应用访问已撤销');
    }
    #[Permission('SandAI 可发布知识库列表', 'sand_ai:knowledge:publication:index')]
    public function publishableIndex(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgePublicationGateway())->listKnowledgeBases(
            $this->queryInt($request->get('owner_environment_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '可发布知识库已加载');
    }
    #[Permission('SandAI 知识库应用访问列表', 'sand_ai:knowledge:publication:index')]
    public function publicationIndex(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgePublicationGateway())->listPublications(
            $this->queryInt($request->get('owner_environment_id')),
            $this->queryInt($request->get('knowledge_base_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '知识库应用访问已加载');
    }
    #[Permission('SandAI 应用可用知识库列表', 'sand_ai:knowledge:publication:index')]
    public function consumerIndex(Request $request): Response
    {
        return $this->guard(fn (): array => (new KnowledgePublicationGateway())->listForConsumer(
            $this->queryInt($request->get('consumer_environment_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '应用可用知识库已加载');
    }
    #[Permission('SandAI 找回原停用结果', 'sand_ai:knowledge:disable')]
    public function disableByRequestKey(Request $request): Response { return $this->lifecycleReceipt($request, 'disable'); }
    #[Permission('SandAI 找回原向量清理结果', 'sand_ai:knowledge:disable')]
    public function embeddingPurgeByRequestKey(Request $request): Response { return $this->lifecycleReceipt($request, 'purge_vectors'); }
    #[Permission('SandAI 找回原来源解除结果', 'sand_ai:knowledge:disable')]
    public function sourcePurgeByRequestKey(Request $request): Response { return $this->lifecycleReceipt($request, 'purge_sources'); }
    private function lifecycleReceipt(Request $request, string $operation): Response
    {
        return $this->receiptResponse($request, function () use ($request, $operation): array {
            $actor = $this->authenticatedAdminId();
            $query = $this->receiptQuery($request, ['environment_id', 'knowledge_base_id', 'request_id']);
            $result = (new KnowledgeBaseGateway())->lifecycleByRequestKey(
                $this->queryInt($query['environment_id']), $this->queryInt($query['knowledge_base_id']),
                $operation, $query['request_id'], (string) $actor,
            );
            if ($actor !== $this->authenticatedAdminId()) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original administrator receipt is unavailable');
            return $result;
        });
    }
    #[Permission('SandAI 找回原应用开放结果', 'sand_ai:knowledge:publication:publish')]
    public function publishPublicationByRequestKey(Request $request): Response { return $this->publicationReceipt($request, 'publish'); }
    #[Permission('SandAI 找回原应用撤销结果', 'sand_ai:knowledge:publication:revoke')]
    public function revokePublicationByRequestKey(Request $request): Response { return $this->publicationReceipt($request, 'revoke'); }
    private function publicationReceipt(Request $request, string $operation): Response
    {
        return $this->receiptResponse($request, function () use ($request, $operation): array {
            $fields = $operation === 'publish'
                ? ['owner_environment_id', 'knowledge_base_id', 'consumer_environment_id', 'code', 'request_id']
                : ['owner_environment_id', 'publication_id', 'request_id'];
            $query = $this->receiptQuery($request, $fields);
            $actor = $this->authenticatedAdminId();
            $target = $operation === 'publish'
                ? ['knowledge_base_id' => $this->queryInt($query['knowledge_base_id']),
                    'consumer_environment_id' => $this->queryInt($query['consumer_environment_id']), 'code' => $query['code']]
                : ['publication_id' => $this->queryInt($query['publication_id'])];
            if ($operation === 'publish' && (!is_string($query['code']) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $query['code']) !== 1)) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Original publication code is invalid');
            }
            $result = (new KnowledgePublicationGateway())->readOperation($this->queryInt($query['owner_environment_id']),
                $operation, $query['request_id'], (string) $actor, $target);
            if ($actor !== $this->authenticatedAdminId()) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original administrator receipt is unavailable');
            return $result;
        });
    }
    private function receiptQuery(Request $request, array $fields): array
    {
        $query = $request->get();
        if (!is_array($query) || array_diff(array_keys($query), $fields) !== [] || array_diff($fields, array_keys($query)) !== []
            || $request->rawBody() !== '' || !is_string($query['request_id']) || trim($query['request_id']) === ''
            || trim($query['request_id']) !== $query['request_id'] || strlen($query['request_id']) > 128) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge receipt lookup requires only the original identifiers');
        }
        return $query;
    }
    private function receiptResponse(Request $request, callable $read): Response
    {
        try { return $this->guard($read, '原知识操作回执已读取')->header('Cache-Control', 'no-store'); }
        catch (ApiException $failure) {
            $response = $failure->render($request);
            if (!$response instanceof Response) throw $failure;
            return $response->header('Cache-Control', 'no-store');
        }
    }
    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback, string $message): Response { try { return $this->success($callback(), $message); } catch (ApiProblem $problem) { throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400); } }
    /** @param list<string> $keys @return array<string,mixed> */
    private function body(Request $request, array $keys): array { $body = $request->post(); if (!is_array($body) || array_diff(array_keys($body), $keys) !== []) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base request envelope is invalid'); return $body; }
    private function positiveInt(mixed $value): int { if (!is_int($value) || $value <= 0) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base request envelope is invalid'); return $value; }
    private function queryInt(mixed $value): int { $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($parsed === false) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge base query is invalid'); return (int) $parsed; }
    private function idempotencyKey(Request $request): string { $key = $request->header('idempotency-key'); if (!is_string($key) || trim($key) === '' || strlen($key) > 128) throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Idempotency-Key is invalid'); return trim($key); }
}
