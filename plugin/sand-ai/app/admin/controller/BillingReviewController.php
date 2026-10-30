<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingEvidenceService;
use app\Application\Billing\BillingPositiveId;
use app\Application\Billing\BillingReviewService;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

final class BillingReviewController extends SandAiAdminController
{
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function evidenceUpload(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            if (array_keys($request->post()) !== ['provider_id']) {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Only provider_id and one file are accepted');
            }
            $uploads = $request->file();
            if (!is_array($uploads) || array_keys($uploads) !== ['file']) {
                throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Exactly one attachment is required');
            }
            $file = $request->file('file');
            if (!$file instanceof UploadFile) { throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Single file field required'); }
            return (new BillingEvidenceService())->upload($this->id($request->post('provider_id')), $file, (int) $this->authenticatedAdminId());
        });
    }
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function evidenceRead(Request $request): Response
    {
        try {
            $this->onlyId($request);
            $data = (new BillingEvidenceService())->download($this->id($request->get('id')));
            return response($data['body'])->withHeaders([
                'Content-Type' => $data['media_type'], 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'attachment; filename="billing-evidence.' . match ($data['media_type']) {
                    'application/pdf' => 'pdf',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    default => 'csv',
                } . '"',
            ]);
        } catch (ApiProblem $error) { return $this->problem($error); }
        catch (\Throwable) { return $this->failed(); }
    }
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function evidenceClear(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            $this->onlyId($request);
            return (new BillingEvidenceService())->clear($this->id($request->get('id')), (int) $this->authenticatedAdminId());
        });
    }
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function review(Request $request): Response
    {
        $input = (array) $request->post();
        $adminId = (int) $this->authenticatedAdminId();
        return $this->respond(fn (): array => (new \app\Application\Billing\BillingOperationReceipt())->local('review', $input, $adminId,
            fn (): array => (new BillingReviewService())->review($input, $adminId)));
    }
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function index(Request $request): Response
    {
        return $this->respond(function () use ($request): array {
            if (array_keys($request->get()) !== ['billing_line_id']) {
                throw new ApiProblem('SAND_AI_BILLING_REVIEW_INVALID', 'billing_line_id required');
            }
            return (new BillingReviewService(new \app\Application\Billing\PostgresBillingReviewStore($this->billingTrace($request))))->index($this->id($request->get('billing_line_id')));
        });
    }
    #[Permission('SandAI 人工账单审核', 'sand_ai:billing:review')]
    public function recover(Request $request): Response
    {
        return $this->respond(fn (): array => (new \app\Application\Billing\BillingOperationRecovery($this->billingTrace($request)))
            ->read('review', $request->get(), (int) $this->authenticatedAdminId()))->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function respond(callable $callback): Response
    {
        try { return $this->success($callback()); }
        catch (ApiProblem $error) { return $this->problem($error); }
        catch (\Throwable) { return $this->failed(); }
    }
    private function problem(ApiProblem $error): Response
    {
        $status = in_array($error->errorCode, ['SAND_AI_BILLING_REVIEW_CONFLICT','SAND_AI_BILLING_REVIEW_FINAL','SAND_AI_BILLING_RECOVERY_CONFLICT','SAND_AI_BILLING_OPERATION_REJECTED','SAND_AI_BILLING_OPERATION_UNKNOWN'], true) ? 409
            : ($error->errorCode === 'SAND_AI_RESOURCE_NOT_FOUND' ? 404 : 400);
        return json(['code' => $status, 'message' => $error->getMessage(),
            'data' => ['error_code' => $error->errorCode, 'retryable' => false]])->withStatus($status);
    }
    private function failed(): Response
    {
        return json(['code' => 500, 'message' => '账单审核未完成，请保留原请求重试',
            'data' => ['error_code' => 'SAND_AI_BILLING_REVIEW_FAILED', 'retryable' => false]])->withStatus(500);
    }
    private function onlyId(Request $request): void
    {
        if (array_keys($request->get()) !== ['id']) { throw new ApiProblem('SAND_AI_BILLING_EVIDENCE_INVALID', 'Only id is accepted'); }
    }
    private function id(mixed $value): int { return BillingPositiveId::parse($value,'id','SAND_AI_BILLING_REVIEW_INVALID'); }
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
