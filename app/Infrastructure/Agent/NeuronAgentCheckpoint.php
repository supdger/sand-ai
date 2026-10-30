<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\WorkflowRunStore;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowStatus;

/** Server-owned original run binding. Current IAM and source decisions stay with the gateway. */
final readonly class NeuronAgentCheckpoint
{
    public function __construct(public PersistenceInterface $persistence, public string $workflowId,
        public string $runId, public bool $recoverFailed = false, public ?\Closure $assertReplayCurrent = null)
    {
        if ($workflowId === '' || str_starts_with($workflowId, '__') || strlen($workflowId) > 255
            || $runId === '' || strlen($runId) > 255) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Agent checkpoint binding is invalid');
    }

    public function request(Event $event, array $runtime): ExecutionRequest
    {
        $store = new WorkflowRunStore($this->persistence, new NeuronCheckpointSerializer(), $this->workflowId);
        $control = $store->loadControl();
        if ($control !== null && !hash_equals($this->runId, $control->runId)) $this->invalid();
        if ($this->recoverFailed && ($control === null || $store->loadIgnition() === null || !is_string($runtime['definition_sha256']??null)
            || ($control->status !== WorkflowStatus::Failed && $control->status !== WorkflowStatus::Completed
                && !($control->status === WorkflowStatus::Running && $control->leaseExpiresAt !== null && $control->leaseExpiresAt <= time())))) $this->invalid();
        if ($control !== null) {
            if ($this->assertReplayCurrent === null) $this->invalid();
            ($this->assertReplayCurrent)($runtime);
            if ($control->status === WorkflowStatus::Completed) {
                return ExecutionRequest::resume(expectedRunId: $this->runId, expectedExecutionAttempt: $control->executionAttempt);
            }
        }
        return ExecutionRequest::start($event, $this->runId, recoverFailed: $this->recoverFailed);
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'The original recoverable agent checkpoint is unavailable');
    }
}
