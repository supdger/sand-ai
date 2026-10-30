<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Api\Support\ApiProblem;
use app\Application\Usage\PricingRevisionService;
use app\Application\Usage\PricingOperationReceipt;
use plugin\sandadmin\app\cache\UserAuthCache;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

/** Platform-only price-directory administration. No provider secret is exposed here. */
final class PricingRevisionController extends SandAiAdminController
{
    #[Permission('SandAI 价格目录', 'sand_ai:pricing:index')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $filters = [];
        foreach (['provider_id', 'model_id'] as $field) {
            $value = $request->input($field);
            $filters[$field] = $value === null || $value === '' ? null : (int) $value;
        }
        foreach (['capability_code', 'state'] as $field) {
            $value = trim((string) $request->input($field, ''));
            $filters[$field] = $value === '' ? null : $value;
        }

        return $this->success((new PricingRevisionService())->list($page, $limit, $filters));
    }

    #[Permission('SandAI 价格目录', 'sand_ai:pricing:index')]
    public function read(Request $request): Response
    {
        try {
            return $this->success((new PricingRevisionService())->read((int) $request->input('id', 0)));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    #[Permission('SandAI 保存价格草稿', 'sand_ai:pricing:save')]
    public function saveDraft(Request $request): Response
    {
        try {
            $admin = $this->authenticatedAdminId();
            $revision = (new PricingRevisionService())->saveDraft($request->post(), $admin, $request->header('Idempotency-Key'), $this->operationGuard($admin, 'save'));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
        return $this->success($revision, '价格草稿已保存');
    }

    #[Permission('SandAI 发布价格', 'sand_ai:pricing:publish')]
    public function publish(Request $request): Response
    {
        try {
            $admin = $this->authenticatedAdminId();
            $key = $request->header('Idempotency-Key');
            if ($key !== null && (array_keys($request->post()) !== ['id'] || !is_int($request->post('id')))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original publish input must contain one integer id');
            }
            $revision = (new PricingRevisionService())->publish((int) $request->post('id', 0), $admin, $key, $this->operationGuard($admin, 'publish'));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
        return $this->success($revision, '价格已发布');
    }
    #[Permission('SandAI 价格目录', 'sand_ai:pricing:index')]
    public function readOperationByRequestKey(Request $request): Response
    {
        try {
            $admin = $this->authenticatedAdminId();
            return $this->success((new PricingOperationReceipt())->read($request->get(), $admin,
                $this->operationGuard($admin, (string) $request->get('kind'), true)));
        } catch (ApiProblem $problem) {
            throw new ApiException($problem->errorCode . ': ' . $problem->getMessage());
        }
    }

    private function operationGuard(int $admin, string $kind, bool $read = false): \Closure
    {
        return function () use ($admin, $kind, $read): void {
            $permissions = UserAuthCache::getUserAuth($admin);
            $permission = $kind === 'publish' ? 'sand_ai:pricing:publish' : 'sand_ai:pricing:save';
            $super = $admin === 1 || (int) ($this->authenticatedAdminInfo()['is_super'] ?? 0) === 1;
            if ($this->authenticatedAdminId() !== $admin || (!$super
                && (!in_array($permission, $permissions, true) || ($read && !in_array('sand_ai:pricing:index', $permissions, true))))) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original price operation is not available to this administrator');
            }
        };
    }

}
