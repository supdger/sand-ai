<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use Throwable;

/** One SDK summary inference through the existing authorized, accounted knowledge dispatch. */
final class NeuronKnowledgeSummaryProvider implements AIProviderInterface
{
    private ?string $instructions = null;
    private bool $toolsDisabled = false;
    private bool $attempted = false;
    private ?array $outcome = null;
    private ?Throwable $failure = null;

    public function __construct(private readonly string $model, private readonly \Closure $dispatch)
    {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        if ($this->attempted) $this->invalid();
        $this->instructions = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        if ($this->attempted || $tools !== []) $this->invalid();
        $this->toolsDisabled = true;
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->attempted || !$this->toolsDisabled || count($messages) !== 1
            || !$messages[0] instanceof UserMessage
            || !is_string($this->instructions) || trim($this->instructions) === '') {
            $this->invalid();
        }
        $this->attempted = true;
        try {
            $reply = ($this->dispatch)(NeuronTextWireMapper::map($messages, $this->instructions));
            if (!is_array($reply)) $this->invalid();
            $this->outcome = $reply;
            // Keep malformed/empty output with its real invocation and fees for the gateway's invalid_output state.
            return new ProviderResponse(new AssistantMessage(is_string($reply['content'] ?? null) ? $reply['content'] : ''));
        } catch (Throwable $failure) {
            $this->failure = $failure;
            throw $failure;
        }
    }

    /** Called outside official generateSummary's catch, so an unknown dispatch is never treated as no summary. */
    public function result(): array
    {
        if ($this->failure !== null) throw $this->failure;
        if (!$this->attempted || $this->outcome === null) $this->invalid();
        return $this->outcome;
    }

    public function stream(Message ...$messages): \Generator
    {
        return $this->streamUnsupported();
    }

    private function streamUnsupported(): \Generator
    {
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Knowledge summary uses its original non-stream dispatch');
        yield;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Knowledge summary has no structured retry');
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Knowledge summary transport is controlled by SandAI');
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_CONVERSATION_UNAVAILABLE', 'Official knowledge summary dispatch is invalid');
    }
}
