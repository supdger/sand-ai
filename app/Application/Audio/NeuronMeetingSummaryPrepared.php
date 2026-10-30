<?php

declare(strict_types=1);

namespace app\Application\Audio;

use NeuronAI\Workflow\Events\Event;

final class NeuronMeetingSummaryPrepared implements Event
{
    /**
     * @param list<array{role:string,content:string}> $messages
     * @param array<string,array<string,mixed>> $sources
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $sources,
    ) {
    }
}
