<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

/** Append-only validated result for one frozen workflow file. */
final class WorkflowFileResult extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_workflow_file_result';

    protected $json = ['payload'];
}
