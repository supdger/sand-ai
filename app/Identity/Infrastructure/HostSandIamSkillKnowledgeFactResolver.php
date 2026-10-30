<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use plugin\SandIam\app\runtime\ServiceInvocationFactResolver;

/** Authorizes Skill preparation against its actual, pinned knowledge source. */
final class HostSandIamSkillKnowledgeFactResolver implements ServiceInvocationFactResolver
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, ['sand_ai.skill.read', 'sand_ai.skill.manage'], true)
            || preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18}):([1-9][0-9]{0,18})$/D', $resourceKey, $parts) !== 1) {
            self::unverified();
        }

        // The publication resolver checks the exact version's live publication,
        // owner, immutable source summary and real file classification.
        $knowledge = (new KnowledgePublicationFacts())->resolve(
            $serviceCode, 'sand_ai.knowledge.query', $resourceKey,
        );
        $client = (new HostSandIamWorkloadClientFactResolver())->resolve(
            $serviceCode, $actionCode, $parts[2],
        );
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if ($knowledge[$field] !== $client[$field]) self::unverified();
        }

        // The null classification of a client reference must never replace
        // the classification resolved from the persisted knowledge source.
        return $knowledge;
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
