<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Application\Skill\SkillKnowledgePublication;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\SkillKnowledgeRef;
use app\Infrastructure\Persistence\Model\SkillVersion;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;

/** Skill content scope is checked by SandAI, never promoted to IAM identity facts. */
final class HostSandIamSkillManagementFactResolver implements ServiceInvocationFactResolver
{
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, [
            'sand_ai.skill.read', 'sand_ai.skill.manage', 'sand_ai.skill.test', 'sand_ai.skill.publish', 'sand_ai.skill.run',
        ], true) || preg_match('/^([1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)$/D', $resourceKey, $parts) !== 1) {
            throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        }
        $skill = Skill::where('id', (int) $parts[1])->whereNull('delete_time')->find();
        $version = SkillVersion::where('skill_id', (int) $parts[1])->where('version_no', (int) $parts[2])->find();
        $client = (new HostSandIamWorkloadClientFactResolver())->resolve($serviceCode, $actionCode, $parts[3]);
        if ($skill === null || $version === null || $skill->scope_source !== 'consumer_declared'
            || !in_array($skill->scope_type, ['personal', 'team', 'organization'], true)
            || (int) $skill->organization_id !== $client['organization_id']
            || (int) $skill->application_id !== $client['application_id']
            || (int) $skill->environment_id !== $client['environment_id']) {
            throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        }
        if ($actionCode === 'sand_ai.skill.run' && ((int) $skill->status !== 1
            || (string) $skill->state !== 'published' || (string) $version->state !== 'published')) {
            throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        }
        $reference = SkillKnowledgeRef::where('skill_version_id', (int) $version->id)->find();
        if ($reference === null) throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
        $knowledge = (new SkillKnowledgePublication())->resolve(
            (int) $skill->environment_id, (int) $reference->knowledge_base_version_id,
            (string) $reference->snapshot_sha256,
        );
        return [
            ...$client,
            'data_class' => $knowledge['data_class'],
            'resource_type' => 'sand_ai.skill',
            'resource_ref' => (string) $skill->id . ':' . (int) $version->id,
        ];
    }
}
