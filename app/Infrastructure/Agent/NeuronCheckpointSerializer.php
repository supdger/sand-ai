<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Workflow\Persistence\Serializer;

/** Only the official text Agent graph's inert values can be reconstructed; never runtime services. */
final class NeuronCheckpointSerializer implements Serializer
{
    private const CLASSES = [
        'NeuronAI\\Workflow\\Executor\\WorkflowControl', 'NeuronAI\\Workflow\\Executor\\Ignition',
        'NeuronAI\\Workflow\\Executor\\StepResult', 'NeuronAI\\Workflow\\WorkflowStatus',
        'NeuronAI\\Workflow\\Events\\StartEvent', 'NeuronAI\\Workflow\\Events\\StopEvent',
        'NeuronAI\\Agent\\AgentState', 'NeuronAI\\Agent\\InferenceRequest', 'NeuronAI\\Agent\\AgentRunOptions',
        'NeuronAI\\Agent\\Events\\AgentStartEvent', 'NeuronAI\\Agent\\Events\\AIInferenceEvent',
        'NeuronAI\\Agent\\Events\\ToolCallEvent', 'NeuronAI\\Agent\\Events\\AgentOutputEvent',
        'NeuronAI\\Providers\\ProviderResponse', 'NeuronAI\\Chat\\Messages\\Message',
        'NeuronAI\\Chat\\Messages\\UserMessage', 'NeuronAI\\Chat\\Messages\\AssistantMessage',
        'NeuronAI\\Chat\\Messages\\SystemMessage', 'NeuronAI\\Chat\\Messages\\ToolCallMessage',
        'NeuronAI\\Chat\\Messages\\ToolResultMessage', 'NeuronAI\\Chat\\Messages\\Usage',
        'NeuronAI\\Chat\\Enums\\MessageRole', 'NeuronAI\\Chat\\Messages\\ContentBlocks\\TextContent',
        'NeuronAI\\Chat\\Messages\\ContentBlocks\\SystemContent', 'NeuronAI\\Chat\\Messages\\ContentBlocks\\ReasoningContent',
        'NeuronAI\\Tools\\ToolCall', 'NeuronAI\\Tools\\ToolOutput', 'NeuronAI\\Tools\\ApprovalState',
        'stdClass',
    ];

    public function serialize(mixed $value): string
    {
        $this->assertValues($value, new \SplObjectStorage());
        return serialize($value);
    }

    public function unserialize(string $data): mixed
    {
        $value = @unserialize($data, ['allowed_classes' => self::CLASSES, 'max_depth' => 128]);
        if ($value === false && $data !== serialize(false)) $this->invalid();
        $this->assertValues($value, new \SplObjectStorage());
        return $value;
    }

    private function assertValues(mixed $value, \SplObjectStorage $seen, int $depth = 0): void
    {
        if ($depth > 128 || is_resource($value)) $this->invalid();
        if (is_object($value)) {
            if (!in_array($value::class, self::CLASSES, true)) $this->invalid();
            if ($seen->contains($value)) return;
            $seen->attach($value);
            foreach ((array) $value as $item) $this->assertValues($item, $seen, $depth + 1);
        } elseif (is_array($value)) {
            foreach ($value as $item) $this->assertValues($item, $seen, $depth + 1);
        }
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Private agent checkpoint has an unsupported value');
    }
}
