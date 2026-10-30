<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Tool\ToolAdminGateway;
use app\Application\Tool\ToolConnectionRequestSecrets;
use app\Application\Tool\ToolGatewayFactory;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ToolController extends SandAiAdminController
{
    #[Permission('SandAI 查看已绑定外部资料工具', 'sand_ai:tool:read')]
    public function available(Request $request): Response
    {
        return $this->guard(fn (): array => ToolGatewayFactory::admin()->available(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '外部资料工具目录已读取');
    }

    #[Permission('SandAI 查看外部资料连接', 'sand_ai:tool:read')]
    public function connections(Request $request): Response
    {
        return $this->guard(fn (): array => ToolGatewayFactory::admin()->connections(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('page', 1)),
            $this->queryInt($request->get('limit', 20)),
        ), '外部资料连接已读取');
    }

    #[Permission('SandAI 读取外部资料连接', 'sand_ai:tool:read')]
    public function readConnection(Request $request): Response
    {
        return $this->guard(fn (): array => ToolGatewayFactory::admin()->connection(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('connection_id')),
        ), '外部资料连接已读取');
    }

    #[Permission('SandAI 按代码读取外部资料连接', 'sand_ai:tool:read')]
    public function connectionByCode(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $code = $request->get('code');
            if (!is_string($code)) $this->invalid();
            return ToolGatewayFactory::admin()->connectionByCode(
                $this->queryInt($request->get('environment_id')),
                $code,
            );
        }, '外部资料连接已读取');
    }

    #[Permission('SandAI 查看受管 MCP 发现快照', 'sand_ai:tool:read')]
    public function latestMcp(Request $request): Response
    {
        return $this->guard(fn (): array => ToolGatewayFactory::mcpAdmin()->latestTested(
            $this->queryInt($request->get('environment_id')),
            $this->queryInt($request->get('connection_id')),
            $this->queryInt($request->get('connection_revision_id')),
        ), '受管 MCP 快照已读取');
    }

    #[Permission('SandAI 读取外部工具工作区', 'sand_ai:tool:read')]
    public function workspace(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $code = $request->get('code');
            if (!is_string($code)) $this->invalid();
            return ToolGatewayFactory::admin()->workspace(
                $this->queryInt($request->get('environment_id')),
                $this->queryInt($request->get('connection_id')),
                $code,
            );
        }, '外部工具工作区已读取');
    }

    #[Permission('SandAI 创建外部资料连接', 'sand_ai:tool:connection:create')]
    public function createConnection(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = ToolConnectionRequestSecrets::take($request);
            if (!is_array($body)) {
                $this->invalid();
            }
            $body = $this->body($body, ['environment_id', 'code', 'name', 'origin', 'path', 'authorization', 'data_class']);
            $environmentId = $this->positiveInt($body['environment_id'] ?? null);
            unset($body['environment_id']);
            return ToolGatewayFactory::admin()->createConnection($environmentId, $body, $this->idempotencyKey($request), (string) $this->authenticatedAdminId());
        }, '外部资料连接已创建');
    }

    #[Permission('SandAI 创建外部资料工具草稿', 'sand_ai:tool:create')]
    public function createDraft(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->bodyFromRequest($request, ['environment_id', 'adapter', 'mcp_snapshot_item_id', 'code', 'name', 'description', 'input_schema', 'output_schema', 'review_policy', 'timeout_ms', 'response_max_bytes']);
            $environmentId = $this->optionalEnvironment($body);
            unset($body['environment_id']);
            return ToolGatewayFactory::admin()->createDraft(
                $body, $this->idempotencyKey($request), (string) $this->authenticatedAdminId(), $environmentId,
            );
        }, '外部资料工具草稿已创建');
    }

    #[Permission('SandAI 发现受管 MCP 工具', 'sand_ai:tool:mcp:discover')]
    public function discoverMcp(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->bodyFromRequest($request, ['environment_id', 'connection_id', 'connection_revision_id']);
            return ToolGatewayFactory::mcpAdmin()->discover(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['connection_id'] ?? null),
                $this->positiveInt($body['connection_revision_id'] ?? null),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '受管 MCP 工具发现已完成');
    }

    #[Permission('SandAI 发布外部资料工具', 'sand_ai:tool:publish')]
    public function publish(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->bodyFromRequest($request, ['tool_id', 'environment_id']);
            return ToolGatewayFactory::admin()->publish($this->positiveInt($body['tool_id'] ?? null), $this->idempotencyKey($request), (string) $this->authenticatedAdminId(), $this->optionalEnvironment($body));
        }, '外部资料工具已发布');
    }

    #[Permission('SandAI 绑定外部资料工具', 'sand_ai:tool:bind')]
    public function bindEnvironment(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->bodyFromRequest($request, ['environment_id', 'tool_id', 'tool_version_id', 'connection_id', 'connection_revision_id']);
            return ToolGatewayFactory::admin()->bind(
                $this->positiveInt($body['environment_id'] ?? null),
                $this->positiveInt($body['tool_id'] ?? null),
                $this->positiveInt($body['tool_version_id'] ?? null),
                $this->positiveInt($body['connection_id'] ?? null),
                $this->positiveInt($body['connection_revision_id'] ?? null),
                $this->idempotencyKey($request),
                (string) $this->authenticatedAdminId(),
            );
        }, '外部资料工具已绑定环境');
    }

    #[Permission('SandAI 撤销外部资料工具', 'sand_ai:tool:revoke')]
    public function revoke(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $body = $this->bodyFromRequest($request, ['tool_id', 'environment_id']);
            return ToolGatewayFactory::admin()->revoke($this->positiveInt($body['tool_id'] ?? null), $this->idempotencyKey($request), (string) $this->authenticatedAdminId(), $this->optionalEnvironment($body));
        }, '外部资料工具已撤销');
    }

    #[Permission('SandAI 创建外部资料连接', 'sand_ai:tool:connection:create')]
    public function readConnectionOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'create_connection');
    }

    #[Permission('SandAI 创建外部资料工具草稿', 'sand_ai:tool:create')]
    public function readDraftOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'create_draft');
    }

    #[Permission('SandAI 发布外部资料工具', 'sand_ai:tool:publish')]
    public function readPublishOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'publish');
    }

    #[Permission('SandAI 绑定外部资料工具', 'sand_ai:tool:bind')]
    public function readBindOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'bind_environment');
    }

    #[Permission('SandAI 撤销外部资料工具', 'sand_ai:tool:revoke')]
    public function readRevokeOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'revoke');
    }

    #[Permission('SandAI 发现受管 MCP 工具', 'sand_ai:tool:mcp:discover')]
    public function readDiscoveryOperation(Request $request): Response
    {
        return $this->originalOperation($request, 'mcp_discovery');
    }

    private function originalOperation(Request $request, string $operation): Response
    {
        return $this->guard(function () use ($request, $operation): array {
            $query = $request->get();
            if (!is_array($query) || $request->rawBody() !== ''
                || array_diff(array_keys($query), ['environment_id', 'request_key', 'target_sha256']) !== []
                || !is_string($query['request_key'] ?? null) || !is_string($query['target_sha256'] ?? null)) {
                $this->invalid();
            }
            $environmentId = $this->queryInt($query['environment_id'] ?? null);
            $actor = (string) $this->authenticatedAdminId();
            return $operation === 'mcp_discovery'
                ? ToolGatewayFactory::mcpAdmin()->readOriginal($environmentId, $query['request_key'], $query['target_sha256'], $actor)
                : ToolGatewayFactory::admin()->readOperation($operation, $environmentId, $query['request_key'], $query['target_sha256'], $actor);
        }, '原外部工具操作回执已读取')->header('Cache-Control', 'no-store');
    }

    /** Legacy API callers may omit the UI environment; their receipts cannot establish a later environment. */
    private function optionalEnvironment(array $body): ?int
    {
        return array_key_exists('environment_id', $body) ? $this->positiveInt($body['environment_id']) : null;
    }

    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback, string $message): Response
    {
        try {
            return $this->success($callback(), $message);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function bodyFromRequest(Request $request, array $allowed): array
    {
        $body = $request->post();
        if (!is_array($body)) {
            $this->invalid();
        }
        return $this->body($body, $allowed);
    }

    /** @param array<string,mixed> $body @param list<string> $allowed @return array<string,mixed> */
    private function body(array $body, array $allowed): array
    {
        if (array_is_list($body) || array_diff(array_keys($body), $allowed) !== []) {
            $this->invalid();
        }
        return $body;
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            $this->invalid();
        }
        return $value;
    }

    private function queryInt(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) $this->invalid();
        return (int) $parsed;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('idempotency-key');
        if (!is_string($key) || trim($key) === '' || strlen($key) > 128) {
            $this->invalid();
        }
        return trim($key);
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Managed external tool request is invalid');
    }
}
