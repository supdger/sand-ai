<?php

declare(strict_types=1);

namespace app\Application\Trial;

use app\Api\Support\ApiProblem;
use think\facade\Db;
use Throwable;

/**
 * SandIAM-backed trial entity store. Consumes SandIAM's own model and service
 * layer through the host plugin namespace (no parallel IAM, no raw table
 * writes outside SandIAM's schema). Upserts follow the OnboardingService
 * pattern: natural-key lookup under a transaction-scoped advisory lock, with
 * the unique constraint as the concurrency backstop.
 */
final class SandIamAdminTrialEntityStore implements AdminTrialEntityStore
{
    private const ORGANIZATION_CODE = 'default';
    private const ORGANIZATION_NAME = '本机构';
    private const APPLICATION_CODE = 'system';
    private const APPLICATION_NAME = '本系统';
    private const ENVIRONMENT_CODE = 'production';
    private const ENVIRONMENT_NAME = '正式';
    private const CLIENT_CODE = 'admin_trial';
    private const CLIENT_NAME = '管理员试用';
    private const AUDIENCE = 'sand-ai';
    private const SERVICE_CODE = 'sand_ai';

    /** All SandIAM classes the channel consumes; absent plugin => unavailable. */
    public static function available(): bool
    {
        foreach ([
            \plugin\SandIam\app\model\Organization::class,
            \plugin\SandIam\app\model\Application::class,
            \plugin\SandIam\app\model\Environment::class,
            \plugin\SandIam\app\model\WorkloadClient::class,
            \plugin\SandIam\app\model\Service::class,
            \plugin\SandIam\app\model\ServiceAction::class,
            \plugin\SandIam\app\model\ServiceGrant::class,
            \plugin\SandIam\app\service\CredentialIssuanceService::class,
        ] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }
        return true;
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int} */
    public function ensureTrialClient(): array
    {
        Db::startTrans();
        try {
            $this->lockTrialChannel();
            $application = \plugin\SandIam\app\model\Application::where('code', self::APPLICATION_CODE)
                ->order('id')->lock(true)->find();
            if ($application !== null) {
                $organizationId = (int) $application->organization_id;
                $applicationId = (int) $application->id;
                $this->assertActive($application, '接入应用');
            } else {
                $organization = \plugin\SandIam\app\model\Organization::where('status', 1)
                    ->order('id')->lock(true)->find();
                if ($organization === null) {
                    $organization = $this->createOrFind(
                        \plugin\SandIam\app\model\Organization::class,
                        ['code' => self::ORGANIZATION_CODE],
                        ['code' => self::ORGANIZATION_CODE, 'name' => self::ORGANIZATION_NAME, 'status' => 1],
                    );
                }
                $this->assertActive($organization, '客户主体');
                $organizationId = (int) $organization->id;
                $application = $this->createOrFind(
                    \plugin\SandIam\app\model\Application::class,
                    ['organization_id' => $organizationId, 'code' => self::APPLICATION_CODE],
                    [
                        'organization_id' => $organizationId,
                        'code' => self::APPLICATION_CODE,
                        'name' => self::APPLICATION_NAME,
                        'status' => 1,
                    ],
                );
                $applicationId = (int) $application->id;
                $this->assertActive($application, '接入应用');
            }
            $environment = $this->createOrFind(
                \plugin\SandIam\app\model\Environment::class,
                ['application_id' => $applicationId, 'code' => self::ENVIRONMENT_CODE],
                [
                    'application_id' => $applicationId,
                    'code' => self::ENVIRONMENT_CODE,
                    'name' => self::ENVIRONMENT_NAME,
                    'status' => 1,
                ],
            );
            $this->assertActive($environment, '应用环境');
            $client = $this->createOrFind(
                \plugin\SandIam\app\model\WorkloadClient::class,
                ['environment_id' => (int) $environment->id, 'code' => self::CLIENT_CODE],
                [
                    'environment_id' => (int) $environment->id,
                    'code' => self::CLIENT_CODE,
                    'name' => self::CLIENT_NAME,
                    'audience' => self::AUDIENCE,
                    'status' => 1,
                ],
            );
            // A pre-existing row with this code but a different audience is an
            // operator-owned client, not the trial channel; never mutate it.
            if (!hash_equals(self::AUDIENCE, (string) $client->audience)) {
                throw new ApiProblem('SAND_AI_TRIAL_CLIENT_CONFLICT', '试用通道与既有调用身份冲突，请改用调用密钥');
            }
            if ((int) $client->status !== 1) {
                throw new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', '管理员试用通道已被停用，请改用调用密钥');
            }
            $reference = [
                'organization_id' => $organizationId,
                'application_id' => $applicationId,
                'environment_id' => (int) $environment->id,
                'workload_client_id' => (int) $client->id,
            ];
            Db::commit();
            return $reference;
        } catch (Throwable $exception) {
            Db::rollback();
            if ($exception instanceof ApiProblem) {
                throw $exception;
            }
            throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '暂时无法准备试用身份，请稍后重试或改用调用密钥');
        }
    }

    /** @param list<string> $actionCodes @return array<string,int> */
    public function ensureGrants(int $workloadClientId, array $actionCodes): array
    {
        Db::startTrans();
        try {
            $this->lockTrialChannel();
            $service = \plugin\SandIam\app\model\Service::where('code', self::SERVICE_CODE)
                ->where('status', 1)->find();
            if ($service === null) {
                throw new ApiProblem('SAND_AI_TRIAL_CATALOG_INCOMPLETE', '系统准备未完成，请先在「首次开通」页完成系统准备后重试，或改用调用密钥');
            }
            $granted = [];
            foreach ($actionCodes as $code) {
                $action = \plugin\SandIam\app\model\ServiceAction::where('service_id', (int) $service->id)
                    ->where('code', $code)->where('status', 1)->find();
                if ($action === null) {
                    throw new ApiProblem('SAND_AI_TRIAL_CATALOG_INCOMPLETE', '系统准备未完成，请先在「首次开通」页完成系统准备后重试，或改用调用密钥');
                }
                $grant = $this->createOrFind(
                    \plugin\SandIam\app\model\ServiceGrant::class,
                    [
                        'workload_client_id' => $workloadClientId,
                        'service_action_id' => (int) $action->id,
                        'audience' => self::AUDIENCE,
                    ],
                    [
                        'workload_client_id' => $workloadClientId,
                        'service_action_id' => (int) $action->id,
                        'audience' => self::AUDIENCE,
                        'status' => 1,
                    ],
                );
                if ((int) $grant->status !== 1 || $grant->revoked_time !== null) {
                    throw new ApiProblem('SAND_AI_TRIAL_GRANT_INACTIVE', '管理员试用授权已被停用，请改用调用密钥');
                }
                $granted[$code] = (int) $action->id;
            }
            Db::commit();
            return $granted;
        } catch (Throwable $exception) {
            Db::rollback();
            if ($exception instanceof ApiProblem) {
                throw $exception;
            }
            throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '暂时无法准备试用授权，请稍后重试或改用调用密钥');
        }
    }

    /** @return array{id:int,key_prefix:string,credential:string} */
    public function issueCredential(
        int $workloadClientId,
        string $expireTime,
        string $requestId,
        string $actor,
        ?int $organizationId,
        ?int $applicationId,
    ): array {
        $issued = (new \plugin\SandIam\app\service\CredentialIssuanceService())->issue(
            $workloadClientId,
            '管理员试用 ' . $actor,
            $expireTime,
            $requestId,
            $actor,
            $organizationId,
            $applicationId,
        );
        return [
            'id' => (int) $issued['id'],
            'key_prefix' => (string) $issued['key_prefix'],
            'credential' => (string) $issued['credential'],
        ];
    }

    /**
     * Natural-key upsert inside the caller's advisory-locked transaction; the
     * unique constraint still resolves a concurrent insert racing the lock.
     *
     * @param class-string $class
     * @param array<string,mixed> $where
     * @param array<string,mixed> $payload
     */
    private function createOrFind(string $class, array $where, array $payload): object
    {
        $model = $this->findBy($class, $where, true);
        if ($model !== null) {
            return $model;
        }
        try {
            return $class::create($payload);
        } catch (Throwable) {
            $raced = $this->findBy($class, $where, true);
            if ($raced !== null) {
                return $raced;
            }
            throw new ApiProblem('SAND_AI_TRIAL_IAM_UNAVAILABLE', '暂时无法准备试用身份，请稍后重试或改用调用密钥');
        }
    }

    /**
     * @param class-string $class
     * @param array<string,mixed> $where
     */
    private function findBy(string $class, array $where, bool $lock): ?object
    {
        $query = $class::where(array_key_first($where), reset($where));
        foreach (array_slice($where, 1, null, true) as $field => $value) {
            $query->where($field, $value);
        }
        if ($lock) {
            $query->lock(true);
        }
        $model = $query->find();
        return is_object($model) ? $model : null;
    }

    private function assertActive(object $model, string $label): void
    {
        if ((int) ($model->status ?? 0) !== 1) {
            throw new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', $label . '已停用，暂时无法签发试用身份；请改用调用密钥');
        }
    }

    /**
     * Same two-integer advisory key shape as OnboardingService::advisoryKey;
     * the key pair is fixed for the trial channel so concurrent issuances
     * serialize on the lock, not on unique-violation retries.
     */
    private function lockTrialChannel(): void
    {
        $parts = unpack('Nleft/Nright', substr(hash('sha256', 'sand_ai_admin_trial', true), 0, 8));
        $signed = static fn (int $value): int => $value > 0x7fffffff ? $value - 0x100000000 : $value;
        Db::query('SELECT pg_advisory_xact_lock(?, ?)', [
            $signed((int) $parts['left']),
            $signed((int) $parts['right']),
        ]);
    }
}
