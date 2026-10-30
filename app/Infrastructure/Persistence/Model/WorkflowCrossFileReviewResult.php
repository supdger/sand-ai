<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class WorkflowCrossFileReviewResult extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_workflow_cross_file_review_result';

    protected $json = ['payload'];

    protected $jsonAssoc = true;
}
