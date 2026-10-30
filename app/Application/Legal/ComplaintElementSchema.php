<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class ComplaintElementSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: 2000)]
    #[Length(min: 1, max: 2000)]
    public string $element;
    /** @var list<string> */
    #[SchemaProperty(required: true, max: 30)]
    #[ArrayOf('string', allowEmpty: true)]
    #[Count(max: 30)]
    public array $fact_refs;
    /** @var list<string> */
    #[SchemaProperty(required: true, max: 30)]
    #[ArrayOf('string', allowEmpty: true)]
    #[Count(max: 30)]
    public array $evidence_refs;
    /** @var list<int> */
    #[SchemaProperty(required: true, max: 40)]
    #[ArrayOf('integer', allowEmpty: false)]
    #[Count(min: 1, max: 40)]
    public array $source_block_ids;
}
