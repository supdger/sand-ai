<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingQueryPolicy;
use app\Application\Billing\BillingImportService;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Platform-only, vendor-neutral bill facts. Raw provider responses are never accepted or retained. */
final class BillingImportController extends SandAiAdminController
{
    #[Permission('SandAI 查看账单', 'sand_ai:billing:index')]
    public function index(Request $request): Response
    {
        try {
            $providerId = (new BillingQueryPolicy())->index($request->get());

            return $this->success((new BillingImportService())->index($providerId));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 导入账单', 'sand_ai:billing:import')]
    public function import(Request $request): Response
    {
        try {
            $input = (array) $request->post();
            $adminId = (int) $this->authenticatedAdminId();
            return $this->success((new \app\Application\Billing\BillingOperationReceipt())->local('import', $input, $adminId,
                fn (): array => (new BillingImportService())->import($input, $adminId)));
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode, ['SAND_AI_BILLING_FACT_CONFLICT','SAND_AI_BILLING_IDEMPOTENCY_CONFLICT','SAND_AI_BILLING_RECOVERY_CONFLICT','SAND_AI_BILLING_OPERATION_REJECTED','SAND_AI_BILLING_OPERATION_UNKNOWN'], true)) {
                return json(['code' => 409, 'message' => $problem->errorCode === 'SAND_AI_BILLING_FACT_CONFLICT' ? '账单事实内容冲突' : $problem->getMessage(), 'data' => ['error_code' => $problem->errorCode, 'retryable' => false]])->withStatus(409);
            }
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        } catch (\Throwable) {
            return json([
                'code' => 500,
                'message' => '账单导入未完成，请稍后重试',
                'data' => ['error_code' => 'SAND_AI_BILLING_IMPORT_FAILED', 'retryable' => false],
            ])->withStatus(500);
        }
    }

    #[Permission('SandAI 查看账单', 'sand_ai:billing:index')]
    public function read(Request $request): Response
    {
        try {
            $id = (new BillingQueryPolicy())->read($request->get());

            return $this->success((new BillingImportService(new \app\Application\Billing\PostgresBillingImportStore($this->billingTrace($request))))->read($id));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }
    #[Permission('SandAI 查看账单', 'sand_ai:billing:index')]
    public function recover(Request $request): Response
    {
        try {
            return $this->success((new \app\Application\Billing\BillingOperationRecovery($this->billingTrace($request)))
                ->read('import', $request->get(), (int) $this->authenticatedAdminId()))->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (ApiProblem $problem) {
            $status = $problem->errorCode === 'SAND_AI_BILLING_RECOVERY_CONFLICT' ? 409 : 400;
            return json(['code' => $status, 'message' => $problem->getMessage(),
                'data' => ['error_code' => $problem->errorCode, 'retryable' => false]])->withStatus($status)->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return json(['code' => 500, 'message' => 'Original billing operation could not be read',
                'data' => ['error_code' => 'SAND_AI_BILLING_RECOVERY_UNAVAILABLE', 'retryable' => true]])->withStatus(500);
        }
    }

    private function billingTrace(Request $request): \app\Application\Billing\BillingUsageTrace
    {
        try {
            $scope = (new \plugin\SandAi\app\admin\logic\UsageAdminScopeLogic())->resolve($request->header('check_admin', []), []);
        } catch (\Throwable) {
            // Optional trace unavailable: preserve the original bill facts.
            $scope = [];
        }
        return new \app\Application\Billing\BillingUsageTrace($scope);
    }

}
