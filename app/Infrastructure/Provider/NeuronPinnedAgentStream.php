<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\ProviderResponseIdentity;
use app\Infrastructure\Support\PooledFiberContext;
use NeuronAI\HttpClient\StreamInterface;
use Throwable;

/** Bounded pinned network stream. SDK parses/assembles the actual messages and tools. */
final class NeuronPinnedAgentStream implements StreamInterface
{
    /** Bounds for provider-initiated parallel tool calls SandAI never authorizes. */
    private const PARALLEL_FRAME_CALL_LIMIT = 8;
    private const PARALLEL_CALL_INDEX_LIMIT = 15;
    private const PARALLEL_ARGUMENT_BYTES = 32768;
    /** @var list<string> */
    private array $credentials;
    private \CurlHandle $handle;
    private \Fiber $network;
    private array $lines = [];
    private string $buffer = '';
    private ?string $terminal = null;
    private ?array $usage = null;
    private int $bytes = 0;
    private int $headerBytes = 0;
    private int $status = 0;
    private int $textBytes = 0;
    private int $argumentBytes = 0;
    private ?string $toolCallId = null;
    private ?string $toolName = null;
    private ?int $toolCallIndex = null;
    private int $toolArgumentLimit = 0;
    private int $parallelCalls = 0;
    private int $parallelArgumentBytes = 0;
    private string $contentType = '';
    private string $errorBody = '';
    private bool $done = false;
    private bool $closed = false;
    private bool $releasedTerminal = false;

    public function __construct(string $url, string $resolveEntry, array $options, private readonly int $maximumBytes, private readonly ProviderSendBoundary $boundary, private readonly bool $allowToolCalls = true, private readonly ?AgentToolChatRequest $toolRequest = null)
    {
        if (($allowToolCalls && ($toolRequest === null || !$toolRequest->stream || $toolRequest->tools === []))
            || (!$allowToolCalls && $toolRequest !== null)) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Agent stream requires its frozen tool declarations');
        }
        $handle = curl_init($url);
        if ($handle === false) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Agent cURL stream is unavailable');
        $this->handle = $handle;
        $this->credentials = ProviderResponseIdentity::credentials($options['headers']);
        $headers = [];
        foreach ($options['headers'] as $name => $value) $headers[] = $name . ': ' . $value;
        $configured = curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $options['body'], CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($options['timeout'] * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($options['connect_timeout'] * 1000),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$resolveEntry], CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
                $this->headerBytes += strlen($line);
                if ($this->headerBytes > 65536) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream headers exceed their limit', true);
                if (preg_match('~^HTTP/[0-9.]+ ([0-9]{3})(?:\s|$)~', $line, $match) === 1) {
                    $this->status = (int) $match[1]; $this->contentType = ''; $this->errorBody = '';
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $this->contentType = trim(substr($line, strlen('Content-Type:')));
                }
                if ($this->status >= 200 && preg_match('/\A(x-request-id|request-id):[ \t]*(.*?)\r?\n\z/iD', $line, $header) === 1) {
                    $identity = ProviderResponseIdentity::received('header.' . strtolower($header[1]), $header[2], $this->credentials);
                    if ($identity !== null) $this->boundary->observeResponseIdentity($identity);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk): int {
                if ($this->status >= 200 && $this->status < 300) {
                    if (preg_match('~^text/event-stream(?:\s*;|$)~i', $this->contentType) !== 1) {
                        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream content type is invalid', true);
                    }
                    \Fiber::suspend($chunk);
                } else {
                    if (strlen($this->errorBody) + strlen($chunk) > $this->maximumBytes) {
                        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream error response exceeds its limit', true);
                    }
                    $this->errorBody .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        if (!$configured) {
            curl_close($handle);
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Agent stream transport could not be configured');
        }
        // The send boundary runs its IAM gate inside this fiber. Without the
        // caller's context that query would take a second pooled connection and
        // hold it until this object's reference cycle is cycle-collected.
        $callerContext = PooledFiberContext::capture();
        $this->network = new \Fiber(function () use ($boundary, $callerContext): void {
            PooledFiberContext::adopt($callerContext);
            try {
                $boundary->markStarted();
                if (curl_exec($this->handle) === false) throw ChatProviderFailurePolicy::network(curl_errno($this->handle));
                if ($this->status < 200 || $this->status >= 300) throw ChatProviderFailurePolicy::http($this->status);
            } finally {
                curl_close($this->handle);
            }
        });
    }

    public function eof(): bool { $this->pump(); return $this->lines === [] && $this->network->isTerminated(); }
    public function readLine(): string { $this->pump(); return array_shift($this->lines) ?? ''; }
    public function read(int $length): string
    {
        if ($length < 1) return '';
        $this->pump();
        $line = array_shift($this->lines) ?? '';
        $part = substr($line, 0, $length);
        if (strlen($line) > $length) array_unshift($this->lines, substr($line, $length));
        return $part;
    }

    private function pump(): void
    {
        if ($this->closed) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream is closed', true);
        while ($this->lines === [] && !$this->network->isTerminated()) {
            $chunk = !$this->network->isStarted() ? $this->network->start() : $this->network->resume();
            if (is_string($chunk)) $this->feed($chunk);
        }
        if ($this->network->isTerminated() && !$this->releasedTerminal) {
            if (!$this->done || $this->terminal === null || $this->usage === null || trim($this->buffer) !== '') {
                throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream ended without its measured completion', true);
            }
            // SDK returns a tool response immediately on its finish frame. Give it measured usage first.
            $this->lines[] = $this->terminal;
            $this->lines[] = "data: [DONE]\n";
            $this->releasedTerminal = true;
        }
    }

    private function feed(string $chunk): void
    {
        $this->bytes += strlen($chunk);
        if ($this->bytes > $this->maximumBytes) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream exceeds its byte limit', true);
        $this->buffer = str_replace("\r\n", "\n", $this->buffer . $chunk);
        while (($boundary = strpos($this->buffer, "\n\n")) !== false) {
            if ($boundary > 65536) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream frame is too large', true);
            $frame = substr($this->buffer, 0, $boundary);
            $this->buffer = substr($this->buffer, $boundary + 2);
            $this->frame($frame);
        }
        if (strlen($this->buffer) > 65536) throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream frame is too large', true);
    }

    private function frame(string $frame): void
    {
        $data = null;
        foreach (explode("\n", $frame) as $line) {
            if ($line === '' || str_starts_with($line, ':')) continue;
            if (!str_starts_with($line, 'data:') || $data !== null) self::invalid('sse_data_line');
            $data = ltrim(substr($line, 5), ' ');
        }
        if ($data === null) return;
        if ($this->done) self::invalid('frame_after_done');
        if ($data === '[DONE]') { $this->done = true; return; }
        try { $event = json_decode($data, true, 16, JSON_THROW_ON_ERROR); } catch (Throwable) { self::invalid('frame_json'); }
        if (!is_array($event)) self::invalid('event_shape_or_upstream_error');
        $identity = ProviderResponseIdentity::received('stream.id', $event['id'] ?? null, $this->credentials);
        if ($identity !== null) $this->boundary->observeResponseIdentity($identity);
        if (isset($event['error'])) self::invalid('event_shape_or_upstream_error');
        if (isset($event['usage'])) {
            $usage = $event['usage'];
            if ($this->usage !== null || !is_array($usage)
                || !is_int($usage['prompt_tokens'] ?? null) || $usage['prompt_tokens'] < 0
                || !is_int($usage['completion_tokens'] ?? null) || $usage['completion_tokens'] < 0
                || $usage['prompt_tokens'] > 2147483647 - $usage['completion_tokens']
                || (isset($usage['total_tokens']) && $usage['total_tokens'] !== $usage['prompt_tokens'] + $usage['completion_tokens'])) self::invalid('usage_measurement');
            $this->usage = ['input_tokens' => $usage['prompt_tokens'], 'output_tokens' => $usage['completion_tokens']];
            // Only actual required measurement is forwarded; unknown optional counters cannot become false SDK facts.
            $event['usage'] = ['prompt_tokens' => $usage['prompt_tokens'], 'completion_tokens' => $usage['completion_tokens']];
        }
        $choices = $event['choices'] ?? null;
        if (!is_array($choices) || !array_is_list($choices) || count($choices) > 1) self::invalid('choices_shape');
        if ($choices !== []) {
            $choice = $choices[0];
            if ($this->terminal !== null || !is_array($choice) || ($choice['index'] ?? null) !== 0
                || !is_array($choice['delta'] ?? null)) self::invalid('choice_delta_or_order');
            $delta = $choice['delta'];
            if (isset($delta['content'])) {
                if (!is_string($delta['content']) || !mb_check_encoding($delta['content'], 'UTF-8')) self::invalid('content_encoding');
                $this->textBytes += strlen($delta['content']);
                if ($this->textBytes > ($this->allowToolCalls ? 32768 : $this->maximumBytes)) self::invalid('content_bytes');
            }
            $calls=$delta['tool_calls']??[];
            if (!is_array($calls) || !array_is_list($calls) || count($calls)>self::PARALLEL_FRAME_CALL_LIMIT) self::invalid('tool_calls_shape');
            if (!$this->allowToolCalls && $calls !== []) self::invalid('unrequested_tool_calls');
            $kept=[];
            foreach ($calls as $call) {
                // An OpenAI-compatible provider may stream several indexed calls even though
                // SandAI declares a single call per round (AgentReadonlyToolProtocol::LIMITS
                // parallel_tool_calls=false, SDK Agent::parallelToolCalls(false)). glm-5.3-flash
                // actually does, and the wire parameter does not suppress it. The first observed
                // index stays this round's only authorized call; any other index is shape-checked,
                // byte-bounded and dropped before SDK assembly, so the round still completes with
                // its measured usage. Dropped intents may be requested again in a later round
                // inside the same tool run limits; nothing is executed or cited from them.
                if (!is_array($call) || !is_int($call['index'] ?? null) || $call['index']<0
                    || $call['index']>self::PARALLEL_CALL_INDEX_LIMIT || !is_array($call['function'] ?? null)
                    || (isset($call['function']['arguments']) && !is_string($call['function']['arguments']))) self::invalid('tool_delta_shape');
                if ($this->toolCallIndex!==null && $call['index']!==$this->toolCallIndex) {
                    if ((isset($call['id']) && (!is_string($call['id']) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$call['id'])!==1))
                        || (isset($call['type']) && $call['type']!=='function')
                        || (isset($call['function']['name']) && !is_string($call['function']['name']))) self::invalid('parallel_tool_identity');
                    $this->parallelArgumentBytes += strlen($call['function']['arguments'] ?? '');
                    if ($this->parallelArgumentBytes > self::PARALLEL_ARGUMENT_BYTES) self::invalid('parallel_tool_arguments_bytes');
                    $this->parallelCalls++;
                    // Counters only: a dropped intent never reaches a log, a receipt or an answer.
                    try { \support\Log::info('SandAI agent stream dropped an unauthorized parallel tool call',
                        ['dropped_call_deltas'=>$this->parallelCalls,'dropped_argument_bytes'=>$this->parallelArgumentBytes]); } catch (Throwable) {}
                    continue;
                }
                if ($this->toolCallId===null) {
                    if (!is_string($call['id']??null) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',$call['id'])!==1
                        || ($call['type']??null)!=='function' || !is_string($call['function']['name']??null)) self::invalid('tool_identity_initial');
                    // Exact request membership, never the managed-name pattern alone. The same
                    // immutable mapped schema is validated by assertOutcome after SDK assembly.
                    $name=$call['function']['name'];
                    $this->toolArgumentLimit=$this->toolRequest->streamArgumentLimit($name);
                    $this->toolCallId=$call['id'];
                    $this->toolName=$name;
                    $this->toolCallIndex=$call['index'];
                } elseif ((isset($call['id']) && $call['id']!==$this->toolCallId)
                    || (isset($call['type']) && $call['type']!=='function')
                    || (isset($call['function']['name']) && $call['function']['name']!==$this->toolName)) self::invalid('tool_identity_continuation');
                $this->argumentBytes += strlen($call['function']['arguments'] ?? '');
                if ($this->argumentBytes > $this->toolArgumentLimit) self::invalid('tool_arguments_bytes');
                // The single authorized call is always assembled at index 0.
                $call['index']=0;
                $kept[]=$call;
            }
            if ($calls!==[]) {
                if ($kept===[]) unset($delta['tool_calls']); else $delta['tool_calls']=$kept;
                $event['choices'][0]['delta']=$delta;
            }
            $finish = $choice['finish_reason'] ?? null;
            if ($finish !== null) {
                if (!in_array($finish, $this->allowToolCalls ? ['stop','tool_calls'] : ['stop'], true)) self::invalid('finish_reason');
                if (!$this->allowToolCalls && ($delta['content'] ?? '') !== '') self::invalid('content_after_finish');
                $this->terminal = 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                return;
            }
        }
        $this->lines[] = 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function measuredUsage(): ?array { return $this->usage; }
    public function assertComplete(): void
    {
        if (!$this->network->isTerminated() || !$this->releasedTerminal || $this->usage === null || !$this->done) self::invalid('completion_boundary');
    }
    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        if (!$this->network->isStarted()) { curl_close($this->handle); return; }
        if ($this->network->isSuspended()) {
            try { $this->network->throw(new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream was interrupted', true)); } catch (Throwable) {}
        }
    }
    /** Literal local guard identifiers only; never append upstream data or messages. */
    private static function invalid(string $guard): never
    {
        throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Agent stream protocol is invalid [' . $guard . ']', true);
    }
}
