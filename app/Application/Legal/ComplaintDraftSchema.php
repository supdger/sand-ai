<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class ComplaintDraftSchema
{
    /** @var list<ComplaintSectionSchema> */
    #[SchemaProperty(required: true, max: 6, anyOf: [ComplaintSectionSchema::class])]
    #[ArrayOf(ComplaintSectionSchema::class, allowEmpty: false)]
    #[Count(max: 6)]
    public array $sections;

    /** @var list<ComplaintElementSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [ComplaintElementSchema::class])]
    #[ArrayOf(ComplaintElementSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $element_matrix;

    /** @var list<LegalUnverifiedSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalUnverifiedSchema::class])]
    #[ArrayOf(LegalUnverifiedSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $possible_defenses;

    /** @var list<LegalUnverifiedSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalUnverifiedSchema::class])]
    #[ArrayOf(LegalUnverifiedSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $missing_evidence;

    /** @var list<LegalUnverifiedSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalUnverifiedSchema::class])]
    #[ArrayOf(LegalUnverifiedSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $pending_decisions;

}
