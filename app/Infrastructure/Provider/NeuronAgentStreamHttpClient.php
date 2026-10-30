<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** Sole official SDK stream is bound to SandAI's already frozen request and once-send boundary. */
final class NeuronAgentStreamHttpClient implements HttpClientInterface
{
    private bool $requested = false;
    private ?NeuronPinnedAgentStream $stream = null;

    public function __construct(
        private readonly string $uri,
        private readonly string $resolveEntry,
        private readonly array $options,
        private readonly int $maximumBytes,
        private readonly string $bodyDigest,
        private readonly ProviderSendBoundary $boundary,
        private readonly bool $allowToolCalls = true,
        private readonly ?AgentToolChatRequest $toolRequest = null,
    ) {
        if (($allowToolCalls && ($toolRequest === null || !$toolRequest->stream || $toolRequest->tools === []))
            || (!$allowToolCalls && $toolRequest !== null)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Agent stream requires its frozen tool declarations');
        }
    }

    public function request(HttpRequest $request): HttpResponse { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Agent stream has no buffered transport'); }
    public function stream(HttpRequest $request): StreamInterface
    {
        if ($this->requested || $request->method !== HttpMethod::POST || $request->uri !== $this->uri
            || $request->headers !== $this->options['headers'] || $request->timeout !== null
            || !is_array($request->body)
            || ($this->allowToolCalls && ($request->body['tools'] ?? null) !== $this->toolRequest?->tools)
            || (!$this->allowToolCalls && ($request->body['tools'] ?? []) !== [])
            || !hash_equals($this->bodyDigest, ProviderRequestBodyBuilder::digest($request->body))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Agent stream differs from its frozen provider request');
        }
        $this->requested = true;
        return $this->stream = new NeuronPinnedAgentStream($this->uri, $this->resolveEntry, $this->options, $this->maximumBytes, $this->boundary, $this->allowToolCalls, $this->toolRequest);
    }
    public function completedUsage(): array
    {
        if ($this->stream === null) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official agent stream did not execute', true);
        $this->stream->assertComplete();
        return $this->stream->measuredUsage();
    }
    public function measuredFailure(?ApiProblem $problem=null,?\Throwable $previous=null): ?MeasuredChatFailure
    {
        $usage = $this->stream?->measuredUsage();
        return $usage === null ? null : new MeasuredChatFailure($usage['input_tokens'], $usage['output_tokens'],$problem,$previous);
    }
    public function close(): void { $this->stream?->close(); }
}
