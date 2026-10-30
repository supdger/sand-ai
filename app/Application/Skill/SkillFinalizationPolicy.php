<?php

declare(strict_types=1);

namespace app\Application\Skill;

/** One terminal lock order for fresh and receipt-recovered Skill outcomes. */
final class SkillFinalizationPolicy
{
    /** @return list<string> */
    public static function lockOrder(): array
    {
        return ['budget_window', 'reservation', 'invocation', 'usage_event', 'legacy_usage', 'audit'];
    }

    /** @return array{actor_type:string,actor_ref:string,action:string,recovered:bool} */
    public static function audit(bool $recovered, int $environmentId): array
    {
        return ['actor_type' => $recovered ? 'system' : 'api_credential', 'actor_ref' => $recovered ? 'skill-recovery' : (string) $environmentId, 'action' => $recovered ? 'sand_ai.skill.recovered' : 'sand_ai.skill.completed', 'recovered' => $recovered];
    }
}
