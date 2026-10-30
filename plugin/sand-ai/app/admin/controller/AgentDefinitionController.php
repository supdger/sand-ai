<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AgentDefinitionController extends SandAiAdminController
{
    #[Permission('SandAI 保存智能体草稿', 'sand_ai:agent:definition:manage')]
    public function draft(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','code','name','model','instructions','tools','review_required','max_output_tokens','knowledge_base_code','context_turn_limit','readonly_tools']);
            $environmentId = $this->positiveInt($body['environment_id'] ?? null);
            unset($body['environment_id']);
            return (new AgentDefinitionGateway())->draft($environmentId, $body, $this->key($request), (string) $this->authenticatedAdminId());
        });
    }

    #[Permission('SandAI 发布智能体', 'sand_ai:agent:definition:manage')]
    public function publish(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','agent_definition_id','expected_snapshot_sha256']);
            $expected = $body['expected_snapshot_sha256'] ?? null;
            if (array_key_exists('expected_snapshot_sha256', $body) && (!is_string($expected)
                || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Expected agent snapshot is invalid');
            }
            $adminId = $this->authenticatedAdminId();
            return (new AgentDefinitionGateway())->publish($this->positiveInt($body['environment_id'] ?? null), $this->positiveInt($body['agent_definition_id'] ?? null), $this->key($request), (string) $adminId, $expected, $this->operationAdminGuard($adminId));
        });
    }

    #[Permission('SandAI 停用智能体', 'sand_ai:agent:definition:manage')]
    public function disable(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','agent_definition_id']);
            return (new AgentDefinitionGateway())->disable($this->positiveInt($body['environment_id'] ?? null), $this->positiveInt($body['agent_definition_id'] ?? null), $this->key($request), (string) $this->authenticatedAdminId());
        });
    }

    #[Permission('SandAI 找回智能体管理操作', 'sand_ai:agent:definition:manage')]
    public function readOperationByRequestKey(Request $request): Response
    {
        try {
            $query = $request->get();
            if (!is_array($query) || array_diff(array_keys($query), ['environment_id', 'operation', 'code', 'request_key']) !== []
                || count($query) !== 4 || $request->rawBody() !== '' || (!is_string($query['environment_id'] ?? null)
                    && !is_int($query['environment_id'] ?? null)) || !is_string($query['operation'] ?? null)
                || !in_array($query['operation'], ['draft', 'publish', 'disable', 'rollback'], true)
                || !is_string($query['code'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $query['code']) !== 1
                || !is_string($query['request_key'] ?? null) || trim($query['request_key']) === '' || strlen($query['request_key']) > 128) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent operation lookup query is invalid');
            }
            $environmentId = $this->queryInt($query['environment_id']);
            $adminId = $this->authenticatedAdminId();
            $revalidateAdmin = $this->operationAdminGuard($adminId);
            $revalidateAdmin();
            $result = (new AgentDefinitionGateway())->readOperationByRequestKey($environmentId, $query['operation'], $query['code'],
                trim($query['request_key']), (string) $adminId, $revalidateAdmin);
            $revalidateAdmin();
            return $this->success($result, '已找回原智能体操作结果')->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 查看智能体配置', 'sand_ai:agent:definition:read')]
    public function read(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $id = filter_var($request->get('environment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $code = $request->get('code');
            if ($id === false || !is_string($code)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent query is invalid');
            return (new AgentDefinitionGateway())->read((int) $id, $code);
        });
    }

    #[Permission('SandAI 查看智能体配置版本', 'sand_ai:agent:definition:read')]
    public function versions(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $code = $request->get('code');
            if (!is_string($code)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent query is invalid');
            return (new AgentDefinitionGateway())->versions($this->queryInt($request->get('environment_id')), $code,
                $this->queryInt($request->get('page', 1)), $this->queryInt($request->get('limit', 20)));
        });
    }

    #[Permission('SandAI 回退智能体配置版本', 'sand_ai:agent:definition:manage')]
    public function rollback(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->body($request, ['environment_id','agent_definition_id','version_no']);
            return (new AgentDefinitionGateway())->rollback($this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['agent_definition_id'] ?? null), $this->positiveInt($body['version_no'] ?? null),
                $this->key($request), (string) $this->authenticatedAdminId());
        });
    }

    #[Permission('SandAI 查看智能体配置列表', 'sand_ai:agent:definition:read')]
    public function index(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $environmentId = $this->queryInt($request->get('environment_id'));
            $page = $this->queryInt($request->get('page', 1));
            $limit = $this->queryInt($request->get('limit', 20));
            return (new AgentDefinitionGateway())->list($environmentId, $page, $limit);
        });
    }

    #[Permission('SandAI 查看智能体可用模型', 'sand_ai:agent:definition:read')]
    public function models(Request $request): Response
    {
        return $this->guard(fn (): array => (new AgentDefinitionGateway())->models(
            $this->queryInt($request->get('environment_id')),
        ));
    }

    private function operationAdminGuard(int $adminId): \Closure
    {
        return function () use ($adminId): void {
            if ($this->authenticatedAdminId() !== $adminId || ($adminId !== 1 && !in_array('sand_ai:agent:definition:manage',
                \plugin\sandadmin\app\cache\UserAuthCache::getUserAuth($adminId), true))) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Agent operation request not found');
            }
        };
    }

    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback): Response { try { return $this->success($callback(), '智能体配置已更新'); } catch (ApiProblem $problem) { throw new ApiException($problem->errorCode . ': ' . $problem->getMessage()); } }
    /** @param list<string> $keys @return array<string,mixed> */
    private function body(Request $request, array $keys): array { $body = $request->post(); if (!is_array($body) || array_diff(array_keys($body), $keys) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent request is invalid'); return $body; }
    private function positiveInt(mixed $value): int { if (!is_int($value) || $value <= 0) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Agent request is invalid'); return $value; }
    private function queryInt(mixed $value): int { $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($parsed === false) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Pagination is invalid'); return (int) $parsed; }
    private function key(Request $request): string { $key = $request->header('idempotency-key'); if (!is_string($key) || trim($key) === '' || strlen($key) > 128) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Idempotency-Key is invalid'); return trim($key); }
}
