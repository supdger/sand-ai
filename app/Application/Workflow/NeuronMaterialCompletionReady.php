<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\Event;

final class NeuronMaterialCompletionReady implements Event
{
    public function __construct(
        public readonly NeuronMaterialPromptReady $prepared,
        public readonly string $content,
    ) {
    }
}
