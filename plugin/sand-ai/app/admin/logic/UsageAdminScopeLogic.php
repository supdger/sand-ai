<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Api\Support\ApiProblem;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Environment;
use plugin\SandIam\app\model\Organization;

/** Consumes the official IAM administrator grants and historical environment directory. */
final class UsageAdminScopeLogic
{
    private const MAX_ENVIRONMENTS = 1000;

    /** @return array{service_code:string,coverage:string,environments:list<array{id:int,organization_id:int,application_id:int,name:string,code:string}>} */
    public function directory(mixed $adminInfo): array
    {
        if (!is_array($adminInfo) || !is_int($adminInfo['id'] ?? null) && !is_string($adminInfo['id'] ?? null)
            || filter_var($adminInfo['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ApiProblem('SAND_AI_USAGE_SCOPE_DENIED', 'Administrator identity is unavailable');
        }
        if (!class_exists(AdminOrganizationAccess::class) || !class_exists(Environment::class)
            || !class_exists(Application::class) || !class_exists(Organization::class)) {
            throw new ApiProblem('SAND_AI_USAGE_SCOPE_UNAVAILABLE', 'The official IAM administrator environment scope is unavailable');
        }
        // This is exactly IAM AdminResourceController::access()'s identity
        // construction. The controller supplies the host's checked admin token.
        $access = new AdminOrganizationAccess((int) $adminInfo['id'], $adminInfo);
        $superAdmin = $access->isSuperAdmin();
        // EnvironmentController's directory does not require status=1.
        // Disabled environments remain readable under its management scope.
        $query = Environment::order('id', 'asc');
        if (!$superAdmin) {
            $applicationIds = $access->applicationIds();
            if ($applicationIds === []) return ['service_code' => 'sand_ai', 'coverage' => 'authorized_environment_subtotal', 'environments' => []];
            $query->whereIn('application_id', $applicationIds);
        }
        $rows = $query->limit(self::MAX_ENVIRONMENTS + 1)->select();
        if (count($rows) > self::MAX_ENVIRONMENTS) {
            throw new ApiProblem('SAND_AI_USAGE_SCOPE_TOO_LARGE', 'The allowed environment directory exceeds the supported bounded scope');
        }
        $environments = [];
        foreach ($rows as $environment) {
            // Verify the real chain through IAM-owned models. Runtime active
            // credential validation cannot define historical management reads.
            $application = Application::where('id', (int) $environment->application_id)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->find();
            if ($application === null || $organization === null) continue;
            // applicationIds() already enforces this for delegated admins.
            // Recheck after directory selection without narrowing super-admin's
            // official directory scope to runtime-active application chains.
            if (!$superAdmin && ((int) $application->status !== 1 || (int) $organization->status !== 1)) continue;
            $facts = ['environment_id' => (int) $environment->id, 'application_id' => (int) $application->id, 'organization_id' => (int) $organization->id];
            if ($facts['environment_id'] < 1 || $facts['application_id'] < 1 || $facts['organization_id'] < 1
                || $facts['application_id'] !== (int) $environment->application_id
                || $facts['organization_id'] !== (int) $application->organization_id) {
                throw new ApiProblem('SAND_AI_USAGE_SCOPE_UNAVAILABLE', 'The official IAM environment directory returned inconsistent facts');
            }
            $environments[] = [
                'id' => $facts['environment_id'], 'organization_id' => $facts['organization_id'],
                'application_id' => $facts['application_id'], 'name' => (string) $environment->name,
                'code' => (string) $environment->code,
            ];
        }
        return ['service_code' => 'sand_ai', 'coverage' => 'authorized_environment_subtotal', 'environments' => $environments];
    }

    /** @param array<string,mixed> $filters @return list<array{id:int,organization_id:int,application_id:int,name:string,code:string}> */
    public function resolve(mixed $adminInfo, array $filters): array
    {
        $scope = $this->directory($adminInfo)['environments'];
        foreach (['environment_id' => 'id', 'organization_id' => 'organization_id', 'application_id' => 'application_id'] as $filter => $field) {
            if (($filters[$filter] ?? null) !== null) {
                $selected = $filters[$filter];
                $scope = array_values(array_filter($scope, static fn (array $row): bool => $row[$field] === $selected));
            }
        }
        if ($scope === [] && (($filters['environment_id'] ?? null) !== null
            || ($filters['organization_id'] ?? null) !== null || ($filters['application_id'] ?? null) !== null)) {
            throw new ApiProblem('SAND_AI_USAGE_SCOPE_DENIED', 'The selected organization, application or environment is outside the official allowed environment scope');
        }
        return $scope;
    }
}
