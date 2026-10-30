<?php

declare(strict_types=1);

namespace app\Application\Legal;

use LogicException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;

/** Official Neuron graph for one legal analysis; no second durable journal. */
final class NeuronLegalAnalysisWorkflow extends Workflow
{
    public function __construct(NeuronLegalAnalysisResources $resources)
    {
        parent::__construct('sandai_legal_analysis_local');
        $this->setPersistence(new InMemoryPersistence());
        $this->setResources(static fn (): NeuronLegalAnalysisResources => $resources);
        $this->setLeaseTimeout(null);
        $this->setMaxSteps(3);
        $this->retainCompletionUntilAcknowledged(false);
        $this->addNodes([
            new NeuronLegalPrepareNode(),
            new NeuronLegalCompletionNode(),
            new NeuronLegalValidationNode(),
        ]);
    }

    /** @return array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>} */
    public function analyze(): array
    {
        $state = $this->run(ExecutionRequest::start(new StartEvent(), recoverFailed: false));
        $result = $state->get('legal_analysis_result');
        if (!is_array($result) || !is_array($result['payload'] ?? null)
            || !is_array($result['field_sources'] ?? null)) {
            throw new LogicException('The legal analysis graph did not return its validated result.');
        }
        return $result;
    }
}
