<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class MaterialReviewItemSchema
{
    #[SchemaProperty(required: true)]
    #[Enum(class: MaterialReviewReason::class)]
    public MaterialReviewReason $reason;

    #[SchemaProperty(required: true, minLength: 1, maxLength: 128)]
    #[Length(min: 1, max: 128)]
    public string $field;

    #[SchemaProperty(required: true, minLength: 1, maxLength: 2000)]
    #[Length(min: 1, max: 2000)]
    public string $summary;

    /** @var list<int> */
    #[SchemaProperty(required: true, min: 2)]
    #[ArrayOf('integer')]
    #[Count(min: 2)]
    public array $source_block_ids;

    /** @var list<MaterialReviewCandidateReferenceSchema> */
    #[SchemaProperty(required: true, min: 2, anyOf: [MaterialReviewCandidateReferenceSchema::class])]
    #[ArrayOf(MaterialReviewCandidateReferenceSchema::class)]
    #[Count(min: 2)]
    public array $affected_candidates;
}
