<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Budget\BudgetPolicyValidator;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Capability\CapabilityDriverRegistry;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use plugin\sandadmin\app\cache\UserAuthCache;
use support\Request;
use support\Response;

final class BudgetPolicyController extends SandAiAdminController
{
    #[Permission('SandAI 预算查看', 'sand_ai:budget:index')]
    public function index(Request $request): Response
    {
        $this->assertBudgetAdmin();
        $environmentId = $this->optionalPositiveInt($request->input('environment_id'));
        $state = trim((string) $request->input('state', '')) ?: null;

        return $this->guard(fn (): array => (new BudgetPolicyService())->list(
            max(1, (int) $request->input('page', 1)),
            min(100, max(1, (int) $request->input('limit', 20))),
            $environmentId,
            $state,
            $request->input('scope_kind') === null ? null : (string) $request->input('scope_kind'),
            $request->input('scope_ref') === null ? null : (string) $request->input('scope_ref'),
        ));
    }

    #[Permission('SandAI 预算查看', 'sand_ai:budget:index')]
    public function read(Request $request): Response
    {
        $this->assertBudgetAdmin();
        return $this->guard(fn (): array => (new BudgetPolicyService())->read((int) $request->input('id', 0)));
    }

    #[Permission('SandAI 保存预算草稿', 'sand_ai:budget:save')]
    public function saveDraft(Request $request): Response
    {
        $this->assertBudgetAdmin();
        try {
            $adminId = $this->authenticatedAdminId();
            $header = $request->header('idempotency-key');
            $key = $header === null ? null : (new BudgetPolicyValidator())->normalizeRequestKey($header);
            $policy = (new BudgetPolicyService())->saveDraft($request->post(), (string) $adminId, $key, $this->draftAdminGuard($adminId));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
        return $this->success($policy, '预算草稿已保存');
    }

    #[Permission('SandAI 找回预算草稿保存', 'sand_ai:budget:save')]
    public function readDraftByRequestKey(Request $request): Response
    {
        $this->assertBudgetAdmin();
        try {
            if ($request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Draft lookup must have no request body');
            }
            $adminId = $this->authenticatedAdminId();
            $result = (new BudgetPolicyService())->readDraftByRequestKey(
                $request->get(), (string) $adminId, $this->draftAdminGuard($adminId)
            );
            return $this->success($result, '已找回原预算草稿保存结果')->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    private function draftAdminGuard(int $adminId): \Closure
    {
        return function () use ($adminId): void {
            $this->assertBudgetAdmin();
            if ($this->authenticatedAdminId() !== $adminId || ($adminId !== 1
                && !in_array('sand_ai:budget:save', UserAuthCache::getUserAuth($adminId), true))) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'The original budget draft save could not be confirmed');
            }
        };
    }

    #[Permission('SandAI 发布预算策略', 'sand_ai:budget:publish')]
    public function publish(Request $request): Response
    {
        $this->assertBudgetAdmin();
        $id = $this->optionalPositiveInt($request->post('id'));
        if ($id === null) {
            throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: Budget policy id is required');
        }
        try {
            $body = $request->post();
            if (array_key_exists('expected_policy', $body) && !is_array($body['expected_policy'])) {
                throw new ApiProblem('SAND_AI_BUDGET_VALIDATION_ERROR', 'Expected policy must be a complete configuration object');
            }
            $policy = (new BudgetPolicyService())->publish(
                $id, (string) $this->authenticatedAdminId(),
                $this->optionalPositiveInt($body['reference_environment_id'] ?? null),
                $body['expected_policy'] ?? null,
            );
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
        return $this->success($policy, '预算策略已发布');
    }

    #[Permission('SandAI 归档预算策略', 'sand_ai:budget:publish')]
    public function archive(Request $request): Response
    {
        $this->assertBudgetAdmin();
        $body = $request->post();
        if (array_diff(array_keys($body), ['id', 'reference_environment_id']) !== []) {
            throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: Unsupported archive field');
        }
        foreach (['id', 'reference_environment_id'] as $field) {
            $value = $body[$field] ?? null;
            if ($value !== null && !is_int($value) && !is_string($value)) {
                throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: Archive identifiers must be positive integers');
            }
        }
        $id = $this->optionalPositiveInt($body['id'] ?? null);
        if ($id === null) {
            throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: Budget policy id is required');
        }
        return $this->guard(fn (): array => (new BudgetPolicyService())->archive(
            $id, (string) $this->authenticatedAdminId(),
            $this->optionalPositiveInt($body['reference_environment_id'] ?? null),
        ), '预算策略已归档，已发送调用继续结算');
    }

    #[Permission('SandAI 预算查看', 'sand_ai:budget:index')]
    public function summary(Request $request): Response
    {
        $this->assertBudgetAdmin();
        return $this->guard(fn (): array => (new BudgetPolicyService())->window((int) $request->input('policy_id', 0)));
    }

    #[Permission('SandAI 预算查看', 'sand_ai:budget:index')]
    public function active(Request $request): Response
    {
        $this->assertBudgetAdmin();
        return $this->guard(function () use ($request): array {
            $scope = (new BudgetPolicyValidator())->normalizeScope($request->get());
            return (new BudgetPolicyService())->activeScope($scope['scope_kind'], $scope['scope_ref']);
        });
    }

    #[Permission('SandAI 预算查看', 'sand_ai:budget:index')]
    public function references(Request $request): Response
    {
        $this->assertBudgetAdmin();
        $capabilities = [];
        $labels = ['chat' => '聊天', 'structured_extraction' => '结构化抽取', 'embedding' => '向量化', 'rerank' => '重排',
            'vision' => '视觉理解', 'document_parse' => '材料解析', 'image_ocr' => '图片识别', 'audio_transcription' => '音频转写',
            'audio_speech' => '语音生成', 'external_readonly' => '外部只读工具'];
        foreach (CapabilityDriverRegistry::metadata() + ['external_readonly' => ['description' => '外部只读工具']] as $code => $metadata) {
            $capabilities[] = ['code' => $code, 'name' => $labels[$code] ?? $metadata['description']];
        }
        $models = AiModel::where('status', 1)->field('id,code,name')->order('id')->paginate([
            'page' => max(1, (int) $request->input('page', 1)), 'list_rows' => 100,
        ])->toArray();
        return $this->success(['models' => $models, 'capabilities' => $capabilities]);
    }

    private function assertBudgetAdmin(): void
    {
        if ($this->authenticatedAdminId() === 1 || (int) ($this->authenticatedAdminInfo()['is_super'] ?? 0) === 1) return;
        throw new ApiException('当前预算管理仅限平台超级管理员', 403);
    }

    /** @param callable():array<string,mixed> $callback */
    private function guard(callable $callback, string $message = 'success'): Response
    {
        try {
            return $this->success($callback(), $message);
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !is_string($value)) {
            throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: Identifier must be a positive integer');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiException('SAND_AI_BUDGET_VALIDATION_ERROR: environment_id must be a positive integer');
        }

        return $id;
    }

}
