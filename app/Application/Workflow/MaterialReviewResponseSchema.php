<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

final class MaterialReviewResponseSchema
{
    /** @var list<MaterialReviewItemSchema> */
    #[SchemaProperty(required: true, anyOf: [MaterialReviewItemSchema::class])]
    #[ArrayOf(MaterialReviewItemSchema::class, allowEmpty: true)]
    public array $items_to_verify;
}
