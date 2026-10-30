<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageSummaryCsvExporter;
use app\Application\Usage\UsageSummaryGateway;
use app\Application\Usage\UsageSummaryPolicy;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Platform administrator aggregation over sand_ai_usage_event only. */
final class UsageSummaryController extends BaseController
{
    #[Permission('SandAI 费用汇总', 'sand_ai:usage:summary')]
    public function summary(Request $request): Response
    {
        try {
            [$filters, $scope] = $this->scopedFilters($request);

            return $this->success((new UsageSummaryGateway())->summary($filters, $scope));
        } catch (ApiProblem $problem) {
            return $this->problemResponse($problem);
        }
    }

    #[Permission('SandAI 费用汇总导出', 'sand_ai:usage:summary')]
    public function export(Request $request): Response
    {
        try {
            [$filters, $scope] = $this->scopedFilters($request);
            $summary = (new UsageSummaryGateway())->summary($filters, $scope);

            return new Response(200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="sand-ai-usage-summary.csv"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], (new UsageSummaryCsvExporter())->export($summary));
        } catch (ApiProblem $problem) {
            return $this->problemResponse($problem);
        }
    }
    #[Permission('SandAI 费用允许环境', 'sand_ai:usage:summary')]
    public function scopes(Request $request): Response
    {
        try {
            if ($request->get() !== [] || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage scope directory accepts no query parameters or body');
            }
            return $this->success((new \plugin\SandAi\app\admin\logic\UsageAdminScopeLogic())->directory($request->header('check_admin', [])));
        } catch (ApiProblem $problem) {
            return $this->problemResponse($problem);
        }
    }

    /** @return array{0:array<string,int|string|null>,1:list<array<string,mixed>>} */
    private function scopedFilters(Request $request): array
    {
        if ($request->rawBody() !== '') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage summary accepts query parameters only');
        }
        $filters = (new UsageSummaryPolicy())->normalize($request->get());
        $scope = (new \plugin\SandAi\app\admin\logic\UsageAdminScopeLogic())->resolve($request->header('check_admin', []), $filters);
        return [$filters, $scope];
    }

    private function problemResponse(ApiProblem $problem): Response
    {
        $status = match ($problem->errorCode) {
            'SAND_AI_USAGE_SCOPE_DENIED', 'SAND_AI_USAGE_SCOPE_TOO_LARGE' => 403,
            'SAND_AI_USAGE_SCOPE_UNAVAILABLE' => 503,
            default => 400,
        };
        return json(['code' => $status, 'message' => $problem->errorCode . ': ' . $problem->getMessage()])->withStatus($status);
    }

}
