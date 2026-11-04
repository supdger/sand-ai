<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Trial\AdminTrialContextService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/**
 * Administrator trial channel. Lives only inside the /app/sand-ai/admin group
 * (CheckLogin + CheckAuth + sand_ai:trial:context); it never appears on the
 * public /api/sand-ai/v1 surface and never accepts or returns a workload
 * credential — the response carries only the signed short-lived context.
 */
final class AdminTrialController extends SandAiAdminController
{
    #[Permission('SandAI 管理员试用身份', 'sand_ai:trial:context')]
    public function context(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), ['actions']) !== []) {
            throw new ApiException('SAND_AI_VALIDATION_ERROR: 试用身份请求只接受试用能力列表，请删除不支持的字段', 400);
        }
        $adminId = $this->authenticatedAdminId();
        try {
            $result = (new AdminTrialContextService())->issueContext(
                $input['actions'] ?? null,
                $adminId,
                (string) $request->getRemoteIp(),
                $this->trialRequestId($request, $adminId),
            );
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
        }

        return $this->success($result)->header('Cache-Control', 'no-store');
    }

    /**
     * Each issuance needs a fresh correlation id: SandIAM keys audit rows by
     * (request_id, action), so a replayed client header must not collide.
     */
    private function trialRequestId(Request $request, int $adminId): string
    {
        $header = trim((string) $request->header('X-Request-Id', ''));
        $base = preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,60}$/', $header) === 1
            ? $header
            : 'atrial';
        return substr($base, 0, 60) . ':' . $adminId . ':' . bin2hex(random_bytes(8));
    }
}
