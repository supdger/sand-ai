<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use LogicException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;

/**
 * Official Neuron graph for one material analysis invocation.
 *
 * Its journal is private process memory. Task/file recovery, provider identity and
 * accounting remain with SandAI's canonical execution records and completion client.
 */
final class NeuronMaterialAnalysisWorkflow extends Workflow
{
    public function __construct(NeuronMaterialAnalysisResources $resources)
    {
        parent::__construct('sandai_material_analysis_local');
        $this->setPersistence(new InMemoryPersistence());
        $this->setResources(static fn (): NeuronMaterialAnalysisResources => $resources);
        $this->setLeaseTimeout(null);
        $this->setMaxSteps(3);
        $this->retainCompletionUntilAcknowledged(false);
        $this->addNodes([
            new NeuronMaterialPrepareNode(),
            new NeuronMaterialCompletionNode(),
            new NeuronMaterialValidationNode(),
        ]);
    }

    /**
     * @return array{prompt:MaterialAnalysisPrompt,manifest:array{payload:array<string,mixed>,field_sources:list<array{field_path:string,source_block_id:int}>}}
     */
    public function analyze(): array
    {
        // A fresh graph per executor call; failed SDK journals must never trigger recovery.
        $state = $this->run(ExecutionRequest::start(new StartEvent(), recoverFailed: false));
        $result = $state->get('material_analysis_result');
        if (!is_array($result) || !($result['prompt'] ?? null) instanceof MaterialAnalysisPrompt
            || !is_array($result['manifest'] ?? null)) {
            throw new LogicException('The material analysis graph did not return its validated result.');
        }
        return $result;
    }
}
