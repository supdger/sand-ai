<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use NeuronAI\Workflow\Events\Event;

final class NeuronMaterialReviewCompleted implements Event
{
    public function __construct(
        public readonly NeuronMaterialReviewPrepared $prepared,
        public readonly string $content,
    ) {
    }
}
