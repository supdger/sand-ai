<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolVersion extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_version';
    protected $json = ['input_schema', 'output_schema', 'review_policy'];
    protected $jsonAssoc = true;
}
