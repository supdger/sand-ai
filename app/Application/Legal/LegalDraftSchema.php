<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class LegalDraftSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: 64)]
    #[Length(min: 1, max: 64)]
    public string $kind;
    // Wire/profile limits remain frozen by LegalOutputContract and business validation.
    #[SchemaProperty(required: true, minLength: 0, maxLength: 500000)]
    #[Length(min: 0, max: 500000)]
    public string $content;
    /** @var list<int> */
    #[SchemaProperty(required: true, min: 0, max: 40)]
    #[ArrayOf('integer', allowEmpty: true)]
    #[Count(min: 0, max: 40)]
    public array $source_block_ids;
}
