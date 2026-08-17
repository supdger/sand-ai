<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\SandAi\app\admin\logic\CapabilityProfileLogic;
use plugin\SandAi\app\admin\validate\CapabilityProfileValidate;
use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\contract\EnvironmentReferenceVerifier;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Package-local management endpoints; SandIAM remains environment authority. */
final class CapabilityProfileController extends BaseController
{
    public function __construct(private readonly EnvironmentReferenceVerifier $environments)
    {
    }

    #[Permission('SandAI 能力配置元数据', 'sand_ai:setup:capability:metadata')]
    public function metadata(): Response
    {
        return $this->success($this->logic()->metadata());
    }

    #[Permission('SandAI 能力配置读取', 'sand_ai:setup:capability:read')]
    public function read(Request $request): Response
    {
        return $this->respond(fn (): array => ['profile' => $this->logic()->read((int) $request->input('environment_id', 0))]);
    }

    #[Permission('SandAI 能力配置草稿', 'sand_ai:setup:capability:save')]
    public function save(Request $request): Response
    {
        return $this->respond(fn (): array => ['profile' => $this->logic()->save($request->post())], '能力配置草稿已保存');
    }

    #[Permission('SandAI 发布能力配置', 'sand_ai:setup:capability:publish')]
    public function publish(Request $request): Response
    {
        return $this->respond(
            fn (): array => ['profile' => $this->logic()->publish((int) $request->post('environment_id', 0), (int) $request->post('id', 0))],
            '能力配置已发布',
        );
    }

    private function logic(): CapabilityProfileLogic
    {
        return new CapabilityProfileLogic(new CapabilityProfileValidate(), $this->environments);
    }

    /** @param callable(): array<string, mixed> $operation */
    private function respond(callable $operation, string $message = 'success'): Response
    {
        try {
            return $this->success($operation(), $message);
        } catch (IdentityContextException $exception) {
            throw new ApiException($exception->errorCode);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }
}
