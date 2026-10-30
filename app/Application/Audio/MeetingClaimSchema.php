<?php

declare(strict_types=1);

namespace app\Application\Audio;

use NeuronAI\StructuredOutput\SchemaProperty;

/** Wire shape only; the existing Evidence validator checks original scalar types and real citations. */
final class MeetingClaimSchema
{
    #[SchemaProperty(required: true, minLength: 1, maxLength: MeetingSummaryEvidence::MAX_CLAIM_CHARS)]
    public string $text;

    /** @var list<string> */
    #[SchemaProperty(required: true, min: 1, max: MeetingSummaryEvidence::MAX_CLAIM_SOURCES)]
    public array $segment_ids;
}
