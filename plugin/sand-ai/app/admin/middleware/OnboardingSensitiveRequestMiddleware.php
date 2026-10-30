<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\middleware;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\OnboardingRequestSecrets;
use app\Infrastructure\Provider\OpenAiCompatibleExecutionConfigPolicy;
use app\Infrastructure\Provider\RequestScopedProviderConnection;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\exception\SystemException;
use Throwable;
use Webman\Context;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Must be the outermost middleware on all onboarding routes. It extracts and
 * redacts nested credentials before CheckLogin/CheckAuth or any exception path
 * can inspect request parameters. This route deliberately does not use
 * SandAdmin SystemLog because its operation event can serialize request data.
 */
final class OnboardingSensitiveRequestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // Workerman caches the original request after this handler returns.
        // Keep redaction on a copy, including the global request() accessor.
        $request = clone $request;
        Context::set(Request::class, $request);
        $handlerStarted = false;
        $payload = null;
        try {
            $payload = $this->payload($request);
            if (!is_array($payload)) {
                OnboardingRequestSecrets::redactRawBody($request);
                return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_VALIDATION_ERROR', '请求格式不正确，请使用 JSON 对象提交配置');
            }
            $billingCredentialWrite = $request->path() === '/app/sand-ai/admin/billing/credential/save';
            $providerWrite = in_array($request->path(), [
                '/app/sand-ai/admin/provider/save',
                '/app/sand-ai/admin/provider/update',
            ], true);
            if ($providerWrite) {
                if (array_key_exists('connection', $payload)
                    || array_key_exists('billing_admin_key', $payload)
                    || (array_key_exists('encrypted_config', $payload) && !is_array($payload['encrypted_config']))) {
                    OnboardingRequestSecrets::redactRawBody($request);
                    return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_VALIDATION_ERROR', '服务商配置格式不正确，请核对连接配置字段后重试');
                }
                if (array_key_exists('encrypted_config', $payload)) {
                    OnboardingRequestSecrets::store($request, $payload['encrypted_config']);
                }
            }
            $deploymentSave = $request->path() === '/app/sand-ai/admin/setup/deployment/save';
            if ($deploymentSave && array_diff(array_keys($payload), ['provider_id', 'provider', 'model_id', 'model', 'deployment_id', 'connection', 'remote_model', 'config']) !== []) {
                OnboardingRequestSecrets::redactRawBody($request);
                return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_VALIDATION_ERROR', '部署草稿包含不支持的字段，请核对模型配置请求');
            }
            $connection = $payload['connection'] ?? null;
            $billingAdminKey = $payload['billing_admin_key'] ?? null;
            if (array_key_exists('connection', $payload)) {
                if (is_array($connection)) {
                    if ($deploymentSave) {
                        OnboardingRequestSecrets::storeDeploymentConnection($request, $this->sealDeploymentConnection($connection));
                    } else {
                        OnboardingRequestSecrets::store($request, $connection);
                    }
                }
            }
            if (array_key_exists('billing_admin_key', $payload)) {
                if (is_string($billingAdminKey)) {
                    OnboardingRequestSecrets::storeBillingAdminKey($request, $billingAdminKey);
                }
            }
            if ($billingCredentialWrite && is_string($payload['billing_organization_id'] ?? null)) {
                OnboardingRequestSecrets::storeBillingOrganizationId($request, $payload['billing_organization_id']);
            }
            // Scrub every onboarding POST body, including malformed or future
            // routes that do not use the current connection field.
            $safePayload = OnboardingRequestSecrets::withoutConnection($payload);
            if ($billingCredentialWrite) {
                unset($safePayload['billing_organization_id']);
            }
            if ($providerWrite) {
                unset($safePayload['encrypted_config']);
            }
            if ($deploymentSave) {
                // This is middleware-originated metadata, not a client DTO.
                // It lets the controller preserve the strict new-versus-existing
                // Provider rule without retaining any connection field.
                $safePayload['_sand_ai_connection_submitted'] = array_key_exists('connection', $payload);
            }
            if (!OnboardingRequestSecrets::replacePost($request, $safePayload)
                || !OnboardingRequestSecrets::replaceRawBody($request, $safePayload)) {
                if (!OnboardingRequestSecrets::redactRawBody($request)) {
                    return $this->failure();
                }
                return $this->failure();
            }
            if (array_key_exists('connection', $payload) && !is_array($connection)) {
                return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_ONBOARDING_CONNECTION_INVALID', '请填写 HTTPS 服务地址和 API Key，连接信息须为对象');
            }
            if (array_key_exists('billing_admin_key', $payload) && !is_string($billingAdminKey)) {
                return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_VALIDATION_ERROR', '账单管理 Key 必须是字符串，请按服务商提供的值填写');
            }

            if ($billingCredentialWrite && array_key_exists('billing_organization_id', $payload) && !is_string($payload['billing_organization_id'])) {
                return $this->rejectBeforeHandler($request, $payload, 'SAND_AI_BILLING_VALIDATION_ERROR', '账单组织标识必须是字符串，请按服务商提供的值填写');
            }

            $handlerStarted = true;
            try {
                return $handler($request);
            } catch (ApiException $exception) {
                // SandAdmin's public business exception already carries the
                // safe local error contract. Preserve it after redaction so
                // callers can distinguish login, input and provider failures.
                return $exception->render($request) ?? $this->failure();
            } catch (SystemException $exception) {
                // The supported CheckAuth middleware uses this exact local
                // denial with its default 400. Do not expose other system
                // exception messages, which may contain infrastructure data.
                if ($exception->getCode() === 400 && $exception->getMessage() === '权限不足，无法访问或操作') {
                    return json(['code' => 403, 'message' => '当前后台账号没有执行此操作的权限']);
                }
                throw $exception;
            }
        } catch (ApiProblem $problem) {
            if (!$handlerStarted && in_array($problem->errorCode, [
                'SAND_AI_VALIDATION_ERROR', 'SAND_AI_ONBOARDING_CONNECTION_INVALID',
                'SAND_AI_ONBOARDING_HEADER_INVALID', 'SAND_AI_ONBOARDING_MODEL_INVALID',
            ], true)) {
                return $this->rejectBeforeHandler($request, $payload, $problem->errorCode, $problem->getMessage());
            }
            OnboardingRequestSecrets::redactRawBody($request);
            return $this->failure();
        } catch (Throwable) {
            OnboardingRequestSecrets::redactRawBody($request);
            return $this->failure();
        } finally {
            OnboardingRequestSecrets::clear($request);
        }
    }

    /** @return array<string, mixed>|null */
    private function payload(Request $request): ?array
    {
        $body = $request->rawBody();
        if ($body === '') {
            return [];
        }
        $contentType = strtolower((string) $request->header('content-type', ''));
        if (str_contains($contentType, 'json')) {
            try {
                $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            return is_array($payload) ? $payload : null;
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $payload);
            return is_array($payload) ? $payload : null;
        }

        return null;
    }

    /** No downstream handler or persistent save has run on these paths. */
    private function rejectBeforeHandler(Request $request, ?array $payload, string $errorCode, string $message): Response
    {
        $safePayload = is_array($payload) ? OnboardingRequestSecrets::withoutConnection($payload) : [];
        unset($safePayload['encrypted_config']);
        if ($request->path() === '/app/sand-ai/admin/billing/credential/save') {
            unset($safePayload['billing_organization_id']);
        }
        if (!OnboardingRequestSecrets::replacePost($request, $safePayload)
            || !OnboardingRequestSecrets::replaceRawBody($request, $safePayload)) {
            OnboardingRequestSecrets::redactRawBody($request);
            return $this->failure();
        }
        return $this->invalidInput($errorCode, $message);
    }

    private function invalidInput(string $errorCode, string $message): Response
    {
        return json([
            'code' => 400,
            'message' => $errorCode . ': ' . $message,
            'data' => ['error_code' => $errorCode, 'retryable' => false, 'submission_state' => 'not_submitted'],
        ]);
    }

    private function failure(): Response
    {
        return json([
            'code' => 500,
            'message' => '首次开通请求未完成，请检查输入后重试',
            'data' => ['error_code' => 'SAND_AI_ONBOARDING_REQUEST_FAILED', 'retryable' => false],
        ]);
    }

    /** @param array<string,mixed> $connection */
    private function sealDeploymentConnection(array $connection): RequestScopedProviderConnection
    {
        foreach (['base_url', 'api_key'] as $field) {
            $value = $connection[$field] ?? null;
            if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\p{Cc}/u', $value) === 1) {
                throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '请按原值填写 HTTPS 服务地址和 API Key，不能包含控制字符');
            }
        }
        // Only the pure input policy is translated. Missing encryption/HMAC
        // material during seal() remains an internal failure, never input400.
        try {
            (new OpenAiCompatibleExecutionConfigPolicy())->normalize($connection);
        } catch (ApiProblem $problem) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '连接配置格式不正确，请核对 HTTPS 服务地址、API Key 和额外请求头');
        }
        try {
            return RequestScopedProviderConnection::seal($connection);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'Unsupported deployment connection field') {
                throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_INVALID', '连接信息包含不支持的字段，请核对服务商连接配置');
            }
            throw $exception;
        }
    }
}
