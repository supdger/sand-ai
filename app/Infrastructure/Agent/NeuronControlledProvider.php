<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Provider\AgentToolChatRequest;
use app\Infrastructure\Support\PooledFiberContext;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Providers\HandleWithTools;

/** SDK provider port delegates each inference to the existing SandAI dispatch/receipt/accounting gateway. */
final class NeuronControlledProvider implements AIProviderInterface
{
    use HandleWithTools { setTools as private registerSdkTools; }
    private ?string $instructions=null;
    private ?AgentToolChatRequest $request=null;
    private array $rounds=[];

    public function __construct(private readonly string $model,private readonly \Closure $dispatchRound,private readonly bool $stream=false,private readonly ?NeuronAgentExecutionJournal $journal=null) {}
    public function getModel(): string { return $this->model; }
    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        $this->instructions=$prompt instanceof SystemMessage ? $prompt->getContent() : $prompt;
        return $this;
    }
    public function setTools(array $tools): AIProviderInterface
    {
        $this->request=new AgentToolChatRequest(array_values($tools),stream:$this->stream);
        $this->registerSdkTools(array_values($tools));
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->stream) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Frozen stream intent requires the official stream entrypoint');
        $wire=$this->wireMessages($messages);
        return $this->providerResponse($this->accountedRound($wire, fn():mixed=>($this->dispatchRound)(count($this->rounds)+1,$wire,$this->request)));
    }

    private function wireMessages(array $messages): array
    {
        if ($this->request===null || count($this->rounds)>=(count($this->request->sdkTools)>1?4:3)) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Agent model round limit exceeded');
        $wire=NeuronTextWireMapper::map($messages,$this->instructions);
        $this->request->assertMessages($wire);
        return $wire;
    }

    private function providerResponse(mixed $result): ProviderResponse
    {
        if (!is_array($result) || !is_array($result['usage']??null)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent round did not return an accounted outcome');
        $semantic=['content'=>$result['content']??null,'response_kind'=>$result['response_kind']??null,'tool_calls'=>$result['tool_calls']??null];
        if ($semantic['response_kind']==='tool_calls' && is_array($semantic['tool_calls'])) {
            $raw=['content'=>$semantic['content'],'tool_calls'=>array_map(static fn(array $c):array=>['id'=>$c['id'],'type'=>'function','function'=>['name'=>$c['name'],'arguments'=>json_encode($c['arguments'],JSON_THROW_ON_ERROR)]],$semantic['tool_calls'])];
            if (AgentToolChatRequest::normalizeMessage($raw)!==$semantic) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent tool outcome is invalid');
            $calls=array_map(fn(array $c)=>$this->newToolCall($c['name'],$c['id'],$c['arguments']),$semantic['tool_calls']);
            $toolCallResponse=new ToolCallMessage($semantic['content'],$calls);
            $response=$toolCallResponse;
        } elseif ($semantic['response_kind']==='text' && $semantic['tool_calls']===[] && is_string($semantic['content']) && trim($semantic['content'])!=='') {
            $assistantResponse=new AssistantMessage($semantic['content']);
            $response=$assistantResponse;
        } else { throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent final text outcome is invalid'); }
        $usage=$result['usage'];
        if ($this->stream && (($usage['usage_state']??null)!=='measured'
            || !is_int($usage['input_tokens']??null) || !is_int($usage['output_tokens']??null))) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Agent stream requires actual measured usage');
        }
        if (($usage['usage_state']??null)==='measured' && is_int($usage['input_tokens']??null) && is_int($usage['output_tokens']??null)) {
            $response->setUsage(new Usage($usage['input_tokens'],$usage['output_tokens']));
        }
        $this->rounds[]=$result;
        return new ProviderResponse($response,metadata:['sandai_invocation_id'=>$result['invocation_id']??null,'sandai_round_number'=>count($this->rounds),'sandai_accounted_outcome'=>$result]);
    }
    public function rounds(): array { return $this->rounds; }
    public function restoreCompletedRounds(array $rounds): void
    {
        if (!array_is_list($rounds) || count($rounds)>4) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Recovered agent round cursor is invalid');
        foreach ($rounds as $round) if (!is_array($round) || !is_array($round['usage']??null)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Recovered accounted round is invalid');
        $this->rounds=$rounds;
    }
    private function accountedRound(array $wire,\Closure $dispatch): mixed
    {
        if ($this->journal===null) return $dispatch();
        return $this->journal->round(count($this->rounds)+1,hash('sha256',json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),$dispatch);
    }
    public function stream(Message ...$messages): \Generator
    {
        // TypePHP does not support generators with variadic parameters; the
        // public SDK signature stays variadic and the generator body moves to
        // an array-typed private method.
        return $this->streamFrozen($messages);
    }
    private function streamFrozen(array $messages): \Generator
    {
        if (!$this->stream) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Agent stream intent was not frozen');
        $wire=$this->wireMessages($messages);
        // The accounted round journals and dispatches from inside this fiber.
        // Without the caller's context those writes take a second pooled
        // connection that is only released when the fiber itself is freed.
        $callerContext=PooledFiberContext::capture();
        $fiber=new \Fiber(function() use ($wire,$callerContext):array {
            PooledFiberContext::adopt($callerContext);
            return $this->accountedRound($wire,fn():mixed=>($this->dispatchRound)(count($this->rounds)+1,$wire,$this->request,
                static function(StreamChunk $chunk):void { \Fiber::suspend($chunk); }));
        });
        try {
            $chunk=$fiber->start();
            while (!$fiber->isTerminated()) {
                if (!$chunk instanceof StreamChunk) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Agent stream yielded an invalid SDK chunk');
                yield $chunk;
                $chunk=$fiber->resume();
            }
            return $this->providerResponse($fiber->getReturn());
        } finally {
            if ($fiber->isSuspended()) {
                try { $fiber->throw(new ApiProblem('SAND_AI_PROVIDER_FAILURE','Agent stream receiver stopped',true)); } catch (\Throwable) {}
            }
        }
    }
    public function structured(array|Message $messages,string $class,array $response_schema): ProviderResponse { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED','Agent implicit structured retries are not enabled'); }
    public function setHttpClient(HttpClientInterface $client): AIProviderInterface { throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED','Agent transport is controlled by SandAI dispatch'); }
}
