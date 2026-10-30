<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingFetchRequestPolicy;
use app\Application\Billing\BillingImportService;
use app\Application\Billing\ProviderBillingSyncService;
use app\Infrastructure\Billing\ProviderBillingRuntimeFactory;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class BillingSyncController extends SandAiAdminController
{
    #[Permission('SandAI 同步账单', 'sand_ai:billing:sync')]
    public function sync(Request $request): Response
    {
        try {
            $input = (array) $request->post();
            $adminId = (int) $this->authenticatedAdminId();
            return $this->success((new \app\Application\Billing\BillingOperationReceipt())->sync($input, $adminId,
                static function (callable $start) use ($input, $adminId): array {
                    $data = (new BillingFetchRequestPolicy())->normalize($input);
                    $connector = (new ProviderBillingRuntimeFactory())->connectorFor((int) $data['provider_id']);
                    // The committed started marker precedes connector fetch; no transaction spans HTTP.
                    $start();
                    return (new ProviderBillingSyncService($connector, new BillingImportService()))->sync($data, $adminId);
                }));
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode, ['SAND_AI_BILLING_FACT_CONFLICT', 'SAND_AI_BILLING_RECOVERY_CONFLICT', 'SAND_AI_BILLING_OPERATION_REJECTED', 'SAND_AI_BILLING_OPERATION_UNKNOWN'], true)) {
                return json(['code' => 409, 'message' => $problem->errorCode === 'SAND_AI_BILLING_FACT_CONFLICT' ? '账单事实内容冲突' : $problem->getMessage(), 'data' => ['error_code' => $problem->errorCode, 'retryable' => false]])->withStatus(409);
            }
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 同步账单', 'sand_ai:billing:sync')]
    public function recover(Request $request): Response
    {
        try {
            // Credential resolution and connector creation are deliberately absent from this read.
            return $this->success((new \app\Application\Billing\BillingOperationRecovery())
                ->read('sync', $request->get(), (int) $this->authenticatedAdminId()))->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (ApiProblem $problem) {
            $status = $problem->errorCode === 'SAND_AI_BILLING_RECOVERY_CONFLICT' ? 409 : 400;
            return json(['code' => $status, 'message' => $problem->getMessage(),
                'data' => ['error_code' => $problem->errorCode, 'retryable' => false]])->withStatus($status)->withHeaders(['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return json(['code' => 500, 'message' => 'Original billing sync could not be read',
                'data' => ['error_code' => 'SAND_AI_BILLING_RECOVERY_UNAVAILABLE', 'retryable' => true]])->withStatus(500);
        }
    }
}
