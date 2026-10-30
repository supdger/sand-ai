<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

/** Append-only field-to-source link for a validated workflow file result. */
final class WorkflowFileResultSource extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_workflow_file_result_source';
}
