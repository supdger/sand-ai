<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolOperation extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_operation';
    protected $json = ['result'];
    protected $jsonAssoc = true;
}
