<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\ExecutionContext;
use NeuronAI\Workflow\WorkflowStatus;

/** Shared by live SDK tool clones; durable private receipts and working history are fenced by official control. */
final class NeuronAgentExecutionJournal
{
    public const RECORD_KEY = '__sandai_runtime_v1';
    private array $runtime;
    private ?int $attempt = null;
    private NeuronCheckpointSerializer $serializer;

    public function __construct(private readonly ?NeuronAgentCheckpoint $checkpoint = null)
    {
        $this->serializer = new NeuronCheckpointSerializer();
        $raw = $checkpoint?->persistence->get($checkpoint->workflowId, self::RECORD_KEY);
        $this->runtime = $raw === null ? ['version' => 1, 'rounds' => [], 'retrieval' => [], 'readonly' => [],
            'summary' => null, 'summary_applied' => false, 'history' => null] : $this->serializer->unserialize($raw);
        if (!is_array($this->runtime) || ($this->runtime['version'] ?? null) !== 1 || !is_array($this->runtime['rounds'] ?? null)
            || !array_is_list($this->runtime['rounds']) || count($this->runtime['rounds']) > 4
            || !is_array($this->runtime['retrieval'] ?? null) || count($this->runtime['retrieval']) > 2
            || !is_array($this->runtime['readonly'] ?? null) || count($this->runtime['readonly']) > 2
            || !is_bool($this->runtime['summary_applied']??null) || !array_key_exists('summary',$this->runtime)
            || !array_key_exists('history',$this->runtime)) $this->invalid();
    }

    public function runtime(): array { return $this->runtime; }
    public function persistent(): bool { return $this->checkpoint !== null; }

    public function assertDefinition(string $sha256): void
    {
        if (isset($this->runtime['definition_sha256']) && !hash_equals((string)$this->runtime['definition_sha256'],$sha256)) $this->invalid();
        if ($this->checkpoint?->recoverFailed && !isset($this->runtime['definition_sha256'])) $this->invalid();
        $this->runtime['definition_sha256']=$sha256;
    }

    /** The official segment event carries its current attempt even while replayed step states still carry an older attempt. */
    public function captureAdmission(?ExecutionContext $context): void
    {
        if ($this->checkpoint===null) return;
        if ($context===null || $context->workflowId!==$this->checkpoint->workflowId || $context->runId!==$this->checkpoint->runId
            || ($this->attempt!==null && $this->attempt!==$context->executionAttempt)) $this->invalid();
        $this->attempt=$context->executionAttempt;
    }

    public function bind(WorkflowState $state): void
    {
        if ($this->checkpoint === null) return;
        if ($state->getWorkflowId() !== $this->checkpoint->workflowId || $state->getRunId() !== $this->checkpoint->runId
            || $this->attempt===null) $this->invalid();
        $state->setExecutionMetadata($this->checkpoint->workflowId,$this->checkpoint->runId,$this->attempt);
        // Initialize the private receipt record only after official admission, under its control fence.
        $this->save();
    }

    /** Reuse accounted round outcomes if a crash preceded the SDK memo commit. */
    public function round(int $number, string $wireSha256, \Closure $dispatch): array
    {
        $existing = $this->runtime['rounds'][$number - 1] ?? null;
        if ($existing !== null) {
            if (!is_array($existing) || !hash_equals((string) ($existing['wire_sha256'] ?? ''), $wireSha256)
                || !is_array($existing['outcome'] ?? null)) $this->invalid();
            return $existing['outcome'];
        }
        if ($number !== count($this->runtime['rounds']) + 1 || $number > 4) $this->invalid();
        $outcome = $dispatch();
        if (!is_array($outcome)) $this->invalid();
        $this->runtime['rounds'][] = ['wire_sha256' => $wireSha256, 'outcome' => $outcome];
        $this->save();
        return $outcome;
    }

    public function retrieval(string $callId, string $query): ?array
    {
        $receipt = $this->runtime['retrieval'][$callId] ?? null;
        if ($receipt !== null && (!is_array($receipt) || ($receipt['query'] ?? null) !== $query || !is_array($receipt['output'] ?? null))) $this->invalid();
        return $receipt;
    }

    public function recordRetrieval(string $callId, string $query, array $output, array $evidence): void
    {
        if (isset($this->runtime['retrieval'][$callId])) $this->invalid();
        $this->runtime['retrieval'][$callId] = ['query' => $query, 'output' => $output, 'evidence' => $evidence];
        $this->save();
    }

    public function retrievalExecutions(): array { return array_values(array_column($this->runtime['retrieval'], 'evidence')); }

    public function readonlyReceipt(string $callId, string $wireName, mixed $input): ?array
    {
        $receipt = $this->runtime['readonly'][$callId] ?? null;
        if ($receipt !== null && (!is_array($receipt) || ($receipt['wire_name'] ?? null) !== $wireName
            || serialize($receipt['input'] ?? null) !== serialize($input) || !is_array($receipt['output'] ?? null))) $this->invalid();
        return $receipt;
    }

    public function recordReadonly(string $callId, string $wireName, mixed $input, array $output, array $execution): void
    {
        if (isset($this->runtime['readonly'][$callId])) $this->invalid();
        $this->runtime['readonly'][$callId] = ['wire_name' => $wireName, 'input' => $input, 'output' => $output, 'execution' => $execution];
        $this->save();
    }

    public function readonlyExecutions(?string $wireName = null): array
    {
        $records = $wireName === null ? $this->runtime['readonly'] : array_filter($this->runtime['readonly'], static fn(array $record): bool => $record['wire_name'] === $wireName);
        return array_values(array_column($records, 'execution'));
    }

    public function summary(): ?array { return $this->runtime['summary']; }
    public function summaryApplied(): bool { return $this->runtime['summary_applied']; }
    public function recordSummary(array $coverage, array $outcome, string $wireSha256): void
    {
        $this->runtime['summary'] = ['coverage' => $coverage, 'outcome' => $outcome, 'wire_sha256' => $wireSha256];
        $this->save();
    }
    public function history(): ?array { return $this->runtime['history']; }
    public function recordHistory(array $messages, int $archived, bool $summaryApplied = false): void
    {
        if (count($messages) > 64 || $archived < 0 || $archived > count($messages)) $this->invalid();
        $this->runtime['history'] = ['messages' => $messages, 'archived' => $archived];
        if ($summaryApplied) $this->runtime['summary_applied'] = true;
        $this->save();
    }

    private function save(): void
    {
        if ($this->checkpoint === null) return;
        if ($this->attempt === null) $this->invalid();
        $raw = $this->checkpoint->persistence->get($this->checkpoint->workflowId, '__control');
        $control = $raw === null ? null : $this->serializer->unserialize($raw);
        if (!$control instanceof WorkflowControl || $control->runId !== $this->checkpoint->runId
            || $control->executionAttempt !== $this->attempt || $control->status !== WorkflowStatus::Running
            || ($control->leaseExpiresAt !== null && $control->leaseExpiresAt <= time())) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The original agent checkpoint execution fence was lost');
        }
        if (!$this->checkpoint->persistence->writeIfUnchanged($this->checkpoint->workflowId, '__control', $raw,
            [self::RECORD_KEY => $this->serializer->serialize($this->runtime)])) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'The original agent checkpoint execution fence changed');
        }
    }

    private function invalid(): never { throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Private agent execution receipts are inconsistent'); }
}
