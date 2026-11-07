<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\Provider;
use support\Request;
use Throwable;

/**
 * Administrator "试一试" channel (B2): published chat-route projection plus a
 * single-message chat that runs entirely through `ChatGateway::complete`, so
 * budget reservation, usage metering, billing, idempotency and receipts stay
 * centralized. The SandIAM side only ever sees a minimum-action short-lived
 * context for the application's managed workload client; nothing here grants,
 * provisions or widens IAM state.
 */
final class AdminTrialService
{
    public const DEFAULT_MAX_OUTPUT_TOKENS = 1024;
    private const MAX_MESSAGE_LENGTH = 32000;

    /** @param CapabilityProfileResolver|null $routes injected for tests */
    public function __construct(
        private readonly ?CapabilityProfileResolver $routes = null,
        private readonly ?AdminTrialIamGateway $iam = null,
    ) {
    }

    /**
     * GET /admin/trial/options projection. Only providers with at least one
     * published `chat` route in the application's managed environment appear;
     * names are the administrator-facing Chinese display names and ordering is
     * stable (route priority, then id). No provider endpoint is probed.
     *
     * @return array{application:array{id:int,name:string},providers:list<array{id:int,name:string,models:list<array{id:int,name:string}>,default_model_id:int}>,default_max_output_tokens:int}
     */
    public function options(int $applicationId, int $adminId, ?array $adminInfo): array
    {
        $iam = $this->iam ?? new AdminTrialIamGateway();
        $application = $iam->requireManageableApplication($applicationId, $adminId, $adminInfo);
        $binding = $iam->resolveManagedBinding($applicationId);
        $providers = $binding === null ? [] : $this->providerProjection((int) $binding['environment_id']);
        return [
            'application' => ['id' => $application['id'], 'name' => $application['name']],
            'providers' => $providers,
            'default_max_output_tokens' => self::DEFAULT_MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * POST /admin/trial/chat. Full chain: trusted application context → managed
     * binding → live grant recheck → published-route model validation →
     * minimum-action context issuance → `ChatGateway::complete`.
     *
     * @param array<string,mixed> $input validated request body fields
     * @return array<string,mixed>
     */
    public function chat(array $input, int $adminId, ?array $adminInfo, Request $request): array
    {
        AdminTrialRateLimiter::assertAllowed($adminId);
        // Cheap shape validation runs before any SandIAM lookup so malformed
        // requests fail fast without touching the identity plane.
        ['application_id' => $applicationId, 'provider_id' => $providerId, 'message' => $message,
            'model_id' => $modelId, 'max_output_tokens' => $maxOutputTokens] = self::normalizeChatInput($input);

        $iam = $this->iam ?? new AdminTrialIamGateway();
        $application = $iam->requireManageableApplication($applicationId, $adminId, $adminInfo);

        $binding = $iam->resolveManagedBinding($application['id']);
        if ($binding === null) {
            throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
        }
        $environmentId = (int) $binding['environment_id'];
        $workloadClientId = (int) $binding['workload_client_id'];
        if (!$iam->chatGrantActive($workloadClientId)) {
            throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
        }

        $selection = $this->selectRoute($environmentId, $providerId, $modelId);

        $requestId = $this->requestId($adminId);
        $identity = $iam->issueChatIdentity(
            $workloadClientId,
            $application['organization_id'],
            $application['id'],
            (string) $request->getRemoteIp(),
            $requestId,
            'admin_trial:' . $adminId,
        );
        $context = $identity['identity'];
        $idempotencyRequestId = RequestIdempotencyKey::required($request, 'chat', $context);

        // The administrator session header is never an invocation credential:
        // every attempt re-authorizes the issued workload context explicitly.
        $authorizeInvocation = self::trialAuthorizeCallback(
            new ChatInvocationAccessGuard(),
            'Bearer ' . $identity['context'],
            (string) $request->getRemoteIp(),
            $context,
            $requestId,
        );

        try {
            $result = (new ChatGateway())->complete(
                $environmentId,
                $selection['model_code'],
                [['role' => 'user', 'content' => trim($message)]],
                $idempotencyRequestId,
                UsageAttribution::fromIdentity($context, 'admin_trial', AdminTrialIamGateway::CHAT_ACTION),
                $maxOutputTokens,
                authorizePublicInvocation: $authorizeInvocation,
            );
        } catch (ApiProblem $problem) {
            throw $this->mapGatewayProblem($problem, $selection['provider_name']);
        }

        $invocation = is_array($result['invocation'] ?? null) ? $result['invocation'] : [];
        return [
            'application' => ['id' => $application['id'], 'name' => $application['name']],
            'provider' => ['id' => $selection['provider_id'], 'name' => $selection['provider_name']],
            'model' => ['id' => $selection['model_id'], 'name' => $selection['model_name']],
            'content' => is_string($result['content'] ?? null) ? $result['content'] : '',
            'latency_ms' => is_int($invocation['latency_ms'] ?? null) ? $invocation['latency_ms'] : 0,
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : null,
            'invocation_id' => is_int($result['invocation_id'] ?? null) ? $result['invocation_id'] : null,
            'idempotent_replay' => ($result['idempotent_replay'] ?? false) === true,
        ];
    }

    /**
     * Published `chat` routes grouped into the options projection.
     *
     * @return list<array{id:int,name:string,models:list<array{id:int,name:string}>,default_model_id:int}>
     */
    private function providerProjection(int $environmentId): array
    {
        $routes = $this->chatRoutes($environmentId);
        if ($routes === []) {
            return [];
        }
        $byProvider = [];
        foreach ($routes as $route) {
            $providerId = (int) $route['provider_id'];
            $modelId = (int) $route['model_id'];
            $priority = (int) $route['priority'];
            $byProvider[$providerId]['priority'] = min($byProvider[$providerId]['priority'] ?? PHP_INT_MAX, $priority);
            if (!isset($byProvider[$providerId]['models'][$modelId]) || $priority < $byProvider[$providerId]['models'][$modelId]) {
                $byProvider[$providerId]['models'][$modelId] = $priority;
            }
        }
        $providerNames = $this->names(Provider::class, array_keys($byProvider));
        $modelNames = $this->names(AiModel::class, self::projectedModelIds($byProvider));

        $providers = [];
        foreach ($byProvider as $providerId => $entry) {
            $models = [];
            foreach ($entry['models'] as $modelId => $priority) {
                $models[] = ['id' => $modelId, 'name' => $modelNames[$modelId] ?? '', 'priority' => $priority];
            }
            usort($models, static fn (array $a, array $b): int => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
            $providers[] = [
                'id' => $providerId,
                'name' => $providerNames[$providerId] ?? '',
                'priority' => $entry['priority'],
                'models' => array_map(static fn (array $model): array => ['id' => $model['id'], 'name' => $model['name']], $models),
                'default_model_id' => (int) $models[0]['id'],
            ];
        }
        usort($providers, static fn (array $a, array $b): int => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
        return array_map(static function (array $provider): array {
            unset($provider['priority']);
            return $provider;
        }, $providers);
    }

    /**
     * Resolve provider/model against the same published-route projection used
     * by options, so trial chat can never reach a route the options page hid.
     *
     * @return array{provider_id:int,provider_name:string,model_id:int,model_name:string,model_code:string}
     */
    private function selectRoute(int $environmentId, int $providerId, ?int $modelId): array
    {
        $routes = $this->chatRoutes($environmentId);
        $providerRoutes = array_values(array_filter(
            $routes,
            static fn (array $route): bool => (int) $route['provider_id'] === $providerId,
        ));
        if ($providerRoutes === []) {
            throw new ApiProblem('SAND_AI_TRIAL_MODEL_INVALID', '这个服务商当前没有可用的对话线路，请在服务商配置里检查后重试');
        }
        $modelIds = [];
        foreach ($providerRoutes as $route) {
            $modelIds[(int) $route['model_id']] = min($modelIds[(int) $route['model_id']] ?? PHP_INT_MAX, (int) $route['priority']);
        }
        if ($modelId !== null && !isset($modelIds[$modelId])) {
            throw new ApiProblem('SAND_AI_TRIAL_MODEL_INVALID', '所选模型不属于这个服务商的可用线路，请刷新后重试');
        }
        if ($modelId === null) {
            asort($modelIds);
            $modelId = (int) array_key_first($modelIds);
        }
        $provider = Provider::where('id', $providerId)->where('status', 1)->find();
        $model = AiModel::where('id', $modelId)->where('status', 1)->find();
        if ($provider === null || $model === null) {
            throw new ApiProblem('SAND_AI_TRIAL_MODEL_INVALID', '所选模型当前不可用，请刷新后重试');
        }
        return [
            'provider_id' => $providerId,
            'provider_name' => (string) $provider->name,
            'model_id' => $modelId,
            'model_name' => (string) $model->name,
            'model_code' => (string) $model->code,
        ];
    }

    /** @return list<array<string,mixed>> published chat routes for the environment */
    private function chatRoutes(int $environmentId): array
    {
        try {
            $routes = ($this->routes ?? new CapabilityProfileResolver())->routes($environmentId, 'chat');
        } catch (ApiProblem $problem) {
            throw new ApiProblem('SAND_AI_TRIAL_ROUTES_UNAVAILABLE', '暂时无法读取这个应用的可用线路，请稍后重试', false, ['cause' => $problem->errorCode]);
        } catch (Throwable) {
            throw new ApiProblem('SAND_AI_TRIAL_ROUTES_UNAVAILABLE', '暂时无法读取这个应用的可用线路，请稍后重试');
        }
        return is_array($routes) ? $routes : [];
    }

    /**
     * Pre-dispatch re-authorization bound to the issued workload context. The
     * signed context is passed explicitly (`Bearer <context>`) so the
     * administrator session header is never consulted; SandIAM still verifies
     * the signature, grant, quota, network policy and data class on every
     * attempt through `authorizeInvocation`.
     *
     * @return callable(int):void
     */
    public static function trialAuthorizeCallback(
        ChatInvocationAccessGuard $guard,
        string $signedContext,
        string $sourceIp,
        IdentityContext $identity,
        string $requestId,
    ): callable {
        return static function (int $invocationId) use ($guard, $signedContext, $sourceIp, $identity, $requestId): void {
            $guard->authorizeSigned($signedContext, $sourceIp, $identity, $invocationId, $requestId);
        };
    }

    /**
     * Flatten the per-provider published model id sets for the name lookup.
     * Route model ids are AiModel primary keys; array positions are never ids.
     *
     * @param array<int,array{models:array<int,int>}> $byProvider
     * @return list<int>
     */
    private static function projectedModelIds(array $byProvider): array
    {
        $ids = [];
        foreach ($byProvider as $entry) {
            foreach (array_keys($entry['models']) as $modelId) {
                $ids[] = $modelId;
            }
        }
        return $ids;
    }

    /** @param class-string $class @param list<int> $ids @return array<int,string> */
    private function names(string $class, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = $class::whereIn('id', $ids)->where('status', 1)->field('id,name')->select()->toArray();
        $names = [];
        foreach ($rows as $row) {
            $names[(int) ($row['id'] ?? 0)] = (string) ($row['name'] ?? '');
        }
        return $names;
    }

    /**
     * Pure request-body normalization shared by the controller path and tests.
     *
     * @param array<string,mixed> $input
     * @return array{application_id:int,provider_id:int,message:string,model_id:?int,max_output_tokens:mixed}
     */
    public static function normalizeChatInput(array $input): array
    {
        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ApiProblem('SAND_AI_TRIAL_MESSAGE_INVALID', '请输入要发送给 AI 的内容（不超过 32000 字）');
        }
        $modelId = array_key_exists('model_id', $input) && $input['model_id'] !== null
            ? self::positiveIdStatic($input['model_id'], 'model_id')
            : null;
        return [
            'application_id' => self::positiveIdStatic($input['application_id'] ?? null, 'application_id'),
            'provider_id' => self::positiveIdStatic($input['provider_id'] ?? null, 'provider_id'),
            'message' => trim($message),
            'model_id' => $modelId,
            'max_output_tokens' => ChatCompletionOptionsPolicy::normalize(
                array_key_exists('max_output_tokens', $input) ? $input['max_output_tokens'] : self::DEFAULT_MAX_OUTPUT_TOKENS,
            )->maxOutputTokens,
        ];
    }

    private static function positiveIdStatic(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            return (int) $value;
        }
        throw new ApiProblem('SAND_AI_TRIAL_VALIDATION_ERROR', '请求参数不完整或格式不正确，请刷新后重试', false, ['field' => $field]);
    }

    /** Provider/egress failures surface as safe Chinese copy, never raw codes. */
    private function mapGatewayProblem(ApiProblem $problem, string $providerName): ApiProblem
    {
        return match ($problem->errorCode) {
            'SAND_AI_IDEMPOTENCY_KEY_REQUIRED' => new ApiProblem(
                'SAND_AI_TRIAL_IDEMPOTENCY_REQUIRED', '缺少请求标识，请刷新页面后重试',
            ),
            'SAND_AI_PROVIDER_UNAVAILABLE', 'SAND_AI_PROVIDER_REJECTED', 'SAND_AI_PROVIDER_FAILURE',
            'SAND_AI_PROVIDER_TIMEOUT' => new ApiProblem(
                'SAND_AI_TRIAL_PROVIDER_UNREACHABLE',
                $providerName !== ''
                    ? "连不上{$providerName}：服务暂时没有响应，请稍后重试或检查服务商连接"
                    : '连不上所选服务商：服务暂时没有响应，请稍后重试或检查服务商连接',
                true,
            ),
            'SAND_AI_BUDGET_EXCEEDED', 'SAND_AI_BUDGET_INSUFFICIENT' => new ApiProblem(
                'SAND_AI_TRIAL_BUDGET_BLOCKED', '这个应用的 AI 额度不足或已超出预算，请联系管理员调整后再试',
            ),
            'SAND_AI_DATA_EGRESS_FORBIDDEN' => new ApiProblem(
                'SAND_AI_TRIAL_EGRESS_BLOCKED', '这个应用的 AI 数据策略不允许这次调用，请联系管理员调整后再试',
            ),
            'SAND_AI_CONFIGURATION_NOT_PUBLISHED' => new ApiProblem(
                'SAND_AI_TRIAL_NOT_PUBLISHED', '这个应用的 AI 线路还没有发布完成，请联系管理员检查配置后再试',
            ),
            // Gateway internals carry English diagnostics; the trial surface
            // only ever shows plain Chinese copy to the administrator.
            default => new ApiProblem(
                'SAND_AI_TRIAL_CHAT_FAILED', '这次试用没有成功，请稍后重试', $problem->retryable,
            ),
        };
    }

    private function requestId(int $adminId): string
    {
        return 'atrial-chat:' . $adminId . ':' . bin2hex(random_bytes(8));
    }
}
