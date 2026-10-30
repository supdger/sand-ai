<?php

declare(strict_types=1);
namespace app\Application\Composition;
use NeuronAI\Workflow\WorkflowResources;

/** Live IAM/DB collaborators are not serialized into an SDK checkpoint. */
final class CompositionResources extends WorkflowResources
{
    public function __construct(public readonly \Closure $selectReady, public readonly \Closure $execute)
    { parent::__construct(); }
}
