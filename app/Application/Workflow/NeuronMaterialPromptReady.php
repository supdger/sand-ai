<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\Event;

final class NeuronMaterialPromptReady implements Event
{
    /** @param array<string,mixed> $profile */
    public function __construct(
        public readonly array $profile,
        public readonly MaterialAnalysisPrompt $prompt,
    ) {
    }
}
