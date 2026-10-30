<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Workflow\MaterialAnalysisProfileGateway;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class MaterialProfileController extends SandAiAdminController
{
    #[Permission('SandAI 材料整理配置列表', 'sand_ai:setup:material-profile:read')]
    public function index(Request $request): Response
    {
        $query = $this->query($request, ['environment_id']);
        return $this->handle(static fn (): array => [
            'profiles' => (new MaterialAnalysisProfileGateway())->index(self::positiveId($query['environment_id'] ?? null)),
        ]);
    }

    #[Permission('SandAI 材料整理配置读取', 'sand_ai:setup:material-profile:read')]
    public function read(Request $request): Response
    {
        $query = $this->query($request, ['environment_id', 'code', 'version']);
        return $this->handle(static fn (): array => [
            'profile' => (new MaterialAnalysisProfileGateway())->read(
                self::positiveId($query['environment_id'] ?? null),
                is_string($query['code'] ?? null) ? $query['code'] : '',
                is_string($query['version'] ?? null) ? $query['version'] : '',
            ),
        ]);
    }

    #[Permission('SandAI 材料整理样本候选', 'sand_ai:setup:material-profile:read')]
    public function samples(Request $request): Response
    {
        $query = $this->query($request, ['environment_id', 'code', 'version', 'revision']);
        return $this->handle(static fn (): array => [
            'samples' => (new MaterialAnalysisProfileGateway())->samples(
                self::positiveId($query['environment_id'] ?? null),
                is_string($query['code'] ?? null) ? $query['code'] : '',
                is_string($query['version'] ?? null) ? $query['version'] : '',
                self::positiveId($query['revision'] ?? null),
            ),
        ]);
    }

    #[Permission('SandAI 保存材料整理配置草稿', 'sand_ai:setup:material-profile:save')]
    public function saveDraft(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input)) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 材料整理配置请求字段无效');
        }
        return $this->handle(fn (): array => [
            'profile' => (new MaterialAnalysisProfileGateway())->saveDraft($input, (string) $this->authenticatedAdminId()),
        ], '材料整理配置草稿已保存');
    }

    #[Permission('SandAI 发布材料整理配置', 'sand_ai:setup:material-profile:publish')]
    public function publish(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), ['environment_id', 'code', 'version', 'revision', 'sample_run_id']) !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 材料整理配置请求字段无效');
        }
        return $this->handle(fn (): array => [
            'profile' => (new MaterialAnalysisProfileGateway())->publish(
                self::positiveId($input['environment_id'] ?? null),
                is_string($input['code'] ?? null) ? $input['code'] : '',
                is_string($input['version'] ?? null) ? $input['version'] : '',
                self::positiveId($input['revision'] ?? null),
                self::positiveId($input['sample_run_id'] ?? null),
                (string) $this->authenticatedAdminId(),
            ),
        ], '材料整理配置已发布');
    }

    #[Permission('SandAI 撤销材料整理配置', 'sand_ai:setup:material-profile:revoke')]
    public function revoke(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), ['environment_id', 'code', 'version']) !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 材料整理配置请求字段无效');
        }
        return $this->handle(fn (): array => [
            'profile' => (new MaterialAnalysisProfileGateway())->revoke(
                self::positiveId($input['environment_id'] ?? null),
                is_string($input['code'] ?? null) ? $input['code'] : '',
                is_string($input['version'] ?? null) ? $input['version'] : '',
                (string) $this->authenticatedAdminId(),
            ),
        ], '材料整理配置已撤销');
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function query(Request $request, array $allowed): array
    {
        $query = $request->get();
        if (!is_array($query) || array_diff(array_keys($query), $allowed) !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 材料整理配置查询字段无效');
        }
        return $query;
    }

    private static function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '需要有效的环境标识');
        }
        return $id;
    }

    /** @param callable():array<string,mixed> $operation */
    private function handle(callable $operation, string $message = 'success'): Response
    {
        try {
            return $this->success($operation(), $message);
        } catch (ApiProblem $problem) {
            $code = in_array($problem->errorCode, ['SAND_AI_VALIDATION_ERROR', 'SAND_AI_SCHEMA_UNSUPPORTED'], true) ? 400 : 0;
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), $code);
        }
    }
}
