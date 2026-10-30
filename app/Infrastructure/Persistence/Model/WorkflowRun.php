<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class WorkflowRun extends SandAiModel
{
    protected $table = 'sand_ai_workflow_run';

    protected $json = ['definition_snapshot', 'input_snapshot', 'file_snapshot', 'attribution_snapshot', 'execution_snapshot'];

    protected $hidden = ['attribution_snapshot', 'execution_snapshot', 'logical_execution_id', 'consumer_ack_ref'];
}
