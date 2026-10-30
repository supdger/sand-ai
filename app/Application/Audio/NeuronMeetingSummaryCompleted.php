<?php

declare(strict_types=1);

namespace app\Application\Audio;

use NeuronAI\Workflow\Events\Event;

final class NeuronMeetingSummaryCompleted implements Event
{
    /** @param array<string,mixed> $response */
    public function __construct(
        public readonly NeuronMeetingSummaryPrepared $prepared,
        public readonly array $response,
    ) {
    }
}
