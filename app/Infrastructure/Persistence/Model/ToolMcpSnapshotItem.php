<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ToolMcpSnapshotItem extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_tool_mcp_snapshot_item';
    protected $json = ['remote_input_schema', 'remote_output_schema', 'input_schema', 'output_schema'];
    protected $jsonAssoc = true;
}
