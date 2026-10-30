<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Enum;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

/** Used by the real prompt's JsonSchema and the real output's typed validator. */
final class MaterialAnalysisCandidateSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: MaterialAnalysisProfilePolicy::MAX_DOCUMENT_CLASSIFICATION_CHARACTERS)]
    #[Length(min: 1, max: MaterialAnalysisProfilePolicy::MAX_DOCUMENT_CLASSIFICATION_CHARACTERS)]
    public string $value;

    /** @var list<int> */
    #[SchemaProperty(required: true, min: 1)]
    #[ArrayOf('integer')]
    #[Count(min: 1)]
    public array $source_block_ids;

    #[SchemaProperty(required: true)]
    #[Enum(class: MaterialAnalysisEvidenceState::class)]
    public MaterialAnalysisEvidenceState $evidence_state;
}
