<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolAttempt extends SandAiModel
{
    protected $table = 'sand_ai_tool_attempt';
    protected $deleteTime = false;
}
