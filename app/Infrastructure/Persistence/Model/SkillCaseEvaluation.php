<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class SkillCaseEvaluation extends ImmutableSandAiModel
{
    protected $json = ['consumer_context'];
    protected $jsonAssoc = true;

    protected $table = 'sand_ai_skill_case_evaluation';
}
