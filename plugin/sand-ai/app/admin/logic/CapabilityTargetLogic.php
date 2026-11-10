<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Api\Support\ApiProblem;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;

/**
 * Resolves the optional application/environment pair for capability profile
 * operations. Explicit identifiers keep their legacy meaning; omitted ones
 * are filled only when the administrator's manageable scope yields exactly
 * one application and that application exactly one enabled environment.
 */
final class CapabilityTargetLogic
{
    /**
     * @param array<string,mixed> $input raw request fields
     * @return array{application_id:?int,environment_id:int}
     */
    public function resolve(array $input, int $adminId, ?array $adminRecord): array
    {
        $applicationId = $this->optionalId($input['application_id'] ?? null, 'application_id');
        $environmentId = $this->optionalId($input['environment_id'] ?? null, 'environment_id');
        $iam = $this->iamAvailable();
        if ($environmentId !== null) {
            if ($applicationId !== null && $iam) {
                $this->assertEnvironmentApplication($environmentId, $applicationId);
            }
            return [
                'application_id' => $applicationId ?? ($iam ? $this->applicationOf($environmentId) : null),
                'environment_id' => $environmentId,
            ];
        }
        if (!$iam) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '暂时无法识别应用和环境，请手动选择后重试');
        }
        $applicationId ??= $this->soleApplicationId($adminId, $adminRecord);
        if (Application::where('id', $applicationId)->find() === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '没有找到这个应用，请刷新后重试');
        }
        /** @var list<int> $environmentIds */
        $environmentIds = array_values(array_unique(array_map(
            'intval',
            Environment::where('application_id', $applicationId)->where('status', 1)->order('id', 'asc')->column('id'),
        )));
        $environmentId = self::soleCandidate(
            $environmentIds,
            '这个应用还没有可用环境，请先创建环境',
            '这个应用有多个环境，请选择要配置的环境',
        );

        return ['application_id' => $applicationId, 'environment_id' => $environmentId];
    }

    /**
     * The unique-candidate rule shared by application and environment
     * resolution: exactly one candidate wins; zero or several refuse rather
     * than guess.
     *
     * @param list<int> $ids
     */
    public static function soleCandidate(array $ids, string $emptyMessage, string $manyMessage): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (count($ids) === 0) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $emptyMessage);
        }
        if (count($ids) > 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', $manyMessage);
        }

        return $ids[0];
    }

    private function iamAvailable(): bool
    {
        return class_exists(Application::class)
            && class_exists(Environment::class)
            && class_exists(AdminOrganizationAccess::class);
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A positive identifier is required');
        }

        return (int) $id;
    }

    /** @param array<string,mixed>|null $adminRecord */
    private function soleApplicationId(int $adminId, ?array $adminRecord): int
    {
        $access = new AdminOrganizationAccess($adminId, $adminRecord);
        $ids = $access->isSuperAdmin()
            ? Application::where('status', 1)->column('id')
            : $access->applicationIds();

        return self::soleCandidate(
            is_array($ids) ? $ids : [],
            '还没有可管理的应用，请先开通应用',
            '有多个应用，请选择要配置的应用',
        );
    }

    private function applicationOf(int $environmentId): ?int
    {
        $applicationId = Environment::where('id', $environmentId)->value('application_id');

        return $applicationId === null ? null : (int) $applicationId;
    }

    private function assertEnvironmentApplication(int $environmentId, int $applicationId): void
    {
        $environment = Environment::where('id', $environmentId)->find();
        if ($environment === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '没有找到这个环境，请刷新后重试');
        }
        if ((int) $environment->application_id !== $applicationId) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '所选环境不属于所选应用，请刷新后重试');
        }
    }
}
