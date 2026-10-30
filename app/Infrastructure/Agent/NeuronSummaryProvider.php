<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Provider\AgentToolChatRequest;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use Throwable;

/** Official Summarization provider port; one accounted child dispatch, never a transport or retry engine. */
final class NeuronSummaryProvider implements AIProviderInterface
{
    private ?string $instructions = null;
    private ?AgentToolChatRequest $request = null;
    private ?array $coverage = null;
    private ?array $outcome = null;
    private ?Throwable $failure = null;
    private bool $attempted = false;

    public function __construct(private readonly string $model, private readonly \Closure $dispatchSummary, private readonly ?NeuronAgentExecutionJournal $journal=null) {}
    public function getModel(): string { return $this->model; }

    public function prepareCoverage(array $coverage): void
    {
        if ($this->attempted || $this->coverage !== null) {
            throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Only one history summary is allowed per agent execution');
        }
        $this->coverage = $coverage;
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->instructions = $prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        if ($tools !== [] || $this->coverage === null) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'History summary must have no tools');
        }
        $this->request = new AgentToolChatRequest([], $this->coverage);
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->attempted || $this->request === null || count($messages) !== 1
            || !$messages[0] instanceof UserMessage || !is_string($this->instructions)
            || trim($this->instructions) === '') {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Official summary request is invalid');
        }
        $this->attempted = true;
        try {
            $wire = NeuronTextWireMapper::map($messages, $this->instructions);
            $this->request->assertMessages($wire);
            $wireSha256=hash('sha256',json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $cached=$this->journal?->summary();
            if ($cached!==null && (($cached['coverage']??null)!==$this->coverage || !hash_equals((string)($cached['wire_sha256']??''),$wireSha256))) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Recovered summary coverage is inconsistent');
            $result=$cached===null ? ($this->dispatchSummary)($wire, $this->request, $this->coverage) : $cached['outcome'];
            if (!is_array($result)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'History summary has no accounted outcome');
            }
            $this->outcome = $result;
            $semantic = [
                'content' => $result['content'] ?? null,
                'response_kind' => $result['response_kind'] ?? null,
                'tool_calls' => $result['tool_calls'] ?? null,
            ];
            $this->request->assertOutcome($semantic, $wire);
            $usage = $result['usage'] ?? null;
            if (!is_array($usage) || ($usage['usage_state'] ?? null) !== 'measured'
                || !is_int($usage['input_tokens'] ?? null) || $usage['input_tokens'] < 0
                || !is_int($usage['output_tokens'] ?? null) || $usage['output_tokens'] < 0
                || !is_int($result['invocation_id'] ?? null) || $result['invocation_id'] < 1
                || !is_array($result['invocation'] ?? null)
                || ($result['invocation']['state'] ?? null) !== 'succeeded'
                || !is_string($result['invocation']['request_id'] ?? null)) {
                throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'History summary usage or invocation is unavailable');
            }
            if ($cached===null) $this->journal?->recordSummary($this->coverage,$result,$wireSha256);
            $message = new AssistantMessage($semantic['content']);
            $message->setUsage(new Usage($usage['input_tokens'], $usage['output_tokens']));
            return new ProviderResponse($message, metadata: ['sandai_invocation_id' => $result['invocation_id']]);
        } catch (Throwable $failure) {
            $this->failure = $failure;
            throw $failure;
        }
    }

    /** Called outside SDK generateSummary's catch(Exception), so paid/unknown failures stop the main inference. */
    public function assertSuccessful(): void
    {
        if ($this->failure !== null) throw $this->failure;
        if (!$this->attempted || $this->outcome === null) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Official history summary did not complete');
        }
    }

    public function result(): ?array
    {
        if ($this->outcome === null) return null;
        $this->assertSuccessful();
        return [
            'coverage' => $this->coverage,
            'invocation' => $this->outcome['invocation'],
            'invocation_id' => $this->outcome['invocation_id'],
            'usage' => $this->outcome['usage'],
        ];
    }

    public function stream(Message ...$messages): \Generator { return $this->streamUnsupported(); }
    private function streamUnsupported(): \Generator { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'History summary uses its accounted non-stream dispatch'); yield; }
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'History summary has no structured retry'); }
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'History summary transport is controlled by SandAI'); }
}
