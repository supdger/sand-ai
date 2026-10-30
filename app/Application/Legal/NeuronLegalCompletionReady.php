<?php

declare(strict_types=1);

namespace app\Application\Legal;

use NeuronAI\Workflow\Events\Event;

final class NeuronLegalCompletionReady implements Event
{
    public function __construct(
        public readonly NeuronLegalPromptReady $prepared,
        public readonly string $content,
    ) {
    }
}
