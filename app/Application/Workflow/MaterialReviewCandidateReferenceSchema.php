<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class MaterialReviewCandidateReferenceSchema
{
    #[SchemaProperty(required: true, min: 1)]
    public int $workflow_run_file_id;

    #[SchemaProperty(required: true, minLength: 1)]
    #[Length(min: 1)]
    public string $field_path;
}
