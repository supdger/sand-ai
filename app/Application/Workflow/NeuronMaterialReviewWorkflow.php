<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use LogicException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;

/** Official prepare/completion/validation graph for a new review attempt only. */
final class NeuronMaterialReviewWorkflow extends Workflow
{
    public function __construct(NeuronMaterialReviewResources $resources)
    {
        parent::__construct('sandai_material_review_local');
        $this->setPersistence(new InMemoryPersistence());
        $this->setResources(static fn (): NeuronMaterialReviewResources => $resources);
        $this->setLeaseTimeout(null);
        $this->setMaxSteps(3);
        $this->retainCompletionUntilAcknowledged(false);
        $this->addNodes([
            new NeuronMaterialReviewPrepareNode(),
            new NeuronMaterialReviewCompletionNode(),
            new NeuronMaterialReviewValidationNode(),
        ]);
    }

    public function review(): void
    {
        // Never resume SDK journals or recover failed nodes: provider recovery
        // is an explicit SandAI receipt/lease operation, outside this graph.
        $state = $this->run(ExecutionRequest::start(new StartEvent(), recoverFailed: false));
        if ($state->get('material_review_committed') !== true) {
            throw new LogicException('The material review graph did not seal its validated result.');
        }
    }
}
