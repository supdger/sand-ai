<?php
declare(strict_types=1);
namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingCredentialService;
use app\Application\Onboarding\OnboardingRequestSecrets;
use app\Infrastructure\Billing\ProviderConfigCipher;
use app\Infrastructure\Billing\ProviderModelBillingCredentialStore;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class BillingCredentialController extends SandAiAdminController
{
    private function service(): BillingCredentialService
    {
        return new BillingCredentialService(new ProviderModelBillingCredentialStore(), new ProviderConfigCipher());
    }

    #[Permission('SandAI 账单凭证', 'sand_ai:billing:credential')]
    public function save(Request $request): Response
    {
        try {
            $input = (array) $request->post();
            $billingAdminKey = OnboardingRequestSecrets::billingAdminKey($request);
            if ($billingAdminKey !== null) {
                $input['billing_admin_key'] = $billingAdminKey;
            }

            $organizationId = OnboardingRequestSecrets::billingOrganizationId($request);
            if ($organizationId !== null) {
                $input['billing_organization_id'] = $organizationId;
            }

            return $this->success($this->service()->save($input, (int) $this->authenticatedAdminId(), (string) $request->header('Idempotency-Key', '')));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        } catch (\Throwable) {
            return json([
                'code' => 500,
                'message' => '账单凭证保存结果尚未确认，请保留原请求号并只读找回',
                'data' => ['error_code' => 'SAND_AI_BILLING_CREDENTIAL_SAVE_FAILED', 'retryable' => false],
            ])->withStatus(500);
        }
    }

    #[Permission('SandAI 账单凭证', 'sand_ai:billing:credential')]
    public function recover(Request $request): Response
    {
        try {
            return $this->success($this->service()->readSavedByRequestKey((array) $request->get(), (int) $this->authenticatedAdminId()));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        } catch (\Throwable) {
            return json([
                'code' => 500,
                'message' => '原账单凭证保存结果暂时无法确认，请保留原请求号后再次读取',
                'data' => ['error_code' => 'SAND_AI_BILLING_CREDENTIAL_READ_FAILED', 'retryable' => false],
            ])->withStatus(500);
        }
    }

    #[Permission('SandAI 账单凭证', 'sand_ai:billing:credential')]
    public function status(Request $request): Response
    {
        try {
            return $this->success($this->service()->status((int) $request->get('provider_id')));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }
}
