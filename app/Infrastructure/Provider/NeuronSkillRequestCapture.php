<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** Captures the SDK-produced request for the existing pre-dispatch ledger. No I/O. */
final class NeuronSkillRequestCapture implements HttpClientInterface
{
    /** @var array<string,mixed>|null */
    private ?array $body = null;

    public function request(HttpRequest $request): HttpResponse
    {
        if ($this->body !== null || $request->method !== HttpMethod::POST || !is_array($request->body)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Official Skill request preparation is invalid');
        }
        $this->body = $request->body;
        throw new NeuronSkillRequestCaptured();
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill output strategies do not stream');
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return $this->body ?? throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Official Skill request was not prepared');
    }
}
