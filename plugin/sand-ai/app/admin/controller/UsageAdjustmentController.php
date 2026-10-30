<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageRefundService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Financial adjustment entrypoints are restricted to authenticated platform superadmins. */
final class UsageAdjustmentController extends SandAiAdminController
{
    #[Permission('SandAI 记录退款调整', 'sand_ai:usage:adjustment:refund')]
    public function refund(Request $request): Response
    {
        $this->assertAdjustmentAdmin();
        return $this->respond(fn (): array => (new UsageRefundService())->refund(
            (array) $request->post(), $this->authenticatedAdminId(),
        ));
    }

    #[Permission('SandAI 退款调整查看', 'sand_ai:usage:adjustment:index')]
    public function index(Request $request): Response
    {
        $this->assertAdjustmentAdmin();
        return $this->respond(fn (): array => (new UsageRefundService())->index((array) $request->get()));
    }

    #[Permission('SandAI 退款调整查看', 'sand_ai:usage:adjustment:index')]
    public function read(Request $request): Response
    {
        $this->assertAdjustmentAdmin();
        return $this->respond(fn (): array => (new UsageRefundService())->read((array) $request->get()));
    }

    private function assertAdjustmentAdmin(): void
    {
        if ($this->authenticatedAdminId() === 1 || (int) ($this->authenticatedAdminInfo()['is_super'] ?? 0) === 1) {
            return;
        }
        throw new ApiException('退款调整仅限平台超级管理员', 403);
    }

    /** @param \Closure():array<string,mixed> $callback */
    private function respond(\Closure $callback): Response
    {
        try {
            return $this->success($callback());
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_USAGE_ADJUSTMENT_CONFLICT',
                'SAND_AI_USAGE_ADJUSTMENT_EXCEEDS_BILL' => 409,
                'SAND_AI_RESOURCE_NOT_FOUND' => 404,
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED' => 403,
                default => 400,
            };
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), $status);
        } catch (\Throwable) {
            throw new ApiException('SAND_AI_USAGE_ADJUSTMENT_FAILED: 退款调整未确认，请先按原请求键查找记录，勿更换请求键', 500);
        }
    }
}
