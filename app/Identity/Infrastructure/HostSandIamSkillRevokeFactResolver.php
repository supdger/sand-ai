<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;
use think\facade\Db;

/** Revocation authorizes persisted metadata without reopening withdrawn source content. */
final class HostSandIamSkillRevokeFactResolver implements ServiceInvocationFactResolver
{
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || $actionCode !== 'sand_ai.skill.publish'
            || preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D', $resourceKey, $parts) !== 1) {
            self::unverified();
        }
        foreach (array_slice($parts, 1) as $part) {
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || (string) $id !== $part) self::unverified();
        }
        $client = (new HostSandIamWorkloadClientFactResolver())->resolve($serviceCode, $actionCode, $parts[3]);
        $rows = Db::connect('pgsql')->query(
            "SELECT s.id,s.organization_id,s.application_id,s.environment_id,s.scope_type,s.scope_ref,
                    s.scope_source,v.id version_id,v.published_consumer_context,
                    kv.snapshot_sha256,kv.source_scope_sha256,kv.data_class,
                    p.consumer_organization_id,p.consumer_application_id,p.consumer_environment_id
             FROM sand_ai_skill s
             JOIN sand_ai_skill_version v ON v.skill_id=s.id AND v.version_no=?
             JOIN sand_ai_skill_knowledge_ref r ON r.skill_version_id=v.id
             JOIN sand_ai_knowledge_base_version kv ON kv.id=r.knowledge_base_version_id
                 AND kv.knowledge_base_id=r.knowledge_base_id AND kv.snapshot_sha256=r.snapshot_sha256
             JOIN sand_ai_knowledge_base kb ON kb.id=kv.knowledge_base_id
             JOIN sand_ai_knowledge_publication p ON p.knowledge_base_id=kb.id
                 AND p.owner_environment_id=kb.environment_id AND p.consumer_environment_id=s.environment_id
             WHERE s.id=? AND s.delete_time IS NULL AND s.state IN ('published','revoked')
                 AND v.state='published' AND kv.state='published'
             FOR SHARE OF s,v,r,kv,kb,p",
            [(int) $parts[2], (int) $parts[1]],
        );
        if (count($rows) !== 1) self::unverified();
        $row = $rows[0];
        foreach (['organization_id', 'application_id', 'environment_id'] as $field) {
            if ((int) $row[$field] !== $client[$field]
                || (int) $row['consumer_' . $field] !== $client[$field]) self::unverified();
        }
        if ($row['scope_source'] !== 'consumer_declared'
            || !in_array($row['scope_type'], ['personal', 'team', 'organization'], true)
            || !is_string($row['scope_ref']) || $row['scope_ref'] === ''
            || !is_string($row['snapshot_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $row['snapshot_sha256']) !== 1
            || !is_string($row['source_scope_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $row['source_scope_sha256']) !== 1
            || ($row['data_class'] !== null && (!is_string($row['data_class'])
                || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $row['data_class']) !== 1))) {
            self::unverified();
        }
        $published = $row['published_consumer_context'];
        if (is_string($published)) {
            try {
                $published = json_decode($published, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                self::unverified();
            }
        }
        if (!is_array($published) || ($published['scope_source'] ?? null) !== 'consumer_declared'
            || ($published['actor_source'] ?? null) !== 'consumer_declared'
            || ($published['scope']['type'] ?? null) !== $row['scope_type']
            || ($published['scope']['ref'] ?? null) !== $row['scope_ref']) self::unverified();
        foreach (['organization_id', 'application_id', 'environment_id'] as $field) {
            if (($published[$field] ?? null) !== $client[$field]) self::unverified();
        }
        // The publisher may be a different current client in the same consumer scope.
        // Its immutable context proves this version's publication; current client facts
        // and the signed context authorize the present revoke operation independently.
        if (!is_int($published['workload_client_id'] ?? null) || $published['workload_client_id'] < 1
            || !is_string($published['actor_ref'] ?? null) || $published['actor_ref'] === '') self::unverified();
        return [
            ...$client,
            'data_class' => $row['data_class'],
            'resource_type' => 'sand_ai.skill',
            'resource_ref' => (string) $row['id'] . ':' . (string) $row['version_id'],
        ];
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
