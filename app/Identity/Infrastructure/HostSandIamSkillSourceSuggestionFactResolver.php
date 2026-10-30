<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\SkillSourceSuggestion;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;

/** Management facts use frozen content classification even after source access is revoked. */
final class HostSandIamSkillSourceSuggestionFactResolver implements ServiceInvocationFactResolver
{
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || $actionCode !== 'sand_ai.skill.manage'
            || preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/D', $resourceKey, $parts) !== 1) self::unverified();
        $row = SkillSourceSuggestion::where('id', (int) $parts[1])->find();
        $client = (new HostSandIamWorkloadClientFactResolver())->resolve($serviceCode, $actionCode, $parts[2]);
        if ($row === null || !is_array($row->consumer_context)) self::unverified();
        $consumer = $row->consumer_context;
        if (($consumer['scope_source'] ?? null) !== 'consumer_declared'
            || ($consumer['organization_id'] ?? null) !== $client['organization_id']
            || ($consumer['application_id'] ?? null) !== $client['application_id']
            || ($consumer['environment_id'] ?? null) !== $client['environment_id']
            || (int) $row->environment_id !== $client['environment_id']
            || (int) $row->knowledge_base_id < 1 || (int) $row->knowledge_base_version_id < 1
            || (int) $row->knowledge_publication_id < 1) self::unverified();
        $dataClass = $row->data_class;
        if ($dataClass !== null && (!is_string($dataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) self::unverified();
        return [...$client, 'data_class' => $dataClass,
            'resource_type' => 'sand_ai.skill_source_suggestion', 'resource_ref' => (string) $row->id];
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
