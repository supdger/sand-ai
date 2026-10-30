<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Legal\LegalAnalysisProfileGateway;
use plugin\sandadmin\app\cache\UserAuthCache;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class LegalProfileController extends SandAiAdminController
{
    #[Permission('SandAI 法律分析配置模板', 'sand_ai:setup:legal-profile:read')]
    public function templates(): Response
    {
        return $this->handle(static fn (): array => [
            'profiles' => (new LegalAnalysisProfileGateway())->templates(),
        ]);
    }

    #[Permission('SandAI 法律分析配置列表', 'sand_ai:setup:legal-profile:read')]
    public function index(Request $request): Response
    {
        return $this->handle(fn (): array => [
            'profiles' => (new LegalAnalysisProfileGateway())->index(
                self::positiveId($this->query($request, ['environment_id'])['environment_id'] ?? null),
            ),
        ]);
    }

    #[Permission('SandAI 法律分析配置读取', 'sand_ai:setup:legal-profile:read')]
    public function read(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            $query = $this->query($request, ['environment_id', 'code', 'version']);
            return ['profile' => (new LegalAnalysisProfileGateway())->read(
                self::positiveId($query['environment_id'] ?? null),
                is_string($query['code'] ?? null) ? $query['code'] : '',
                is_string($query['version'] ?? null) ? $query['version'] : '',
            )];
        });
    }

    #[Permission('SandAI 保存法律分析配置草稿', 'sand_ai:setup:legal-profile:save')]
    public function saveDraft(Request $request): Response
    {
        return $this->handle(fn (): array => [
            'profile' => (new LegalAnalysisProfileGateway())->saveDraft($this->body($request), (string) $this->authenticatedAdminId(), (string) $request->header('Idempotency-Key', ''), $this->operationPermission('draft_save')),
        ], '法律分析配置草稿已保存');
    }

    #[Permission('SandAI 发布法律分析配置', 'sand_ai:setup:legal-profile:publish')]
    public function publish(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'code', 'version', 'revision']);
            return ['profile' => (new LegalAnalysisProfileGateway())->publish(
                self::positiveId($body['environment_id'] ?? null),
                is_string($body['code'] ?? null) ? $body['code'] : '',
                is_string($body['version'] ?? null) ? $body['version'] : '',
                self::positiveId($body['revision'] ?? null), (string) $this->authenticatedAdminId(),
                (string) $request->header('Idempotency-Key', ''), $this->operationPermission('publish'),
            )];
        }, '法律分析配置已发布');
    }

    #[Permission('SandAI 撤销法律分析配置', 'sand_ai:setup:legal-profile:revoke')]
    public function revoke(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            $body = $this->body($request, ['environment_id', 'code', 'version']);
            return ['profile' => (new LegalAnalysisProfileGateway())->revoke(
                self::positiveId($body['environment_id'] ?? null),
                is_string($body['code'] ?? null) ? $body['code'] : '',
                is_string($body['version'] ?? null) ? $body['version'] : '',
                (string) $this->authenticatedAdminId(),
                (string) $request->header('Idempotency-Key', ''), $this->operationPermission('revoke'),
            )];
        }, '法律分析配置已撤销');
    }

    #[Permission('SandAI 读取法律配置原操作', 'sand_ai:setup:legal-profile:read')]
    public function readOperation(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            if ($request->rawBody() !== '') throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '原法律配置读取不接受正文');
            $query = $this->query($request, ['operation','environment_id','code','version','request_key','expected_revision']);
            $operation = is_string($query['operation'] ?? null) ? $query['operation'] : '';
            return (new LegalAnalysisProfileGateway())->readOperation($query, (string) $this->authenticatedAdminId(), $this->operationPermission($operation, true));
        })->header('Cache-Control', 'no-store');
    }

    private function operationPermission(string $operation, bool $read = false): \Closure
    {
        $admin = $this->authenticatedAdminId();
        return function () use ($admin, $operation, $read): void {
            $action = ['draft_save' => 'save', 'publish' => 'publish', 'revoke' => 'revoke'][$operation] ?? null;
            $permissions = UserAuthCache::getUserAuth($admin);
            $super = $admin === 1 || (int) ($this->authenticatedAdminInfo()['is_super'] ?? 0) === 1;
            if ($action === null || $this->authenticatedAdminId() !== $admin
                || (!$super && (!in_array('sand_ai:setup:legal-profile:' . $action, $permissions, true)
                    || ($read && !in_array('sand_ai:setup:legal-profile:read', $permissions, true))))) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '原法律配置操作当前不可读取或执行');
            }
        };
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function query(Request $request, array $allowed): array
    {
        $query = $request->get();
        if (!is_array($query) || array_diff(array_keys($query), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析配置查询字段无效');
        }
        return $query;
    }

    /** @param list<string>|null $allowed @return array<string,mixed> */
    private function body(Request $request, ?array $allowed = null): array
    {
        $body = $request->post();
        if (!is_array($body) || ($allowed !== null && array_diff(array_keys($body), $allowed) !== [])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析配置请求字段无效');
        }
        return $body;
    }

    private static function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '需要有效的环境标识或修订号');
        }
        return (int) $id;
    }

    /** @param callable():array<string,mixed> $operation */
    private function handle(callable $operation, string $message = 'success'): Response
    {
        try {
            return $this->success($operation(), $message);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiException('SAND_AI_RESOURCE_CONFLICT: 法律配置操作结果未确认，请保留原请求号只读找回', 500);
        }
    }
}
