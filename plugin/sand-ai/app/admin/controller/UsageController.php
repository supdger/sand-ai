<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use plugin\SandAi\app\admin\logic\UsageCostLogic;
use plugin\SandAi\app\admin\logic\UsageLogic;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class UsageController extends BaseController
{
    #[Permission('SandAI 用量记录', 'sand_ai:usage:index')]
    public function index(Request $request): Response
    {
        try {
            if ($request->rawBody() !== '') throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage list accepts query parameters only');
            return $this->success((new UsageLogic())->index($request->get(), $request->header('check_admin', [])));
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_USAGE_SCOPE_DENIED', 'SAND_AI_USAGE_SCOPE_TOO_LARGE' => 403,
                'SAND_AI_USAGE_SCOPE_UNAVAILABLE' => 503,
                default => 400,
            };
            return json(['code' => $status, 'message' => $problem->errorCode . ': ' . $problem->getMessage()])->withStatus($status);
        }
    }

    #[Permission('SandAI 单次费用', 'sand_ai:usage:cost')]
    public function cost(Request $request): Response
    {
        try {
            if ($request->rawBody() !== '') throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage cost accepts query parameters only');
            return $this->success((new UsageCostLogic())->read($request->get(), $request->header('check_admin', [])));
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_RESOURCE_NOT_FOUND' => 404,
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'SAND_AI_USAGE_SCOPE_DENIED', 'SAND_AI_USAGE_SCOPE_TOO_LARGE' => 403,
                'SAND_AI_USAGE_SCOPE_UNAVAILABLE' => 503,
                default => 400,
            };
            return json([
                'code' => $status,
                'message' => $problem->errorCode . ': ' . $problem->getMessage(),
            ])->withStatus($status);
        }
    }

    #[Permission('SandAI 费用比较', 'sand_ai:usage:cost')]
    public function comparison(Request $request): Response
    {
        try {
            // Closed GET contract: a request body cannot override query facts.
            if ($request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Comparison accepts query parameters only');
            }
            return $this->success((new \plugin\SandAi\app\admin\logic\UsageComparisonLogic())->read($request->get(), $request->header('check_admin', [])));
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_RESOURCE_NOT_FOUND' => 404,
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'SAND_AI_USAGE_SCOPE_DENIED', 'SAND_AI_USAGE_SCOPE_TOO_LARGE' => 403,
                'SAND_AI_USAGE_SCOPE_UNAVAILABLE' => 503,
                default => 400,
            };
            return json([
                'code' => $status,
                'message' => $problem->errorCode . ': ' . $problem->getMessage(),
            ])->withStatus($status);
        }
    }
}
