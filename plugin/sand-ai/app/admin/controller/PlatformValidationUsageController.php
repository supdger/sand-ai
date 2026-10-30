<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Usage\PlatformValidationQuery;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Host-authenticated permissioned platform projection; no workload impersonation. */
final class PlatformValidationUsageController extends SandAiAdminController
{
    #[Permission('SandAI 模型验证费用', 'sand_ai:usage:index')]
    public function index(Request $request): Response
    {
        $this->authenticatedAdminId();
        return $this->respond(fn (): array => (new PlatformValidationQuery())->index($request->get()));
    }

    #[Permission('SandAI 模型验证费用汇总', 'sand_ai:usage:summary')]
    public function summary(Request $request): Response
    {
        $this->authenticatedAdminId();
        return $this->respond(fn (): array => (new PlatformValidationQuery())->summary($request->get()));
    }

    private function respond(\Closure $read): Response
    {
        try { return $this->success($read()); }
        catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_AUTHENTICATION_FAILED' => 401, 'SAND_AI_INVOCATION_FACTS_UNVERIFIED' => 409, default => 400,
            };
            return json(['code' => $status, 'message' => $problem->errorCode . ': ' . $problem->getMessage()])->withStatus($status);
        }
    }
}
