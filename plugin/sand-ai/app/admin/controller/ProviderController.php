<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use app\Application\Onboarding\GuzzleOnboardingHttpTransport;
use app\Application\Onboarding\OnboardingHttpTransport;
use app\Application\Onboarding\OnboardingRequestSecrets;
use app\Application\Onboarding\OpenAiCompatibleOnboardingClient;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\ProviderGuideCatalog;
use app\Application\Onboarding\ProviderTestConnectionRequest;
use app\Application\Onboarding\SystemHostResolver;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Provider\ProviderSecretDecryptor;
use plugin\SandAi\app\admin\logic\ProviderLogic;
use app\Api\Support\ApiProblem;
use app\Application\Onboarding\CatalogOperationReceipt;
use plugin\sandadmin\app\cache\UserAuthCache;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Container;
use support\Request;
use support\Response;

final class ProviderController extends SandAiAdminController
{
    #[Permission('SandAI 服务商列表', 'sand_ai:provider:index')]
    public function index(Request $request): Response
    {
        return $this->success((new ProviderLogic())->index($request->get()));
    }

    #[Permission('SandAI 服务商读取', 'sand_ai:provider:read')]
    public function read(Request $request): Response
    {
        return $this->success((new ProviderLogic())->read($request->get() + $request->post()));
    }

    #[Permission('SandAI 服务商保存', 'sand_ai:provider:save')]
    public function save(Request $request): Response
    {
        $input = $request->post();
        $config = OnboardingRequestSecrets::take($request);
        if ($config !== null) {
            $input['encrypted_config'] = $config;
        }
        return $this->writeOperation('save', fn (int $admin, \Closure $guard): Response => $this->success(['id' => (new ProviderLogic())->create($input, $admin, $request->header('Idempotency-Key'), $guard)], '保存成功'));
    }

    #[Permission('SandAI 服务商更新', 'sand_ai:provider:update')]
    public function update(Request $request): Response
    {
        $input = $request->post() + $request->get();
        $config = OnboardingRequestSecrets::take($request);
        if ($config !== null) {
            $input['encrypted_config'] = $config;
        }
        return $this->writeOperation('update', function (int $admin, \Closure $guard) use ($request, $input): Response {
            (new ProviderLogic())->update($input, $admin, $request->header('Idempotency-Key'), $guard);
            return $this->success('更新成功');
        });
    }

    #[Permission('SandAI 服务商删除', 'sand_ai:provider:destroy')]
    public function destroy(Request $request): Response
    {
        (new ProviderLogic())->destroy($request->post());
        return $this->success('删除成功');
    }

    #[Permission('SandAI 服务商读取', 'sand_ai:provider:read')]
    public function guides(): Response
    {
        return $this->success(['guides' => ProviderGuideCatalog::all($this->debugMode())]);
    }

    /**
     * Probes a provider connection without persisting anything. Two mutually
     * exclusive forms: a fresh `connection` moved to the request vault by the
     * sensitive-request guard, or `provider_id` decrypted server-side.
     */
    #[Permission('SandAI 服务商读取', 'sand_ai:provider:read')]
    public function testConnection(Request $request): Response
    {
        try {
            $input = $request->post();
            $source = ProviderTestConnectionRequest::resolve(
                is_array($input) ? $input : [],
                OnboardingRequestSecrets::take($request),
            );
            $connection = $source['connection'] ?? $this->savedConnection((int) $source['provider_id']);
            $models = $this->onboardingClient()->discover($connection);
        } catch (ApiProblem $problem) {
            if ($problem->details !== []) {
                return $this->problemDetailsResponse($problem);
            }
            throw $this->testConnectionProblem($problem);
        }

        return $this->success(['ok' => true, 'models' => $models], '连接成功');
    }

    /** @return array<string, mixed> */
    private function savedConnection(int $providerId): array
    {
        $provider = Provider::findOrEmpty($providerId);
        if ($provider->isEmpty()) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '服务商不存在，请从列表重新选择');
        }
        $envelope = $provider->getAttr('encrypted_config');
        if (!is_array($envelope)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', '该服务商尚未保存连接配置，请填写接口地址和 API Key 后测试');
        }

        return (new ProviderSecretDecryptor())->decrypt($envelope);
    }

    /** Test-connection problems are reported with the same "code: message" contract at 400. */
    private function testConnectionProblem(ApiProblem $problem): ApiException
    {
        return new ApiException($problem->errorCode . ': ' . $problem->getMessage(), 400);
    }

    private function onboardingClient(): OpenAiCompatibleOnboardingClient
    {
        return new OpenAiCompatibleOnboardingClient(
            new ProviderConnectionPolicy(new SystemHostResolver()),
            Container::has(OnboardingHttpTransport::class)
                ? Container::get(OnboardingHttpTransport::class)
                : new GuzzleOnboardingHttpTransport(),
        );
    }

    private function debugMode(): bool
    {
        return filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
    }

    #[Permission('SandAI 服务商读取', 'sand_ai:provider:read')]
    public function readOperationByRequestKey(Request $request): Response
    {
        $operation = (string) $request->get('operation', '');
        return $this->writeOperation($operation, fn (int $admin, \Closure $guard): Response => $this->success(
            (new CatalogOperationReceipt())->read('provider', $request->get(), $admin, $guard),
        ), true);
    }

    private function writeOperation(string $operation, \Closure $callback, bool $read = false): Response
    {
        try {
            $admin = $this->authenticatedAdminId();
            $guard = function () use ($admin, $operation, $read): void {
                $permissions = UserAuthCache::getUserAuth($admin);
                $super = $admin === 1 || (int) ($this->authenticatedAdminInfo()['is_super'] ?? 0) === 1;
                if (!in_array($operation, ['save','update'], true) || $this->authenticatedAdminId() !== $admin
                    || (!$super && (!in_array('sand_ai:provider:' . $operation, $permissions, true)
                        || ($read && !in_array('sand_ai:provider:read', $permissions, true))))) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original catalogue operation is not available');
                }
            };
            $guard();
            return $callback($admin, $guard);
        } catch (ApiProblem $problem) {
            $status = in_array($problem->errorCode, ['SAND_AI_IDEMPOTENCY_CONFLICT','SAND_AI_RESOURCE_CONFLICT'], true) ? 409 : 400;
            throw $this->adminProblemException($problem, $status);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ApiException('SAND_AI_RESOURCE_CONFLICT: 原目录操作结果未确认，请保留原请求号只读找回', 500);
        }
    }

}
