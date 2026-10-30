<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** Neuron consumes the sole bounded, DNS-pinned SandAI transport. */
final class NeuronControlledHttpClient implements HttpClientInterface
{
    private ?ProviderSendBoundary $boundary = null;
    private bool $requested = false;
    /** @var array{content:string,input_tokens:int,output_tokens:int,usage_measured:bool}|null */
    private ?array $result = null;

    /**
     * @param array<string,string> $headers
     * @param \Closure(ProviderSendBoundary):array{payload:array<string,mixed>,status:int,result:array{content:string,input_tokens:int,output_tokens:int,usage_measured:bool}} $send
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $headers,
        private readonly string $bodyDigest,
        private readonly \Closure $send,
    ) {
    }

    public function bindBoundary(ProviderSendBoundary $boundary): void
    {
        if ($this->boundary !== null) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'The material provider call cannot be reused');
        }
        $this->boundary = $boundary;
    }

    public function request(HttpRequest $request): HttpResponse
    {
        if ($this->boundary === null || $this->requested
            || $request->method !== HttpMethod::POST || $request->uri !== $this->uri
            || $request->headers !== $this->headers || $request->timeout !== null
            || !is_array($request->body)
            || !hash_equals($this->bodyDigest, ProviderRequestBodyBuilder::digest($request->body))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Neuron request differs from the frozen provider call');
        }
        $this->requested = true;
        $response = ($this->send)($this->boundary);
        $this->result = $response['result'];
        $payload = $response['payload'];
        // Unknown or partial usage must remain unknown. Neuron's default zero
        // counts are not evidence of a measured zero-token provider response.
        if ($this->result['usage_measured']) {
            $payload['usage'] = [
                'prompt_tokens' => $this->result['input_tokens'],
                'completion_tokens' => $this->result['output_tokens'],
            ];
        } else {
            unset($payload['usage']);
        }
        // The existing compatible protocol accepts an absent finish reason.
        // Neuron 4.0.2 requires a string; an empty value conveys no new claim.
        if (!is_string($payload['choices'][0]['finish_reason'] ?? null)) {
            $payload['choices'][0]['finish_reason'] = '';
        }
        return new HttpResponse(
            $response['status'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'This Neuron material call does not permit streaming');
    }

    /** @return array{content:string,input_tokens:int,output_tokens:int,usage_measured:bool} */
    public function completion(AssistantMessage $message): array
    {
        $content = $message->getContent();
        if ($this->result === null || !is_string($content) || $content !== $this->result['content']) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Neuron completion differs from the bounded provider response', true);
        }
        $usage = $message->getUsage();
        if ($this->result['usage_measured']
            && ($usage === null || $usage->inputTokens !== $this->result['input_tokens']
                || $usage->outputTokens !== $this->result['output_tokens'])) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Neuron usage differs from the measured provider response', true);
        }
        return [
            'content' => $content,
            'input_tokens' => $this->result['input_tokens'],
            'output_tokens' => $this->result['output_tokens'],
            'usage_measured' => $this->result['usage_measured'],
        ];
    }

    public function measuredFailure(): ?MeasuredChatFailure
    {
        return $this->result !== null && $this->result['usage_measured']
            ? new MeasuredChatFailure($this->result['input_tokens'], $this->result['output_tokens'])
            : null;
    }
}
