<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use plugin\sandadmin\app\cache\UserInfoCache;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Response;

abstract class SandAiAdminController extends BaseController
{
    /**
     * Environment-level failures surface to administrators as one Chinese
     * sentence instead of a raw English error code. Other problems keep the
     * established "error_code: message" contract text. A $status of 0 keeps
     * the ApiException default (rendered as business code 500).
     */
    protected function adminProblemException(ApiProblem $problem, int $status = 0): ApiException
    {
        return new ApiException($this->adminProblemMessage($problem), $status);
    }

    /**
     * Same mapping for controllers that render the problem inside a JSON
     * payload instead of throwing an ApiException.
     */
    protected function adminProblemMessage(ApiProblem $problem): string
    {
        return $this->adminProblemOverride($problem)
            ?? $problem->errorCode . ': ' . $problem->getMessage();
    }

    /**
     * Chinese operator text for the mapped error codes, or null when the
     * problem keeps its own contract message.
     */
    protected function adminProblemOverride(ApiProblem $problem): ?string
    {
        if ($problem->errorCode === 'SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE') {
            return '系统安全初始化未完成，请重试或联系管理员';
        }

        return null;
    }

    /**
     * Problems that carry sanitized machine-readable details are rendered
     * inside the JSON envelope (business code 400) so the admin frontend can
     * branch on `data.reason_code` without parsing contract text.
     */
    protected function problemDetailsResponse(ApiProblem $problem): Response
    {
        return json([
            'code' => 400,
            'message' => $problem->errorCode . ': ' . $problem->getMessage(),
            'data' => $problem->details,
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function authenticatedAdminId(): int
    {
        $request = request();
        $checked = $request->header('check_admin');
        $current = getCurrentInfo();
        if ($request->header('check_login') !== true
            || !is_array($checked)
            || !is_array($current)
            || ($checked['plat'] ?? null) !== 'sandadmin'
            || ($current['plat'] ?? null) !== 'sandadmin') {
            throw new ApiException('管理员登录状态无效，请重新登录', 401);
        }

        $checkedId = $this->positiveAdminId($checked['id'] ?? null);
        $currentId = $this->positiveAdminId($current['id'] ?? null);
        if ($checkedId === null || $checkedId !== $currentId) {
            throw new ApiException('管理员登录状态无效，请重新登录', 401);
        }

        return $checkedId;
    }

    /** @return array<string,mixed> */
    protected function authenticatedAdminInfo(): array
    {
        return UserInfoCache::getUserInfo($this->authenticatedAdminId());
    }

    private function positiveAdminId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }
}
