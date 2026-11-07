<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Trial\AdminTrialContextService;
use app\Application\Trial\AdminTrialService;
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
     * B2 "试一试": published chat-route projection for one manageable
     * application. Read-only; requires sand_ai:trial:options.
     */
    #[Permission('SandAI 试用服务商选项', 'sand_ai:trial:options')]
    public function options(Request $request): Response
    {
        $rawApplicationId = $request->get('application_id');
        $applicationId = $this->trialApplicationId(is_scalar($rawApplicationId) ? (string) $rawApplicationId : '');
        try {
            $result = (new AdminTrialService())->options(
                $applicationId,
                $this->authenticatedAdminId(),
                $this->adminInfo,
            );
        } catch (ApiProblem $problem) {
            throw $this->trialProblem($problem);
        }

        return $this->success($result)->header('Cache-Control', 'no-store');
    }

    /**
     * B2 "试一试": one-message chat through the managed workload binding and
     * the full ChatGateway chain. Requires sand_ai:trial:chat plus a fresh
     * Idempotency-Key; never provisions grants.
     */
    #[Permission('SandAI 试用对话', 'sand_ai:trial:chat')]
    public function chat(Request $request): Response
    {
        $input = $request->post();
        if (!is_array($input) || array_diff(array_keys($input), ['application_id', 'provider_id', 'message', 'model_id', 'max_output_tokens']) !== []) {
            throw new ApiException('请求包含不支持的字段，请刷新后重试', 400);
        }
        try {
            $result = (new AdminTrialService())->chat(
                $input,
                $this->authenticatedAdminId(),
                $this->adminInfo,
                $request,
            );
        } catch (ApiProblem $problem) {
            throw $this->trialProblem($problem);
        }

        return $this->success($result)->header('Cache-Control', 'no-store');
    }

    private function trialApplicationId(string $raw): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', trim($raw)) !== 1) {
            throw new ApiException('请先选择一个应用', 400);
        }
        return (int) trim($raw);
    }

    /** Trial endpoints answer with plain Chinese copy; codes stay internal. */
    private function trialProblem(ApiProblem $problem): ApiException
    {
        $status = match ($problem->errorCode) {
            'SAND_AI_TRIAL_APPLICATION_NOT_FOUND' => 404,
            'SAND_AI_TRIAL_APPLICATION_FORBIDDEN',
            'SAND_AI_TRIAL_NOT_GRANTED' => 403,
            'SAND_AI_TRIAL_BINDING_AMBIGUOUS' => 409,
            'SAND_AI_TRIAL_RATE_LIMITED' => 429,
            'SAND_AI_TRIAL_IAM_UNAVAILABLE',
            'SAND_AI_TRIAL_ISSUE_FAILED',
            'SAND_AI_TRIAL_ROUTES_UNAVAILABLE' => 503,
            'SAND_AI_TRIAL_PROVIDER_UNREACHABLE' => 502,
            default => 400,
        };
        $message = trim($problem->getMessage());
        return new ApiException($message !== '' ? $message : '试用请求未完成，请稍后重试', $status);
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
