<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class LegalRiskSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: 2000)]
    #[Length(min: 1, max: 2000)]
    public string $description;
    /** @var list<int> */
    #[SchemaProperty(required: true, min: 1, max: 40)]
    #[ArrayOf('integer', allowEmpty: false)]
    #[Count(min: 1, max: 40)]
    public array $source_block_ids;
}
