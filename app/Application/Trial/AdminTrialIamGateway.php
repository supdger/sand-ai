<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\HostSandIamRuntime;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\WorkloadClient;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\CredentialIssuanceService;
use think\facade\Db;
use Throwable;

/**
 * Read-only SandIAM adapter for the administrator "试一试" channel (B2).
 *
 * It resolves the trusted application context, locates the §3.1 managed
 * binding (`sand_iam_workload_client.code='managed'`, audience `sand-ai`,
 * environment owned by the application), re-checks that the
 * `sand_ai.chat.complete` grant is currently effective, and issues a
 * minimum-action short-lived credential/context for that managed client.
 *
 * It never creates applications, environments, workload clients or grants;
 * grant provisioning stays exclusively with SandIAM (WP-A1/B3).
 */
final class AdminTrialIamGateway
{
    public const MANAGED_CLIENT_CODE = 'managed';
    public const AUDIENCE = 'sand-ai';
    public const SERVICE_CODE = 'sand_ai';
    public const CHAT_ACTION = 'sand_ai.chat.complete';
    private const CONTEXT_TTL_SECONDS = 300;
    private const CREDENTIAL_TTL_MARGIN_SECONDS = 30;

    /** SandIAM plugin classes required by this channel. */
    public static function available(): bool
    {
        foreach ([
            Application::class,
            Environment::class,
            WorkloadClient::class,
            Organization::class,
            CredentialIssuanceService::class,
            AdminOrganizationAccess::class,
        ] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }
        return HostSandIamRuntime::identityContextProvider() !== null;
    }

    /**
     * Application lookup + SandIAM management-scope check.
     * Missing/disabled application or disabled organization resolves to 404 so
     * cross-tenant details never leak; an existing but out-of-scope application
     * resolves to 403, matching AdminOrganizationAccess semantics.
     *
     * @return array{id:int,name:string,organization_id:int}
     */
    public function requireManageableApplication(int $applicationId, int $adminId, ?array $adminInfo): array
    {
        $this->assertAvailable();
        $application = Application::where('id', $applicationId)->where('status', 1)->find();
        $organization = $application === null
            ? null
            : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        if ($application === null || $organization === null) {
            throw new ApiProblem('SAND_AI_TRIAL_APPLICATION_NOT_FOUND', '没有找到这个应用，请刷新后重试');
        }
        $access = new AdminOrganizationAccess($adminId, $adminInfo);
        if (!$access->isSuperAdmin() && !in_array($applicationId, $access->applicationIds(), true)) {
            throw new ApiProblem('SAND_AI_TRIAL_APPLICATION_FORBIDDEN', '当前账号没有管理这个应用的权限，请联系客户主体管理员授权');
        }
        return [
            'id' => (int) $application->id,
            'name' => (string) $application->name,
            'organization_id' => (int) $application->organization_id,
        ];
    }

    /**
     * Locate the §3.1 managed binding for the application: the single active
     * environment owned by the application that carries an active
     * `managed`/`sand-ai` workload client. Returns null when no such binding
     * exists; multiple candidates are a conflict, never a guess.
     *
     * @return array{environment_id:int,workload_client_id:int}|null
     */
    public function resolveManagedBinding(int $applicationId): ?array
    {
        $this->assertAvailable();
        $environmentIds = Environment::where('application_id', $applicationId)
            ->where('status', 1)
            ->column('id');
        $environmentIds = array_values(array_unique(array_map('intval', is_array($environmentIds) ? $environmentIds : [])));
        if ($environmentIds === []) {
            return null;
        }
        $clients = WorkloadClient::whereIn('environment_id', $environmentIds)
            ->where('code', self::MANAGED_CLIENT_CODE)
            ->where('audience', self::AUDIENCE)
            ->where('status', 1)
            ->field('id,environment_id')
            ->select()
            ->toArray();
        if (!is_array($clients) || count($clients) !== 1) {
            if (is_array($clients) && count($clients) > 1) {
                throw new ApiProblem('SAND_AI_TRIAL_BINDING_AMBIGUOUS', '这个应用存在多个托管环境，请联系管理员处理后再试');
            }
            return null;
        }
        $client = $clients[0];
        $environmentId = (int) ($client['environment_id'] ?? 0);
        $clientId = (int) ($client['id'] ?? 0);
        if ($environmentId < 1 || $clientId < 1 || !in_array($environmentId, $environmentIds, true)) {
            return null;
        }
        return ['environment_id' => $environmentId, 'workload_client_id' => $clientId];
    }

    /**
     * Live grant recheck mirroring SandIAM `resolveServiceGrants`: the
     * `sand_ai.chat.complete` grant must be active, unrevoked, unexpired, and
     * backed by an active service/action. Network policy and quota remain
     * enforced by `IdentityContextProvider::issue`/`verifyForService`.
     */
    public function chatGrantActive(int $workloadClientId): bool
    {
        $this->assertAvailable();
        $row = Db::connect('pgsql')->table('sand_iam_service_grant')->alias('g')
            ->join('sand_iam_service_action a', 'a.id = g.service_action_id')
            ->join('sand_iam_service s', 's.id = a.service_id')
            ->where('g.workload_client_id', $workloadClientId)
            ->where('g.audience', self::AUDIENCE)
            ->where('g.status', 1)
            ->whereNull('g.revoked_time')
            ->where(static function ($query): void {
                $query->whereNull('g.expire_time')->whereOr('g.expire_time', '>', date('Y-m-d H:i:s'));
            })
            ->where('s.code', self::SERVICE_CODE)
            ->where('s.status', 1)
            ->where('a.code', self::CHAT_ACTION)
            ->where('a.status', 1)
            ->field('g.id')
            ->find();
        return is_array($row) && (int) ($row['id'] ?? 0) > 0;
    }

    /**
     * Issue a fresh short-lived credential for the managed client and exchange
     * it for a signed minimum-action context. Never grants anything; SandIAM
     * `issue`/`verifyForService` reject missing or revoked grants.
     *
     * @return array{context:string,identity:IdentityContext,credential_id:int,key_prefix:string,expire_time:string}
     */
    public function issueChatIdentity(
        int $workloadClientId,
        int $organizationId,
        int $applicationId,
        string $sourceIp,
        string $requestId,
        string $actor,
    ): array {
        $this->assertAvailable();
        if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false || $sourceIp === '0.0.0.0' || $sourceIp === '::') {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '无法确认请求来源，暂时无法发起试用对话，请稍后重试');
        }
        $issuer = HostSandIamRuntime::identityContextProvider();
        if ($issuer === null || !method_exists($issuer, 'issue')) {
            throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '身份与授权组件（SandIAM）未安装或未启用，暂时无法发起试用对话');
        }
        $expireTime = date('Y-m-d H:i:s', time() + self::CONTEXT_TTL_SECONDS + self::CREDENTIAL_TTL_MARGIN_SECONDS);
        try {
            $credential = (new CredentialIssuanceService())->issue(
                $workloadClientId,
                '管理员试用 ' . $actor,
                $expireTime,
                $requestId . ':cred',
                $actor,
                $organizationId,
                $applicationId,
            );
        } catch (Throwable) {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用对话，请稍后重试');
        }
        try {
            $issued = $issuer->issue(
                (string) ($credential['credential'] ?? ''),
                self::AUDIENCE,
                [self::CHAT_ACTION],
                null,
                $requestId,
                $sourceIp,
                self::SERVICE_CODE,
            );
        } catch (Throwable $exception) {
            throw $this->mapIssueFailure($exception);
        }
        $signed = is_array($issued) && is_string($issued['context'] ?? null) ? $issued['context'] : '';
        if ($signed === '') {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用对话，请稍后重试');
        }
        try {
            $identity = (new HostSandIamIdentityContextProvider())->requireSignedContext(
                $signed, self::CHAT_ACTION, $sourceIp, $requestId,
            );
        } catch (Throwable) {
            throw new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用对话，请稍后重试');
        }
        return [
            'context' => $signed,
            'identity' => $identity,
            'credential_id' => (int) ($credential['id'] ?? 0),
            'key_prefix' => (string) ($credential['key_prefix'] ?? ''),
            'expire_time' => is_string($issued['expire_time'] ?? null) ? $issued['expire_time'] : $expireTime,
        ];
    }

    /** SandIAM denial during issuance maps to the shared "not opened" copy. */
    private function mapIssueFailure(Throwable $exception): ApiProblem
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'FORBIDDEN') || str_contains($message, 'DENIED') || str_contains($message, 'REVOKED')) {
            return new ApiProblem('SAND_AI_TRIAL_NOT_GRANTED', '这个应用还没开通试用功能，请先在应用授权里开通');
        }
        return new ApiProblem('SAND_AI_TRIAL_ISSUE_FAILED', '暂时无法发起试用对话，请稍后重试');
    }

    private function assertAvailable(): void
    {
        if (!self::available()) {
            throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '身份与授权组件（SandIAM）未安装或未启用，暂时无法使用试用功能');
        }
    }
}
