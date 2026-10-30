<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderIdempotencyCapability;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\ProviderResponseIdentity;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;

/**
 * Minimal OpenAI-compatible chat adapter. Provider-specific endpoints and keys
 * remain in encrypted configuration; business callers only see logical models.
 */
final class OpenAiCompatibleChatProvider
{
    public function __construct(
        private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]),
        private readonly ProviderEndpointResolver $endpointResolver = new HttpsProviderEndpointResolver(),
        private readonly OpenAiCompatibleExecutionConfigPolicy $configPolicy = new OpenAiCompatibleExecutionConfigPolicy(),
    )
    {
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array{role: string, content: string}> $messages
     * @return array{content: string, input_tokens: int, output_tokens: int, usage_measured: bool}
     */
    public function complete(array $config, string $remoteModel, array $messages, int $timeoutMs, ?ChatCompletionOptions $options = null, ?ProviderDispatchContext $dispatchContext = null): array
    {
        return $this->prepare($config, $remoteModel, $messages, $timeoutMs, $options, $dispatchContext)
            ->send(new ProviderSendBoundary());
    }

    /**
     * Finishes every local check before the dispatch ledger is provider-visible.
     * DNS pinning, body construction and idempotency validation therefore cannot
     * turn a never-sent request into an ambiguous Provider outcome.
     *
     * @param array<string,mixed> $config
     * @param list<array{role: string, content: string}> $messages
     */
    public function prepare(array $config, string $remoteModel, array $messages, int $timeoutMs, ?ChatCompletionOptions $options = null, ?ProviderDispatchContext $dispatchContext = null, bool $streamSegments = false, ?callable $acceptSegment = null, bool $useNeuron = false, ?AgentToolChatRequest $agentToolRequest = null, ?callable $acceptAgentChunk = null): PreparedChatProviderCall
    {
        $agentStream=$agentToolRequest?->stream??false;
        $skillOutput = $options?->skillOutput;
        if ($skillOutput !== null && (!$useNeuron || $agentToolRequest !== null || $streamSegments)) throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Skill output strategies require their official single-call transport');
        if ($agentToolRequest !== null && ($useNeuron || $streamSegments)) throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Agent rounds require their controlled transport');
        if (($acceptAgentChunk!==null)!==$agentStream || (($agentStream || $streamSegments) && (!class_exists(\NeuronAI\Providers\OpenAI\OpenAI::class)
            || !\Composer\InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) \Composer\InstalledVersions::getVersion('neuron-core/neuron-ai'), '4.0.2', '>=')))) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID','Official measured stream runtime is unavailable');
        }
        if ($useNeuron && ($streamSegments
            || !class_exists(\NeuronAI\Providers\OpenAI\OpenAI::class)
            || !\Composer\InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) \Composer\InstalledVersions::getVersion('neuron-core/neuron-ai'), '4.0.2', '>='))) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'The required Neuron material runtime is unavailable');
        }
        $config = $this->configPolicy->normalize($config);
        $endpoint = $this->endpointResolver->chatCompletions($config);
        $apiKey = $config['api_key'];
        if (trim($remoteModel) === '') {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is incomplete');
        }
        $responseMaxBytes = $config['response_max_bytes'];
        if ($streamSegments && $acceptSegment === null) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Validated segment receiver is required');
        }
        $headers = ['Authorization' => 'Bearer ' . $apiKey,
            'Accept' => ($streamSegments || $agentStream) ? 'text/event-stream' : 'application/json'];
        foreach ($config['extra_headers'] as $name => $value) {
            $headers[$name] = $value;
        }
        $payload = $this->requestBody($config, $remoteModel, $messages, $options, $streamSegments, $agentToolRequest);
        $bodyBytes = ProviderRequestBodyBuilder::bytes($payload);
        $capability = ProviderIdempotencyCapability::fromServerConfig('openai_compatible', $config);
        if ($dispatchContext !== null && !hash_equals($dispatchContext->bodyDigest, hash('sha256', $bodyBytes))) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Provider request body differs from the dispatch ledger');
        }
        if ($dispatchContext !== null && $capability->mode === 'provider_key') {
            $headers[(string) $capability->headerName] = (string) $dispatchContext->providerKey;
        }
        $headers['Content-Type'] = 'application/json';
        $requestOptions = [
            'body' => $bodyBytes,
            'headers' => $headers,
            'timeout' => max(1, min($timeoutMs / 1000, 120)),
            'connect_timeout' => min(10, max(1, $timeoutMs / 1000)),
            'allow_redirects' => false,
            'verify' => true,
            'proxy' => '',
            'http_errors' => false,
            'stream' => true,
        ];
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE') || $endpoint->curlResolveEntry === null
            || !($this->client instanceof Client) || !($this->client->getConfig('handler') instanceof CurlHandler)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider endpoint pinning is unavailable');
        }
        $requestOptions['curl'] = [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry]];
        $responseSink = null;
        if (!$streamSegments && !$agentStream) {
            // CurlHandler otherwise writes the entire response into its
            // default php://temp sink before readBody() can enforce a limit.
            $responseSink = new BoundedProviderResponseStream($responseMaxBytes);
            $requestOptions['sink'] = $responseSink;
            $requestOptions['decode_content'] = true;
        }

        $send = function (ProviderSendBoundary $boundary) use ($endpoint, $requestOptions, $responseMaxBytes, $responseSink, $streamSegments, $acceptSegment, $useNeuron, $agentToolRequest, $messages, $agentStream, $acceptAgentChunk, $payload, $apiKey, $remoteModel): array {
            try {
                if ($agentStream) {
                    return $this->sendOfficialAgentStream($endpoint->url,$endpoint->curlResolveEntry,
                        $requestOptions,$responseMaxBytes,$payload,$apiKey,$remoteModel,
                        $agentToolRequest,$messages,$acceptAgentChunk,$boundary);
                }
                if ($streamSegments) {
                    return $this->sendOfficialSegmentStream(
                        $endpoint->url,
                        $endpoint->curlResolveEntry,
                        $requestOptions,
                        $responseMaxBytes,
                        $payload,
                        $apiKey,
                        $remoteModel,
                        $acceptSegment,
                        $boundary,
                    );
                }
                $credentials = ProviderResponseIdentity::credentials($requestOptions['headers']);
                $requestOptions['on_headers'] = static function (\Psr\Http\Message\ResponseInterface $response) use ($boundary, $credentials): void {
                    foreach (['x-request-id','request-id'] as $header) {
                        foreach (array_slice($response->getHeader($header), 0, 2) as $value) {
                            $identity = ProviderResponseIdentity::received('header.' . $header, $value, $credentials);
                            if ($identity !== null) $boundary->observeResponseIdentity($identity);
                        }
                    }
                };
                try {
                    // This line is intentionally adjacent to Client::request(): all
                    // decrypt/normalize/DNS/body checks ran in prepare().
                    $boundary->markStarted();
                    $response = $this->client->request('POST', $endpoint->url, $requestOptions);
                } catch (GuzzleException $exception) {
                    if ($responseSink?->limitExceeded()) {
                        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider response exceeds the configured byte limit', true);
                    }
                    $context = ($exception instanceof RequestException || $exception instanceof ConnectException)
                        ? $exception->getHandlerContext() : [];
                    throw ChatProviderFailurePolicy::network(is_int($context['errno'] ?? null) ? $context['errno'] : null);
                }
                $status = $response->getStatusCode();
                // A definite HTTP rejection does not depend on the provider
                // returning JSON. Ignore its body, including HTML error pages.
                if ($status < 200 || $status >= 300) {
                    throw ChatProviderFailurePolicy::http($status);
                }
                $body = $this->readBody($response, $responseMaxBytes);
                try {
                    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned an invalid response', $status >= 500);
                }
                $identity = ProviderResponseIdentity::received('body.id', is_array($payload) ? ($payload['id'] ?? null) : null, $credentials);
                if ($identity !== null) $boundary->observeResponseIdentity($identity);
                $choice = is_array($payload['choices'][0] ?? null) ? $payload['choices'][0] : null;
                $message = is_array($choice['message'] ?? null) ? $choice['message'] : null;
                $content = $message['content'] ?? null;
                $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $inputTokens = self::tokenCount($usage['prompt_tokens'] ?? null);
                $outputTokens = self::tokenCount($usage['completion_tokens'] ?? null);
                $totalTokens = array_key_exists('total_tokens', $usage)
                    ? self::tokenCount($usage['total_tokens']) : null;
                if ($agentToolRequest !== null) {
                    try { $semantic = AgentToolChatRequest::normalizeMessage($message ?? []); $agentToolRequest->assertOutcome($semantic, $messages); }
                    catch (\Throwable) {
                        if ($inputTokens !== null && $outputTokens !== null && $inputTokens <= 2147483647 && $outputTokens <= 2147483647 - $inputTokens
                            && (!array_key_exists('total_tokens', $usage) || $totalTokens === $inputTokens + $outputTokens)) throw new MeasuredChatFailure($inputTokens, $outputTokens);
                        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned an invalid agent tool response', true);
                    }
                    $measured = $inputTokens !== null && $outputTokens !== null && $inputTokens <= 2147483647 && $outputTokens <= 2147483647 - $inputTokens
                        && (!array_key_exists('total_tokens', $usage) || $totalTokens === $inputTokens + $outputTokens);
                    return $semantic + ['input_tokens'=>$measured ? $inputTokens : 0, 'output_tokens'=>$measured ? $outputTokens : 0, 'usage_measured'=>$measured];
                }
                if (!is_string($content) || trim($content) === '') {
                    // A completed, length-limited response may spend tokens entirely
                    // on reasoning. Keep only measured counts; never retain that text.
                    if (($choice['finish_reason'] ?? null) === 'length'
                        && $inputTokens !== null && $outputTokens !== null
                        && $inputTokens <= 2147483647 && $outputTokens <= 2147483647 - $inputTokens
                        && (!array_key_exists('total_tokens', $usage) || $totalTokens === $inputTokens + $outputTokens)) {
                        throw new MeasuredChatFailure($inputTokens, $outputTokens);
                    }
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned no completion content', true);
                }
                $result = [
                    'content' => $content,
                    'input_tokens' => $inputTokens ?? 0,
                    'output_tokens' => $outputTokens ?? 0,
                    'usage_measured' => $inputTokens !== null && $outputTokens !== null,
                ];
                // Neuron receives the real bounded response after the existing
                // definite-failure and measured-empty-response protections.
                return $useNeuron ? ['payload' => $payload, 'status' => $status, 'result' => $result] : $result;
            } finally {
                $responseSink?->close();
            }
        };
        if (!$useNeuron) {
            return new PreparedChatProviderCall($send);
        }
        $httpClient = new NeuronControlledHttpClient($endpoint->url, $headers, hash('sha256', $bodyBytes), $send);
        $structured = $skillOutput !== null && \app\Application\Skill\SkillOutputStrategy::isStructured($skillOutput->strategy);
        if ($structured) {
            $parameters = $payload;
            unset($parameters['model'], $parameters['messages'], $parameters['response_format']);
            $neuron = NeuronSkillOutputAdapter::provider($skillOutput, $apiKey, $remoteModel, $parameters, $endpoint->url, $headers, $httpClient);
        } else {
            $neuron = new NeuronMaterialChatProvider($apiKey, $remoteModel, $payload, $endpoint->url, $headers, $httpClient);
        }
        $neuronMessages = NeuronSkillOutputAdapter::messages($messages);
        return new PreparedChatProviderCall(static function (ProviderSendBoundary $boundary) use ($httpClient, $neuron, $neuronMessages, $dispatchContext, $structured, $skillOutput): array {
            $httpClient->bindBoundary($boundary);
            try {
                $completion = $structured && $skillOutput !== null
                    ? NeuronSkillOutputAdapter::structured($neuron, $neuronMessages, $skillOutput)
                    : $neuron->chat(...$neuronMessages);
                $result = $httpClient->completion($completion->message());
                try {
                    \support\Log::info('SandAI Neuron material completion mapped', [
                        'framework_version' => '4.0.2',
                        'logical_execution_id' => $dispatchContext?->logicalExecutionId,
                    ]);
                } catch (\Throwable) {
                    // Diagnostic logging cannot change a measured completion.
                }
                return $result;
            } catch (MeasuredChatFailure $failure) {
                throw $failure;
            } catch (ApiProblem $problem) {
                throw $httpClient->measuredFailure() ?? $problem;
            } catch (\Throwable) {
                if (($failure = $httpClient->measuredFailure()) !== null) {
                    throw $failure;
                }
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Neuron could not process the provider completion', true);
            }
        });
    }

    /**
     * The dispatch ledger hashes these exact canonical request fields.  Keep it
     * public for the trusted gateway preflight; callers never supply it.
     *
     * @param array<string,mixed> $config
     * @param list<array{role:string,content:string}> $messages
     * @return array<string,mixed>
     */
    public function requestBody(array $config, string $remoteModel, array $messages, ?ChatCompletionOptions $options = null, bool $streamSegments = false, ?AgentToolChatRequest $agentToolRequest = null): array
    {
        $normalized = $this->configPolicy->normalize($config);
        return ProviderRequestBodyBuilder::chat($remoteModel, $messages, $options, $normalized['output_token_parameter'], $streamSegments, $agentToolRequest);
    }

    private static function tokenCount(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $digits = ltrim($value, '0');
            $digits = $digits === '' ? '0' : $digits;
            $limit = (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($limit)
                || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
                return null;
            }
            return (int) $value;
        }

        return null;
    }

    /** Official SDK performs stream parsing/tool assembly; SandAI owns bounded transport, usage validation and receipts. */
    private function sendOfficialAgentStream(string $url,string $resolveEntry,array $options,int $maximumBytes,array $payload,string $key,string $model,AgentToolChatRequest $request,array $messages,callable $acceptChunk,ProviderSendBoundary $boundary):array
    {
        $client=new NeuronAgentStreamHttpClient($url,$resolveEntry,$options,$maximumBytes,ProviderRequestBodyBuilder::digest($payload),$boundary,toolRequest:$request);
        try {
            $provider=new NeuronAgentStreamProvider($key,$model,$payload,$url,$options['headers'],$client);
            $provider->setTools($request->sdkTools);
            $generator=$provider->stream();
            foreach ($generator as $chunk) $acceptChunk($chunk);
            $response=$generator->getReturn();
            $message=$response->message();
            $usage=$client->completedUsage();
            if ($message->getUsage()?->inputTokens!==$usage['input_tokens']
                || $message->getUsage()?->outputTokens!==$usage['output_tokens']) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE','Official stream usage differs from the measured transport',true);
            }
            $raw=['content'=>$message->getContent(),'tool_calls'=>[]];
            if ($message instanceof \NeuronAI\Chat\Messages\ToolCallMessage) {
                foreach ($message->getToolCalls() as $call) $raw['tool_calls'][]=['id'=>$call->getCallId(),'type'=>'function',
                    'function'=>['name'=>$call->getName(),'arguments'=>json_encode($call->getInputs(),JSON_THROW_ON_ERROR)]];
            }
            $semantic=AgentToolChatRequest::normalizeMessage($raw);
            $request->assertOutcome($semantic,$messages);
            return $semantic+$usage+['usage_measured'=>true];
        } catch (\Throwable $failure) {
            $problem=$failure instanceof ApiProblem?$failure:new ApiProblem('SAND_AI_PROVIDER_FAILURE','Official agent stream failed',true);
            $measured=$client->measuredFailure($problem,$failure);
            if ($measured!==null) throw $measured;
            if ($failure instanceof ApiProblem) throw $failure;
            throw $problem;
        } finally {
            $client->close();
        }
    }

    /**
     * Neuron parses the actual stream and assembles its text. SandAI accepts
     * only source-bound business paragraphs and retains measured failure costs.
     *
     * @param callable(array<string,mixed>):array{text:string,source_block_ids:list<int>} $acceptSegment
     * @return array{content:string,input_tokens:int,output_tokens:int,usage_measured:bool}
     */
    private function sendOfficialSegmentStream(
        string $url,
        string $resolveEntry,
        array $requestOptions,
        int $maximumBytes,
        array $payload,
        string $key,
        string $model,
        callable $acceptSegment,
        ProviderSendBoundary $boundary,
    ): array {
        $client = new NeuronAgentStreamHttpClient(
            $url, $resolveEntry, $requestOptions, $maximumBytes,
            ProviderRequestBodyBuilder::digest($payload), $boundary, allowToolCalls: false,
        );
        $segments = new SourceBoundAnswerSegments(\Closure::fromCallable($acceptSegment));
        try {
            $provider = new NeuronAgentStreamProvider($key, $model, $payload, $url, $requestOptions['headers'], $client);
            $generator = $provider->stream();
            foreach ($generator as $chunk) {
                if (!$chunk instanceof \NeuronAI\Chat\Messages\Stream\Chunks\TextChunk) {
                    throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official paragraph stream returned an unsupported chunk', true);
                }
                $segments->append($chunk->content);
            }
            $response = $generator->getReturn();
            $message = $response->message();
            $usage = $client->completedUsage();
            if ($message instanceof \NeuronAI\Chat\Messages\ToolCallMessage
                || !is_string($message->getContent())
                || $message->getUsage()?->inputTokens !== $usage['input_tokens']
                || $message->getUsage()?->outputTokens !== $usage['output_tokens']) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official paragraph stream differs from its measured transport', true);
            }

            return $segments->finish($message->getContent()) + $usage + ['usage_measured' => true];
        } catch (\Throwable $failure) {
            $problem = $failure instanceof ApiProblem ? $failure
                : new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Official paragraph stream failed', true);
            $measured = $client->measuredFailure($problem, $failure);
            if ($measured !== null) {
                throw $measured;
            }
            throw $problem;
        } finally {
            $client->close();
        }
    }

    private function readBody(object $response, int $maximum): string
    {
        $contentLength = $response->getHeaderLine('Content-Length');
        if (ctype_digit($contentLength) && $this->exceedsByteLimit($contentLength, $maximum)) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider response exceeds the configured byte limit', true);
        }
        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(8192, $maximum - strlen($body) + 1));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $maximum) {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider response exceeds the configured byte limit', true);
            }
        }

        return $body;
    }

    private function exceedsByteLimit(string $value, int $maximum): bool
    {
        $value = ltrim($value, '0');
        $value = $value === '' ? '0' : $value;
        $limit = (string) $maximum;

        return strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0);
    }

}
