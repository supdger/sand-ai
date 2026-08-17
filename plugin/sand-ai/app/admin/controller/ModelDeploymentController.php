<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\ModelDeploymentLogic;
use plugin\SandAi\app\admin\validate\ModelDeploymentValidate;
use plugin\SandAi\app\api\support\ApiProblem;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ModelDeploymentController extends BaseController
{
    #[Permission('SandAI 创建部署草稿', 'sand_ai:setup:deployment:save')]
    public function save(Request $request): Response
    {
        return $this->respond(fn (): array => $this->logic()->draft($request->post()), '草稿已保存');
    }

    #[Permission('SandAI 发布部署', 'sand_ai:setup:deployment:publish')]
    public function publish(Request $request): Response
    {
        return $this->respond(fn (): array => $this->logic()->publish($request->post()), '发布成功');
    }

    private function logic(): ModelDeploymentLogic
    {
        return new ModelDeploymentLogic(new ModelDeploymentValidate());
    }

    /** @param callable(): array<string, mixed> $operation */
    private function respond(callable $operation, string $message): Response
    {
        try {
            return $this->success($operation(), $message);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }
}
