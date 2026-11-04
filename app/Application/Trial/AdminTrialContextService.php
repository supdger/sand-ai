<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\HostSandIamRuntime;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;
use app\Infrastructure\Persistence\Model\AuditLog;
use Throwable;

/**
 * Administrator trial channel: turns a backstage admin session into a
 * short-lived SandIAM workload context so trial panels call the runtime API
 * without a hand-pasted credential. The `siam_` credential is issued fresh per
 * call, lives only inside this request and is never persisted by SandAI; the
 * signed context itself is never stored. Revoking the dedicated trial
 * workload client in SandIAM closes the whole channel.
 */
final class AdminTrialContextService
{
    private const AUDIENCE = 'sand-ai';
    private const SERVICE_CODE = 'sand_ai';
    private const CONTEXT_TTL_SECONDS = 300;
    private const CREDENTIAL_TTL_MARGIN_SECONDS = 30;

    /**
     * @param AdminTrialEntityStore|null $store injected for tests; production
     *        resolves the SandIAM-backed store (null when the plugin is absent)
     * @param object|null $contextIssuer SandIAM IdentityContextProvider double
     * @param object|null $serviceCatalog HostSandIamServiceCatalog double
     * @param callable(array<string,mixed>):void|null $adminAudit SandAI-side
     *        management audit sink; production writes sand_ai_audit_log
     */
    public function __construct(
        private readonly ?AdminTrialEntityStore $store = null,
        private readonly ?object $contextIssuer = null,
        private readonly ?object $serviceCatalog = null,
        private readonly mixed $adminAudit = null,
    ) {
    }

    /**
     * @param mixed $requestedActions raw `actions` value from the admin body
     * @return array{context:string,expire_time:string,actions:list<string>}
     */
    public function issueContext(mixed $requestedActions, int $adminId, string $sourceIp, string $requestId): array
    {
        if ($adminId <= 0) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', 'An authenticated administrator is required');
        }
        AdminTrialRateLimiter::assertAllowed($adminId);
        $actions = AdminTrialActions::resolve($requestedActions);
        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::') {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '无法确认请求来源，暂时无法签发试用身份；请改用调用密钥');
        }

        $store = $this->store ?? (SandIamAdminTrialEntityStore::available() ? new SandIamAdminTrialEntityStore() : null);
        $issuer = $this->contextIssuer ?? HostSandIamRuntime::identityContextProvider();
        if ($store === null || $issuer === null || !method_exists($issuer, 'issue')) {
            throw new ApiProblem(
                'SAND_AI_TRIAL_IAM_UNAVAILABLE',
                '身份与授权组件（SandIAM）未安装或未启用，暂时无法自动签发试用身份；请在 SandIAM 中为应用签发调用密钥后手动填写',
            );
        }

        $this->ensureServiceCatalog();
        $reference = $store->ensureTrialClient();
        $store->ensureGrants((int) $reference['workload_client_id'], AdminTrialActions::all());

        $credential = $store->issueCredential(
            (int) $reference['workload_client_id'],
            date('Y-m-d H:i:s', time() + self::CONTEXT_TTL_SECONDS + self::CREDENTIAL_TTL_MARGIN_SECONDS),
            $requestId . ':cred',
            'admin_trial:' . $adminId,
            (int) $reference['organization_id'],
            (int) $reference['application_id'],
        );
        try {
            // subject_scope stays null: the admin session is never asserted as
            // a workload subject; the context carries only whitelist actions.
            $issued = $issuer->issue(
                $credential['credential'],
                self::AUDIENCE,
                $actions,
                null,
                $requestId,
                $sourceIp,
                self::SERVICE_CODE,
            );
        } catch (Throwable $exception) {
            throw $this->issueFailed($exception);
        }
        if (!is_array($issued)
            || !is_string($issued['context'] ?? null) || $issued['context'] === ''
            || !is_string($issued['expire_time'] ?? null) || $issued['expire_time'] === '') {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法签发试用身份，请稍后重试或改用调用密钥');
        }

        $this->writeAdminAudit([
            'actor_type' => 'admin',
            'actor_ref' => (string) $adminId,
            'action' => 'admin_trial.context.issue',
            'resource_type' => 'workload_client',
            'resource_id' => (int) $reference['workload_client_id'],
            'summary' => '管理员试用运行身份已签发',
            'context' => [
                'actions' => $actions,
                'request_id' => $requestId,
                'context_id' => is_string($issued['context_id'] ?? null) ? $issued['context_id'] : null,
                'credential_id' => $credential['id'],
                'credential_key_prefix' => $credential['key_prefix'],
                'expire_time' => $issued['expire_time'],
                'environment_id' => (int) $reference['environment_id'],
            ],
        ]);

        return [
            'context' => $issued['context'],
            'expire_time' => $issued['expire_time'],
            'actions' => $actions,
        ];
    }

    /**
     * Idempotent missing-only registration of the SandAI service declarations
     * the whitelist needs (base catalog plus audio actions). A disabled or
     * unavailable catalog degrades to Chinese guidance instead of issuing.
     */
    private function ensureServiceCatalog(): void
    {
        $catalog = $this->serviceCatalog ?? new HostSandIamServiceCatalog();
        foreach (['register', 'registerAudio'] as $method) {
            if (!method_exists($catalog, $method)) {
                throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '身份与授权组件（SandIAM）未安装或未启用，暂时无法自动签发试用身份；请在 SandIAM 中为应用签发调用密钥后手动填写');
            }
            try {
                $catalog->{$method}();
            } catch (Throwable) {
                throw new ApiProblem('SAND_AI_TRIAL_CATALOG_INCOMPLETE', '系统准备未完成或已被停用，请先在「首次开通」页完成系统准备后重试，或改用调用密钥');
            }
        }
    }

    /** SandIAM-side failures become one degrade problem; details stay in SandIAM audit. */
    private function issueFailed(Throwable $exception): ApiProblem
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'SAND_IAM_CREDENTIAL_REVOKED')) {
            return new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', '管理员试用通道已被停用，请改用调用密钥');
        }
        if (str_contains($message, 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN')) {
            return new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '当前网络来源不允许签发试用身份，请改用调用密钥');
        }
        return new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法签发试用身份，请稍后重试或改用调用密钥');
    }

    /** @param array<string,mixed> $entry */
    private function writeAdminAudit(array $entry): void
    {
        if (is_callable($this->adminAudit)) {
            ($this->adminAudit)($entry);
            return;
        }
        AuditLog::create($entry);
    }
}
