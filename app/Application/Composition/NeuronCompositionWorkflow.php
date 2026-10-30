<?php

declare(strict_types=1);
namespace app\Application\Composition;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;

/** The SDK executes the graph. SandAI's node ledger decides every once-only side effect. */
final class NeuronCompositionWorkflow
{
    public function run(CompositionResources $resources): void
    {
        $workflow = (new Workflow('sandai-composition-local'))
            ->setPersistence(new InMemoryPersistence())
            ->setResources(static fn (): CompositionResources => $resources)
            ->setLeaseTimeout(null)->setMaxSteps(25)->retainCompletionUntilAcknowledged(false)
            ->addNodes([new CompositionSelectNode(), new CompositionExecuteNode()]);
        // A fresh graph tick; SDK failed journals must not resend a provider-visible node.
        $workflow->run(ExecutionRequest::start(new StartEvent(), recoverFailed: false));
    }
}
