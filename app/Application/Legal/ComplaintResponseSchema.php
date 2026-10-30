<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use NeuronAI\StructuredOutput\Validation\Rules\Length;

final class ComplaintResponseSchema
{
    /** @var list<LegalFindingSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalFindingSchema::class])]
    #[ArrayOf(LegalFindingSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $findings;

    /** @var list<LegalRiskSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalRiskSchema::class])]
    #[ArrayOf(LegalRiskSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $risks;

    /** @var list<LegalUnverifiedSchema> */
    #[SchemaProperty(required: true, max: 30, anyOf: [LegalUnverifiedSchema::class])]
    #[ArrayOf(LegalUnverifiedSchema::class, allowEmpty: true)]
    #[Count(max: 30)]
    public array $unverified_items;

    #[SchemaProperty(required: true)]
    public LegalDraftSchema $draft;

    #[SchemaProperty(required: true)]
    public bool $review_required;

    #[SchemaProperty(required: true)]
    public ComplaintDraftSchema $complaint;
}
