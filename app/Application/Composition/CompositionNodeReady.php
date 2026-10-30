<?php

declare(strict_types=1);
namespace app\Application\Composition;
use NeuronAI\Workflow\Events\Event;
final readonly class CompositionNodeReady implements Event
{
    public function __construct(public array $action) {}
}
