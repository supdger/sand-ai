<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolSource extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_source';
    protected $json = ['locator'];
    protected $jsonAssoc = true;
}
