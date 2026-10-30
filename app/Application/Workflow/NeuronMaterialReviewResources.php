<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Application\Task\TaskExecutionLease;
use Closure;
use NeuronAI\Workflow\WorkflowResources;

/** Existing SandAI services for one private, in-process official review graph. */
final class NeuronMaterialReviewResources extends WorkflowResources
{
    /**
     * The two named operations retain the gateway's private dispatch and storage
     * boundaries. Scheduling, events and failure propagation belong to Neuron.
     *
     * @param Closure(array<string,mixed>):string $completeReview
     * @param Closure(array<string,mixed>,string):void $storeReview
     */
    public function __construct(
        public readonly int $workflowRunId,
        public readonly TaskExecutionLease $lease,
        public readonly Closure $completeReview,
        public readonly Closure $storeReview,
        public readonly MaterialCrossFileReviewInputLoader $inputLoader = new MaterialCrossFileReviewInputLoader(),
        public readonly ?array $preparedInput = null,
    ) {
        parent::__construct();
    }
}
