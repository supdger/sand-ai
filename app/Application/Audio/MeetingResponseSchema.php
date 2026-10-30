<?php

declare(strict_types=1);

namespace app\Application\Audio;

use NeuronAI\StructuredOutput\SchemaProperty;

/** Map and merge share the five-array shape; their allowed facts remain stage-specific. */
final class MeetingResponseSchema
{
    /** @var list<MeetingClaimSchema> */
    #[SchemaProperty(required: true, min: 1, max: MeetingSummaryEvidence::MAX_CLAIMS, anyOf: [MeetingClaimSchema::class])]
    public array $summary;

    /** @var list<MeetingClaimSchema> */
    #[SchemaProperty(required: true, max: MeetingSummaryEvidence::MAX_CLAIMS, anyOf: [MeetingClaimSchema::class])]
    public array $key_points;

    /** @var list<MeetingClaimSchema> */
    #[SchemaProperty(required: true, max: MeetingSummaryEvidence::MAX_CLAIMS, anyOf: [MeetingClaimSchema::class])]
    public array $decisions;

    /** @var list<MeetingClaimSchema> */
    #[SchemaProperty(required: true, max: MeetingSummaryEvidence::MAX_CLAIMS, anyOf: [MeetingClaimSchema::class])]
    public array $action_items;

    /** @var list<MeetingClaimSchema> */
    #[SchemaProperty(required: true, max: MeetingSummaryEvidence::MAX_CLAIMS, anyOf: [MeetingClaimSchema::class])]
    public array $unresolved;
}
