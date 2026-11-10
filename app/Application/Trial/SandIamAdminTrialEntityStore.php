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
    /**
     * The trial chain converges on the same canonical defaults as SandIAM's
     * access-matrix ensure-defaults path (AccessMatrixInput::DEFAULT_*):
     * organization `default`, application `default-app`, environment `prod`. Only the
     * workload client stays trial-dedicated (`admin_trial`) so revoking that
     * one client still closes the whole channel. Instances that already ran
     * the pre-unification chain adopted application `system`; when it exists
     * it is reused as the business application instead of splitting a second
     * application — its `prod` environment is then the canonical target.
     */
    private const LEGACY_APPLICATION_CODE = 'system';
    private const CLIENT_CODE = 'admin_trial';
    private const CLIENT_NAME = '管理员试用';
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
            \plugin\SandIam\app\admin\support\AccessMatrixInput::class,
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
            // Stability anchor: a trial client already living in a canonical
            // `prod` environment keeps that application/environment forever —
            // later-created default entities (e.g. ensure-defaults on an
            // upgraded instance) must never migrate the channel sideways.
            $client = null;
            $environment = null;
            $organizationId = 0;
            $applicationId = 0;
            $existingClient = \plugin\SandIam\app\model\WorkloadClient::where('code', self::CLIENT_CODE)
                ->order('id')->lock(true)->find();
            if ($existingClient !== null) {
                $anchor = \plugin\SandIam\app\model\Environment::where('id', (int) $existingClient->environment_id)
                    ->where('code', self::environmentCode())->where('status', 1)->lock(true)->find();
                if ($anchor !== null) {
                    $environment = $anchor;
                    $application = \plugin\SandIam\app\model\Application::where('id', (int) $anchor->application_id)
                        ->lock(true)->find();
                    if ($application === null) {
                        throw new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', '试用通道所属接入应用不可用，请改用调用密钥');
                    }
                    $this->assertActive($application, '接入应用');
                    $organizationId = (int) $application->organization_id;
                    $applicationId = (int) $application->id;
                    $client = $existingClient;
                }
            }
            if ($client === null) {
                // Canonical business application first (`default-app`, same as the
                // access-matrix chain); an existing pre-unification `system`
                // application is adopted so upgraded instances converge on
                // the environment their deployments are already bound to.
                $application = \plugin\SandIam\app\model\Application::where('code', self::applicationCode())
                    ->order('id')->lock(true)->find();
                if ($application === null) {
                    $application = \plugin\SandIam\app\model\Application::where('code', self::LEGACY_APPLICATION_CODE)
                        ->order('id')->lock(true)->find();
                }
                if ($application !== null) {
                    $organizationId = (int) $application->organization_id;
                    $applicationId = (int) $application->id;
                    $this->assertActive($application, '接入应用');
                } else {
                    $organization = $this->resolveDefaultOrganization();
                    $organizationId = (int) $organization->id;
                    $application = $this->createOrFind(
                        \plugin\SandIam\app\model\Application::class,
                        ['organization_id' => $organizationId, 'code' => self::applicationCode()],
                        [
                            'organization_id' => $organizationId,
                            'code' => self::applicationCode(),
                            'name' => self::applicationName(),
                            'status' => 1,
                        ],
                    );
                    $applicationId = (int) $application->id;
                    $this->assertActive($application, '接入应用');
                }
                $environment = $this->createOrFind(
                    \plugin\SandIam\app\model\Environment::class,
                    ['application_id' => $applicationId, 'code' => self::environmentCode()],
                    [
                        'application_id' => $applicationId,
                        'code' => self::environmentCode(),
                        'name' => self::environmentName(),
                        'status' => 1,
                    ],
                );
                $this->assertActive($environment, '应用环境');
                $client = $this->resolveTrialClient($applicationId, (int) $environment->id);
            }
            // A pre-existing row with this code but a different audience is an
            // operator-owned client, not the trial channel; never mutate it.
            if (!hash_equals(self::audience(), (string) $client->audience)) {
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
                        'audience' => self::audience(),
                    ],
                    [
                        'workload_client_id' => $workloadClientId,
                        'service_action_id' => (int) $action->id,
                        'audience' => self::audience(),
                        'data_class' => null,
                        'status' => 1,
                    ],
                );
                if ((int) $grant->status !== 1 || $grant->revoked_time !== null) {
                    throw new ApiProblem('SAND_AI_TRIAL_GRANT_INACTIVE', '管理员试用授权已被停用，请改用调用密钥');
                }
                // Trial-owned resources (invocations, uploaded files, speech
                // runs) are all unclassified, and catalog/metadata reads have
                // no data class at all: the grant must be NULL to satisfy the
                // exact-match contract. Rows the pre-unification chain left
                // at the 'internal' column default are normalized in place.
                if ($grant->data_class !== null) {
                    $grant->save(['data_class' => null]);
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
     * Default organization resolution mirroring the access-matrix
     * ensure-defaults path: the canonical `default` organization when active,
     * else the first active one, else find-or-create it.
     */
    private function resolveDefaultOrganization(): object
    {
        $organization = \plugin\SandIam\app\model\Organization::where('code', self::organizationCode())
            ->where('status', 1)->lock(true)->find();
        if ($organization === null) {
            $organization = \plugin\SandIam\app\model\Organization::where('status', 1)
                ->order('id')->lock(true)->find();
        }
        if ($organization === null) {
            $organization = $this->createOrFind(
                \plugin\SandIam\app\model\Organization::class,
                ['code' => self::organizationCode()],
                ['code' => self::organizationCode(), 'name' => self::organizationName(), 'status' => 1],
            );
        }
        $this->assertActive($organization, '客户主体');
        return $organization;
    }

    /**
     * Canonical-environment trial client. A dedicated trial client that the
     * pre-unification chain left under a different environment of the same
     * application is re-parented onto the canonical environment so grants and
     * already-issued credentials stay attached to one revocable identity.
     */
    private function resolveTrialClient(int $applicationId, int $environmentId): object
    {
        $client = \plugin\SandIam\app\model\WorkloadClient::where('environment_id', $environmentId)
            ->where('code', self::CLIENT_CODE)->lock(true)->find();
        if ($client !== null) {
            return $client;
        }
        $environmentIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            \plugin\SandIam\app\model\Environment::where('application_id', $applicationId)
                ->field('id')->select()->toArray(),
        );
        if ($environmentIds !== []) {
            $legacy = \plugin\SandIam\app\model\WorkloadClient::whereIn('environment_id', $environmentIds)
                ->where('code', self::CLIENT_CODE)->order('id')->lock(true)->find();
            if ($legacy !== null) {
                if (!hash_equals(self::audience(), (string) $legacy->audience)) {
                    throw new ApiProblem('SAND_AI_TRIAL_CLIENT_CONFLICT', '试用通道与既有调用身份冲突，请改用调用密钥');
                }
                if ((int) $legacy->status !== 1) {
                    throw new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', '管理员试用通道已被停用，请改用调用密钥');
                }
                $legacy->save(['environment_id' => $environmentId]);
                return $legacy;
            }
        }
        return \plugin\SandIam\app\model\WorkloadClient::create([
            'environment_id' => $environmentId,
            'code' => self::CLIENT_CODE,
            'name' => self::CLIENT_NAME,
            'audience' => self::audience(),
            'status' => 1,
        ]);
    }

    private static function organizationCode(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_ORGANIZATION_CODE;
    }

    private static function organizationName(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_ORGANIZATION_NAME;
    }

    private static function applicationCode(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_APPLICATION_CODE;
    }

    private static function applicationName(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_APPLICATION_NAME;
    }

    private static function environmentCode(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_ENVIRONMENT_CODE;
    }

    private static function environmentName(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_ENVIRONMENT_NAME;
    }

    private static function audience(): string
    {
        return \plugin\SandIam\app\admin\support\AccessMatrixInput::DEFAULT_AUDIENCE;
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
