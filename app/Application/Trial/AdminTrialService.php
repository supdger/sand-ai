<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentDefinitionPolicy;
use app\Application\Agent\AgentGateway;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Application\Capability\CapabilityProfileResolver;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Knowledge\KnowledgeBaseQueryGateway;
use app\Application\Knowledge\KnowledgeEmbeddingIndexGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Knowledge\KnowledgeQueryEmbeddingGateway;
use app\Application\Task\TaskGateway;
use app\Application\Task\TaskState;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\ChatInvocationAccessGuard;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\KnowledgeBase;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Provider\ChatProviderFailurePolicy;
use support\Request;
use think\facade\Db;
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

    /**
     * Error codes that represent an authorization denial worth a SandAI-side
     * audit row. SandIAM owns the IAM audit log; this row lets SandAI
     * operators reconcile denied trial probes (cross-tenant application ids,
     * revoked or missing grants) without parsing HTTP logs. Availability
     * failures stay unaudited — they are not authorization decisions.
     */
    private const DENIED_AUDIT_CODES = [
        'SAND_AI_TRIAL_APPLICATION_NOT_FOUND' => true,
        'SAND_AI_TRIAL_APPLICATION_FORBIDDEN' => true,
        'SAND_AI_TRIAL_NOT_GRANTED' => true,
        'SAND_AI_TRIAL_BINDING_AMBIGUOUS' => true,
        'SAND_AI_TRIAL_ISSUE_NETWORK_FORBIDDEN' => true,
    ];

    /** @param CapabilityProfileResolver|null $routes injected for tests
     * @param AdminTrialIamGateway|null $iam injected for tests
     * @param callable(array<string,mixed>):void|null $deniedAudit SandAI-side
     *        denial audit sink; production writes sand_ai_audit_log */
    public function __construct(
        private readonly ?CapabilityProfileResolver $routes = null,
        private readonly ?AdminTrialIamGateway $iam = null,
        private readonly mixed $deniedAudit = null,
    ) {
    }

    /**
     * GET /admin/trial/options projection. Applies the same grant gate as
     * `chat()`: without a managed binding or a live `chat` grant the endpoint
     * refuses with SAND_AI_TRIAL_NOT_GRANTED instead of returning options that
     * could never be sent. Only providers with at least one published `chat`
     * route in the application's managed environment appear; names are the
     * administrator-facing Chinese display names and ordering is stable
     * (route priority, then id). No provider endpoint is probed.
     *
     * @return array{application:array{id:int,name:string},providers:list<array{id:int,name:string,models:list<array{id:int,name:string}>,default_model_id:int}>,default_max_output_tokens:int}
     */
    public function options(int $applicationId, int $adminId, ?array $adminInfo): array
    {
        $iam = $this->iam ?? new AdminTrialIamGateway();
        try {
            $application = $iam->requireManageableApplication($applicationId, $adminId, $adminInfo);
            $binding = $iam->resolveManagedBinding($application['id']);
            if ($binding === null || !$iam->chatGrantActive((int) $binding['workload_client_id'])) {
                throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
            }
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $applicationId, 'trial.options', $problem,
                isset($binding['workload_client_id']) ? (int) $binding['workload_client_id'] : null);
            throw $problem;
        }
        return [
            'application' => ['id' => $application['id'], 'name' => $application['name']],
            'providers' => $this->providerProjection((int) $binding['environment_id']),
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
        try {
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
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $applicationId, 'trial.chat', $problem,
                isset($binding['workload_client_id']) ? (int) $binding['workload_client_id'] : null);
            throw $problem;
        }

        $selection = $this->selectRoute($environmentId, $providerId, $modelId);

        $requestId = $this->requestId($adminId);
        // Reject a missing/malformed Idempotency-Key before SandIAM issues a
        // credential and writes its audit rows; the scope-bound derivation
        // still runs after issuance via required().
        RequestIdempotencyKey::preflight($request);
        try {
            $identity = $iam->issueChatIdentity(
                $workloadClientId,
                $application['organization_id'],
                $application['id'],
                (string) $request->getRemoteIp(),
                $requestId,
                'admin_trial:' . $adminId,
            );
        } catch (ApiProblem $problem) {
            // Grant revocation or a network-policy denial surfacing at issue
            // time is still an authorization denial and earns an audit row.
            $this->auditDeniedAccess($adminId, $applicationId, 'trial.chat', $problem, $workloadClientId);
            throw $problem;
        }
        $context = $identity['identity'];
        // The managed workload client is shared by every administrator of the
        // application, so the derived key adds the admin id as a scope
        // discriminator: two admins reusing the same Idempotency-Key and
        // payload can no longer replay each other's invocation.
        $idempotencyRequestId = RequestIdempotencyKey::required($request, 'chat', $context, 'admin:' . $adminId);

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
                self::chatAttribution($context),
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
            throw new ApiProblem('SAND_AI_TRIAL_MODEL_INVALID', '这个服务商当前没有可用的对话模型，请在服务商配置里检查后重试');
        }
        $modelIds = [];
        foreach ($providerRoutes as $route) {
            $modelIds[(int) $route['model_id']] = min($modelIds[(int) $route['model_id']] ?? PHP_INT_MAX, (int) $route['priority']);
        }
        if ($modelId !== null && !isset($modelIds[$modelId])) {
            throw new ApiProblem('SAND_AI_TRIAL_MODEL_INVALID', '所选模型不属于这个服务商的可用模型，请刷新后重试');
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
            throw new ApiProblem('SAND_AI_TRIAL_ROUTES_UNAVAILABLE', '暂时无法读取这个应用的可用模型，请稍后重试', false, ['cause' => $problem->errorCode]);
        } catch (Throwable) {
            throw new ApiProblem('SAND_AI_TRIAL_ROUTES_UNAVAILABLE', '暂时无法读取这个应用的可用模型，请稍后重试');
        }
        return is_array($routes) ? $routes : [];
    }

    /**
     * Usage attribution for a trial chat. A trial message is a real billable
     * chat completion of the application, so `source_kind` must stay `chat`:
     * it is persisted on `sand_ai_invocation_dispatch` and
     * `sand_ai_usage_event` (whose CHECK whitelist has no `admin_trial`), and
     * it is re-validated by `ChatInvocationFacts`/`InvocationReadFacts`,
     * which only accept `chat` for the `chat` execution scope and
     * `sand_ai.chat.complete` action. The trial origin stays distinguishable
     * through the dedicated `admin_trial` SandIAM workload client, the
     * `admin_trial:<id>` identity subject and the `admin_trial.context.issue`
     * audit action — never through `source_kind`.
     */
    public static function chatAttribution(IdentityContext $context): UsageAttribution
    {
        return UsageAttribution::fromIdentity($context, 'chat', AdminTrialIamGateway::CHAT_ACTION);
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

    /**
     * Provider/egress failures surface as safe Chinese copy plus a stable
     * snake_case reason_code inside `details`, never raw codes, upstream
     * bodies or request ids. The reason_code is the frontend's only branch
     * key; the trial error codes stay internal.
     */
    private function mapGatewayProblem(ApiProblem $problem, string $providerName): ApiProblem
    {
        if ($problem->errorCode === 'SAND_AI_IDEMPOTENCY_KEY_REQUIRED') {
            return new ApiProblem(
                'SAND_AI_TRIAL_IDEMPOTENCY_REQUIRED', '缺少请求标识，请刷新页面后重试',
                false, ['reason_code' => 'idempotency_required'],
            );
        }
        if (in_array($problem->errorCode, ['SAND_AI_BUDGET_EXCEEDED', 'SAND_AI_BUDGET_INSUFFICIENT'], true)) {
            return new ApiProblem(
                'SAND_AI_TRIAL_BUDGET_BLOCKED', '本月预算已用完，可以去费用页调高预算',
                false, ['reason_code' => 'budget_exhausted'],
            );
        }
        if (str_starts_with($problem->errorCode, 'SAND_AI_BUDGET_')) {
            return new ApiProblem(
                'SAND_AI_TRIAL_BUDGET_BLOCKED', '预算设置暂时不能放行这次调用，请到费用页检查预算配置后再试',
                false, ['reason_code' => 'budget_blocked'],
            );
        }
        if ($problem->errorCode === 'SAND_AI_DATA_EGRESS_FORBIDDEN') {
            return new ApiProblem(
                'SAND_AI_TRIAL_EGRESS_BLOCKED', '这个应用的 AI 数据策略不允许这次调用，请联系管理员调整后再试',
                false, ['reason_code' => 'egress_blocked'],
            );
        }
        if ($problem->errorCode === 'SAND_AI_CONFIGURATION_NOT_PUBLISHED') {
            return new ApiProblem(
                'SAND_AI_TRIAL_NOT_PUBLISHED', '这个应用的 AI 模型还没有发布完成，请联系管理员检查配置后再试',
                false, ['reason_code' => 'routes_not_published'],
            );
        }
        $family = ChatProviderFailurePolicy::family($problem);
        if ($family !== null) {
            return match ($family) {
                'unreachable' => new ApiProblem(
                    'SAND_AI_TRIAL_PROVIDER_UNREACHABLE', $this->unreachableMessage($providerName),
                    true, ['reason_code' => 'provider_unreachable'],
                ),
                'busy' => new ApiProblem(
                    'SAND_AI_TRIAL_PROVIDER_UNREACHABLE', 'AI 服务商这会儿太忙，请过一会儿再试',
                    true, ['reason_code' => 'provider_busy'],
                ),
                'balance' => new ApiProblem(
                    'SAND_AI_TRIAL_CHAT_FAILED', '服务商的余额不够了，请先去服务商网站充值后再试',
                    false, ['reason_code' => 'provider_balance_insufficient'],
                ),
                default => new ApiProblem(
                    'SAND_AI_TRIAL_CHAT_FAILED', '服务商配置有问题，请到「模型服务商」页检查密钥和地址后再试',
                    false, ['reason_code' => 'provider_config_invalid'],
                ),
            };
        }
        if (in_array($problem->errorCode, ['SAND_AI_MODEL_NOT_AVAILABLE', 'SAND_AI_CONFIGURATION_INVALID'], true)) {
            return new ApiProblem(
                'SAND_AI_TRIAL_CHAT_FAILED', '服务商配置有问题，请到「模型服务商」页检查密钥和地址后再试',
                false, ['reason_code' => 'provider_config_invalid'],
            );
        }
        // Gateway internals carry English diagnostics; the trial surface
        // only ever shows plain Chinese copy to the administrator.
        return new ApiProblem(
            'SAND_AI_TRIAL_CHAT_FAILED', '这次试用没有成功，请稍后重试', $problem->retryable,
            ['reason_code' => 'unknown'],
        );
    }

    private function unreachableMessage(string $providerName): string
    {
        return $providerName !== ''
            ? "连不上{$providerName}，请到「模型服务商」点「测试连接」看看。"
            : '连不上模型服务商，请到「模型服务商」点「测试连接」看看。';
    }

    /**
     * Best-effort SandAI-side audit for trial authorization denials. Records
     * actor/application/action/reason code only — never request payloads,
     * messages or credentials — so the row reconciles with SandIAM's own
     * denied audits. A failing audit sink never masks the ApiProblem.
     */
    private function auditDeniedAccess(int $adminId, int $applicationId, string $endpoint, ApiProblem $problem, ?int $workloadClientId = null): void
    {
        if (!isset(self::DENIED_AUDIT_CODES[$problem->errorCode])) {
            return;
        }
        try {
            $context = [
                'endpoint' => $endpoint,
                'reason_code' => $problem->errorCode,
            ];
            if ($workloadClientId !== null) {
                $context['workload_client_id'] = $workloadClientId;
            }
            $this->writeDeniedAudit([
                'actor_type' => 'admin',
                'actor_ref' => (string) $adminId,
                'action' => 'admin_trial.access.denied',
                'resource_type' => 'application',
                'resource_id' => $applicationId,
                'summary' => '管理员试用授权被拒绝',
                'context' => $context,
            ]);
        } catch (Throwable) {
            // The audit sink must never mask the authorization failure itself.
        }
    }

    /** @param array<string,mixed> $entry */
    private function writeDeniedAudit(array $entry): void
    {
        if (is_callable($this->deniedAudit)) {
            ($this->deniedAudit)($entry);
            return;
        }
        AuditLog::create($entry);
    }

    /**
     * POST /admin/trial/knowledge. Billed trial search through the managed
     * identity (`sand_ai.knowledge.query` only) and the standard embedding +
     * retrieval gateways, so budget, metering and citations match production.
     * Requires the managed binding to own the base's environment and a ready
     * embedding index.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function knowledgeTrial(array $input, int $adminId, ?array $admin, Request $request): array
    {
        AdminTrialRateLimiter::assertAllowed($adminId);
        $knowledgeBaseId = self::positiveIdStatic($input['knowledge_base_id'] ?? null, 'knowledge_base_id');
        $query = $input['query'] ?? null;
        if (!is_string($query) || trim($query) === '' || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_TRIAL_MESSAGE_INVALID', '请输入要查找的内容（不超过 500 字）');
        }
        $limit = $input['limit'] ?? 5;
        if (!is_int($limit) || $limit < 1 || $limit > 20) {
            throw new ApiProblem('SAND_AI_TRIAL_VALIDATION_ERROR', '请求参数不完整或格式不正确，请刷新后重试', false, ['field' => 'limit']);
        }

        $iam = $this->iam ?? new AdminTrialIamGateway();
        $base = KnowledgeBase::where('id', $knowledgeBaseId)->find();
        if ($base === null || $base->delete_time !== null) {
            throw new ApiProblem('SAND_AI_TRIAL_KNOWLEDGE_NOT_FOUND', '没有找到这个知识库，请刷新后重试');
        }
        $environmentId = (int) $base->environment_id;
        $activeVersionId = (int) ($base->active_version_id ?? 0);
        if ((string) $base->state !== 'published' || (int) $base->status !== 1 || $activeVersionId < 1) {
            throw new ApiProblem('SAND_AI_TRIAL_KNOWLEDGE_NOT_READY', '这个知识库还没有发布内容，请先发布后再试');
        }
        try {
            $iamApplicationId = $iam->applicationForEnvironment($environmentId);
            if ($iamApplicationId === null) {
                throw new ApiProblem('SAND_AI_TRIAL_APPLICATION_NOT_FOUND', '没有找到这个应用，请刷新后重试');
            }
            $application = $iam->requireManageableApplication($iamApplicationId, $adminId, $admin);
            $binding = $iam->resolveManagedBinding($application['id']);
            if ($binding === null || (int) $binding['environment_id'] !== $environmentId) {
                throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个知识库不属于当前应用的托管环境，暂时不能试用');
            }
            $workloadClientId = (int) $binding['workload_client_id'];
            if (!$iam->actionGrantActive($workloadClientId, AdminTrialIamGateway::KNOWLEDGE_QUERY_ACTION)) {
                throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
            }
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $iamApplicationId ?? 0, 'trial.knowledge', $problem,
                isset($binding['workload_client_id']) ? (int) $binding['workload_client_id'] : null);
            throw $problem;
        }

        $publication = $this->trialPublication($environmentId, $knowledgeBaseId);
        try {
            (new KnowledgeEmbeddingIndexGateway())->currentIndex($publication);
        } catch (ApiProblem) {
            throw new ApiProblem('SAND_AI_TRIAL_KNOWLEDGE_NOT_READY', '知识库还在索引中或索引失败，请稍后再试');
        }

        $requestId = 'atrial-knowledge:' . $adminId . ':' . bin2hex(random_bytes(8));
        RequestIdempotencyKey::preflight($request);
        try {
            $identity = $iam->issueIdentity(
                $workloadClientId, $application['organization_id'], $application['id'],
                (string) $request->getRemoteIp(), $requestId, 'admin_trial:' . $adminId,
                [AdminTrialIamGateway::KNOWLEDGE_QUERY_ACTION],
            );
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $application['id'], 'trial.knowledge', $problem, $workloadClientId);
            throw $problem;
        }
        $context = $identity['identity'];
        $idempotencyRequestId = RequestIdempotencyKey::required($request, 'knowledge-query', $context, 'admin:' . $adminId);
        $clientId = filter_var($context->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false) {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用，请稍后重试');
        }
        $resourceKey = (int) $publication['id'] . ':' . $clientId;
        $authorizer = new HostSandIamInvocationAuthorizer();
        $sourceIp = (string) $request->getRemoteIp();
        $signedContext = 'Bearer ' . $identity['context'];
        $authorizeEmbedding = static function (int $invocationId) use (
            $authorizer, $signedContext, $sourceIp, $context, $resourceKey, $requestId,
        ): void {
            $authorizer->authorizeSigned(
                $signedContext, $sourceIp, $context, AdminTrialIamGateway::KNOWLEDGE_QUERY_ACTION,
                'sand_ai.knowledge_publication', $resourceKey,
                'knowledge-trial:' . bin2hex(random_bytes(8)), $requestId,
            );
        };

        try {
            $embedding = (new KnowledgeQueryEmbeddingGateway())->embed(
                $publication, trim($query), $idempotencyRequestId,
                UsageAttribution::fromIdentity($context, 'knowledge_query', 'sand_ai.knowledge.query', 'embedding'),
                $authorizeEmbedding,
            );
            $result = (new KnowledgeBaseQueryGateway())->queryWithVector(
                $publication, ['query' => trim($query), 'limit' => $limit],
                $embedding['vector'], $embedding['index_id'],
            );
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode, [
                'SAND_AI_KNOWLEDGE_INDEX_UNAVAILABLE', 'SAND_AI_KNOWLEDGE_SOURCE_DRIFTED',
                'SAND_AI_KNOWLEDGE_SOURCE_INVALID',
            ], true)) {
                throw new ApiProblem('SAND_AI_TRIAL_KNOWLEDGE_NOT_READY', '知识库还在索引中或索引失败，请稍后再试');
            }
            throw $this->mapGatewayProblem($problem, '');
        }

        return [
            'application' => ['id' => $application['id'], 'name' => $application['name']],
            'knowledge_base' => [
                'id' => $knowledgeBaseId,
                'code' => (string) $base->code,
                'name' => (string) $base->name,
            ],
            'query' => trim($query),
            'citations' => is_array($result['citations'] ?? null) ? $result['citations'] : [],
            'invocation_id' => is_int($embedding['invocation']['id'] ?? null) ? $embedding['invocation']['id'] : null,
            'idempotent_replay' => ($embedding['idempotent_replay'] ?? false) === true,
        ];
    }

    /**
     * Self-publication used by the owner-side trial so the billed query path
     * keeps its real publication foreign key. Created once per knowledge base
     * through the normal publication gateway; code is deterministic.
     *
     * @return array<string,mixed>
     */
    private function trialPublication(int $environmentId, int $knowledgeBaseId): array
    {
        $publications = new KnowledgePublicationGateway();
        $existing = Db::connect('pgsql')->query(
            "SELECT code FROM sand_ai_knowledge_publication
             WHERE knowledge_base_id=? AND consumer_environment_id=? AND state='published'",
            [$knowledgeBaseId, $environmentId],
        );
        $code = $existing === [] ? 'self-' . $knowledgeBaseId : (string) $existing[0]['code'];
        if ($existing === []) {
            try {
                $publications->publish(
                    $environmentId, $knowledgeBaseId, $environmentId, $code,
                    'admin-trial-self:' . $knowledgeBaseId, 'admin_trial:self',
                );
            } catch (ApiProblem $problem) {
                if ($problem->errorCode !== 'SAND_AI_IDEMPOTENCY_CONFLICT'
                    && $problem->errorCode !== 'SAND_AI_RESOURCE_CONFLICT') {
                    throw $problem;
                }
            }
            $existing = Db::connect('pgsql')->query(
                "SELECT code FROM sand_ai_knowledge_publication
                 WHERE knowledge_base_id=? AND consumer_environment_id=? AND state='published'",
                [$knowledgeBaseId, $environmentId],
            );
            if ($existing === []) {
                throw new ApiProblem('SAND_AI_TRIAL_KNOWLEDGE_NOT_READY', '知识库试用通道暂时不可用，请稍后再试');
            }
            $code = (string) $existing[0]['code'];
        }
        return $publications->findForConsumer($environmentId, $code);
    }

    /**
     * POST /admin/trial/agent. Published-agent "试一下" through the real
     * AgentGateway task path with a minimum-action trial identity, so billing,
     * budget and citations match production runs. Long runs report state
     * 'running' with the task id for the poll endpoint.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function agentTrial(array $input, int $adminId, ?array $admin, Request $request): array
    {
        AdminTrialRateLimiter::assertAllowed($adminId);
        $applicationId = self::positiveIdStatic($input['application_id'] ?? null, 'application_id');
        $agentId = self::positiveIdStatic($input['agent_id'] ?? null, 'agent_id');
        $query = $input['query'] ?? null;
        if (!is_string($query) || trim($query) === '' || mb_strlen($query, 'UTF-8') > 500) {
            throw new ApiProblem('SAND_AI_TRIAL_MESSAGE_INVALID', '请输入要发送给智能体的内容（不超过 500 字）');
        }
        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && !is_string($conversationId)) {
            throw new ApiProblem('SAND_AI_TRIAL_VALIDATION_ERROR', '请求参数不完整或格式不正确，请刷新后重试', false, ['field' => 'conversation_id']);
        }

        $iam = $this->iam ?? new AdminTrialIamGateway();
        try {
            $application = $iam->requireManageableApplication($applicationId, $adminId, $admin);
            $binding = $iam->resolveManagedBinding($application['id']);
            if ($binding === null) {
                throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
            }
            $environmentId = (int) $binding['environment_id'];
            $workloadClientId = (int) $binding['workload_client_id'];
            foreach ([
                AdminTrialIamGateway::AGENT_SUBMIT_ACTION,
                AdminTrialIamGateway::AGENT_RUN_ACTION,
            ] as $action) {
                if (!$iam->actionGrantActive($workloadClientId, $action)) {
                    throw new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
                }
            }
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $applicationId, 'trial.agent', $problem,
                isset($binding['workload_client_id']) ? (int) $binding['workload_client_id'] : null);
            throw $problem;
        }

        $definitionRow = Db::connect('pgsql')->query(
            "SELECT d.id,d.code,d.environment_id FROM sand_ai_agent_definition d
             WHERE d.id=? AND d.delete_time IS NULL",
            [$agentId],
        );
        if ($definitionRow === []
            || (int) $definitionRow[0]['environment_id'] !== $environmentId) {
            throw new ApiProblem('SAND_AI_TRIAL_AGENT_NOT_FOUND', '没有找到这个智能体，或它不属于当前应用');
        }
        try {
            $definition = (new AgentDefinitionGateway())->resolve($environmentId, (string) $definitionRow[0]['code']);
        } catch (ApiProblem $problem) {
            throw new ApiProblem('SAND_AI_TRIAL_AGENT_NOT_READY', '这个智能体还没有发布完成或缺少可用知识库，请先在智能体页检查后再试');
        }
        $runInput = AgentDefinitionPolicy::runInput([
            'query' => trim($query),
            ...($conversationId === null ? [] : ['conversation_id' => $conversationId]),
        ]);
        // Knowledge grants and the query action only apply when the published
        // agent actually binds a knowledge base.
        $knowledgeBound = $definition['config']['knowledge_base_code'] !== null;
        if ($knowledgeBound && !$iam->actionGrantActive($workloadClientId, AdminTrialIamGateway::KNOWLEDGE_QUERY_ACTION)) {
            $problem = new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
            $this->auditDeniedAccess($adminId, $applicationId, 'trial.agent', $problem, $workloadClientId);
            throw $problem;
        }

        $requestId = 'atrial-agent:' . $adminId . ':' . bin2hex(random_bytes(8));
        RequestIdempotencyKey::preflight($request);
        try {
            $identity = $iam->issueIdentity(
                $workloadClientId, $application['organization_id'], $application['id'],
                (string) $request->getRemoteIp(), $requestId, 'admin_trial:' . $adminId,
                [
                    AdminTrialIamGateway::AGENT_SUBMIT_ACTION,
                    AdminTrialIamGateway::AGENT_RUN_ACTION,
                    ...($knowledgeBound ? [AdminTrialIamGateway::KNOWLEDGE_QUERY_ACTION] : []),
                ],
            );
        } catch (ApiProblem $problem) {
            $this->auditDeniedAccess($adminId, $application['id'], 'trial.agent', $problem, $workloadClientId);
            throw $problem;
        }
        $context = $identity['identity'];
        $idempotencyRequestId = RequestIdempotencyKey::required($request, 'agent', $context, 'admin:' . $adminId);
        $sourceIp = (string) $request->getRemoteIp();
        $signedContext = 'Bearer ' . $identity['context'];

        try {
            (new AgentGateway())->submit(
                $environmentId,
                [...$definition['config'], ...$runInput],
                $idempotencyRequestId,
                UsageAttribution::fromIdentity($context, 'agent', 'sand_ai.agent.submit'),
                static fn (int $taskId): array => (new TaskRunAccessGuard())->authorizeSigned(
                    $signedContext, $sourceIp, $context, $taskId, 'agent_run', $requestId,
                ),
                $definition['binding'],
            );
        } catch (ApiProblem $problem) {
            throw $this->mapGatewayProblem($problem, '');
        }

        // submit() returns the task summary, not the row id; the idempotency
        // request id is the task's unique request_id in every replay branch.
        $task = TaskRun::where('request_id', $idempotencyRequestId)->find();
        if ($task === null) {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用，请稍后重试', true);
        }
        return $this->agentTaskProjection($task, 25);
    }

    /**
     * Poll projection for an in-flight agent trial. Re-validates that the
     * administrator still manages the task's application and that the task
     * belongs to the application's managed trial binding before returning the
     * safe task summary (output content and source references included).
     *
     * @param array<string,mixed>|null $admin
     * @return array<string,mixed>
     */
    public function agentTask(int $taskId, int $adminId, ?array $admin): array
    {
        if ($taskId < 1) {
            throw new ApiProblem('SAND_AI_TRIAL_VALIDATION_ERROR', '请求参数不完整或格式不正确，请刷新后重试', false, ['field' => 'task_id']);
        }
        $task = TaskRun::where('id', $taskId)->find();
        if ($task === null || (string) $task->task_type !== 'agent_run'
            || (string) ($task->resource_type ?? '') !== 'agent_run') {
            throw new ApiProblem('SAND_AI_TRIAL_AGENT_NOT_FOUND', '没有找到这次试用任务');
        }
        $iam = $this->iam ?? new AdminTrialIamGateway();
        $applicationId = (int) $task->application_id;
        $application = $iam->requireManageableApplication($applicationId, $adminId, $admin);
        $binding = $iam->resolveManagedBinding($application['id']);
        // The task columns are the persisted attribution facts written at
        // submit time; they must still match the application's managed
        // binding before any result is disclosed.
        if ($binding === null
            || (int) $binding['environment_id'] !== (int) $task->environment_id
            || (int) $binding['workload_client_id'] !== (int) $task->workload_client_id) {
            throw new ApiProblem('SAND_AI_TRIAL_APPLICATION_FORBIDDEN', '当前账号没有查看这次试用的权限');
        }
        return $this->agentTaskProjection($task, 0);
    }

    /** @return array<string,mixed> */
    private function agentTaskProjection(TaskRun $task, int $waitSeconds): array
    {
        $deadline = time() + max(0, $waitSeconds);
        do {
            $state = (string) $task->state;
            if (TaskState::terminal($state)) {
                break;
            }
            if ($waitSeconds === 0 || time() >= $deadline) {
                return [
                    'task_id' => (int) $task->id,
                    'state' => $state,
                    'result' => null,
                ];
            }
            sleep(1);
            $fresh = TaskRun::where('id', (int) $task->id)->find();
            if ($fresh !== null) {
                $task = $fresh;
            }
        } while (true);
        $summary = (new TaskGateway())->summary($task, true);
        $result = is_array($summary['result'] ?? null) ? $summary['result'] : null;
        return [
            'task_id' => (int) $task->id,
            'state' => (string) $task->state,
            'result' => $result === null ? null : [
                'output' => $result['output'] ?? null,
                'source_references' => $result['source_references'] ?? null,
                'tool_calls' => $result['tool_calls'] ?? null,
                'invocation' => $result['invocation'] ?? null,
                'review_required' => $result['review_required'] ?? null,
            ],
            'error_code' => $task->error_code,
        ];
    }

    private function requestId(int $adminId): string
    {
        return 'atrial-chat:' . $adminId . ':' . bin2hex(random_bytes(8));
    }
}
