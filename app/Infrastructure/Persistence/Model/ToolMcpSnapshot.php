<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolMcpSnapshot extends SandAiModel
{
    protected $table = 'sand_ai_tool_mcp_snapshot';
    protected $deleteTime = false;
    protected $json = ['metering', 'error_detail'];
    protected $jsonAssoc = true;
}
