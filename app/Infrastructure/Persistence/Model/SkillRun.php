<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class SkillRun extends SandAiModel
{
    protected $table = 'sand_ai_skill_run';

    protected $deleteTime = 'delete_time';

    protected $json = ['consumer_context', 'input', 'output', 'citations', 'error_detail'];

    protected $jsonAssoc = true;
}
