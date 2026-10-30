<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use think\facade\Db;

/** Resolves only parent ownership metadata for the two cost-read actions. */
final class HostSandIamCompositionCostFactResolver implements ServiceInvocationFactResolver
{
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, ['sand_ai.workflow.read', 'sand_ai.usage.read'], true)) {
            self::unverified();
        }
        $id = self::id($resourceKey);
        $rows = Db::connect('pgsql')->query(
            "SELECT r.id,r.environment_id,r.organization_id,r.application_id,r.workload_client_id
             FROM sand_ai_composition_run r JOIN sand_ai_composition_version v ON v.id=r.version_id
             JOIN sand_ai_composition_definition d ON d.id=v.definition_id
             WHERE r.id=? AND v.state='published' AND v.snapshot_sha256=r.snapshot_sha256
               AND d.environment_id=r.environment_id", [$id],
        );
        if (count($rows) !== 1) self::unverified();
        $row = $rows[0];
        return [
            'organization_id' => self::id($row['organization_id']),
            'application_id' => self::id($row['application_id']),
            'environment_id' => self::id($row['environment_id']),
            'workload_client_id' => self::id($row['workload_client_id']),
            // This resource is the owner's non-content cost metadata, not
            // a classification for the heterogeneous private child inputs.
            'data_class' => null,
            'resource_type' => 'sand_ai.composition_cost',
            'resource_ref' => (string) $id,
        ];
    }

    private static function id(mixed $value): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && (string) $id === $value) return (int) $id;
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
