<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class ComplaintSectionSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: 64)]
    #[Length(min: 1, max: 64)]
    public string $key;
    #[SchemaProperty(required: true, minLength: 1, maxLength: 160)]
    #[Length(min: 1, max: 160)]
    public string $title;
    #[SchemaProperty(required: true, minLength: 0, maxLength: 16000)]
    #[Length(min: 0, max: 16000)]
    public string $content;
    /** @var list<int> */
    #[SchemaProperty(required: true, max: 40)]
    #[ArrayOf('integer', allowEmpty: true)]
    #[Count(max: 40)]
    public array $source_block_ids;
}
