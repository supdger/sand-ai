<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class SkillVersion extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_skill_version';

    protected $json = ['consumer_context', 'provenance', 'published_consumer_context', 'input_schema', 'output_schema', 'review_policy', 'execution_snapshot'];

    protected $jsonAssoc = true;
}
