<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class SkillSourceSuggestion extends SandAiModel
{
    protected $table = 'sand_ai_skill_source_suggestion';
    protected $deleteTime = false;
    protected $json = ['consumer_context', 'input', 'evidence', 'suggestions', 'usage', 'adoption', 'accounting_outcome'];
    protected $jsonAssoc = true;
}
