<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchExecutionScope;
use app\Infrastructure\Persistence\Model\TaskRun;
use app\Infrastructure\Persistence\Model\TaskStep;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Providers\OpenAI\ToolMapper;

/** Server-only protocol for the actual authorized SDK tool instances. */
final readonly class AgentToolChatRequest
{
    public const VERSION = 1;
    private const RETRIEVAL_ARGUMENT_BYTES = 2048;
    private const READONLY_ARGUMENT_BYTES = 8192;
    public array $tools;
    public ?array $summaryCoverage;
    /** Actual execution-scoped SDK tool definitions; never serialized to a public body or receipt. */
    public array $sdkTools;

    /** @param list<ToolInterface> $tools */
    public function __construct(array $tools, ?array $summaryCoverage=null, public bool $stream=false)
    {
        $this->summaryCoverage=$summaryCoverage;
        $this->sdkTools=array_values($tools);
        if ($summaryCoverage!==null) {
            if ($tools!==[] || $stream || ($summaryCoverage['version']??null)!==1
                || !is_string($summaryCoverage['coverage_sha256']??null)
                || preg_match('/\A[a-f0-9]{64}\z/D',$summaryCoverage['coverage_sha256'])!==1) self::invalid();
            $unsigned=array_diff_key($summaryCoverage,['coverage_sha256'=>true]);
            if (!hash_equals($summaryCoverage['coverage_sha256'],hash('sha256',json_encode($unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)))) self::invalid();
            $this->tools=[];
            return;
        }
        if ($tools === [] || count($tools) > 3 || !$tools[0] instanceof \app\Infrastructure\Agent\NeuronAuthorizedRetrievalTool) self::invalid();
        $names = [];
        foreach ($tools as $index => $candidate) {
            if ($index > 0 && !$candidate instanceof \app\Infrastructure\Agent\NeuronAuthorizedReadonlyTool) self::invalid();
            if (isset($names[$candidate->getName()])) self::invalid();
            $names[$candidate->getName()] = true;
        }
        $tool = $tools[0];
        if ($tool->getName() !== 'retrieval_search') self::invalid();
        // Official SDK maps function names, descriptions, parameters and tool extensions.
        $mapped = (new ToolMapper())->map($tools);
        $schema = $mapped[0]['function']['parameters'];
        // The SDK's ToolProperty exposes type/required; these domain bounds are SandAI's contract.
        $schema['additionalProperties'] = false;
        $schema['properties']['query']['minLength'] = 1;
        $schema['properties']['query']['maxLength'] = 500;
        $mapped[0]['function']['parameters'] = $schema;
        $this->tools = $mapped;
    }

    /** Frozen SDK-mapped names and schemas are shared by transport and final outcome validation. */
    public function streamArgumentLimit(string $name): int
    {
        if (!$this->stream || $this->summaryCoverage !== null) self::invalid();
        foreach ($this->tools as $tool) {
            if (($tool['function']['name'] ?? null) === $name) {
                return $name === 'retrieval_search' ? self::RETRIEVAL_ARGUMENT_BYTES : self::READONLY_ARGUMENT_BYTES;
            }
        }
        self::invalid();
    }

    public function fingerprint(): array
    {
        return $this->summaryCoverage===null
            ? ['version'=>count($this->sdkTools)>1?2:self::VERSION,'tools'=>$this->tools,'tool_choice'=>'auto'] + (count($this->sdkTools)>1?['execution_limits'=>\app\Application\Agent\AgentReadonlyToolProtocol::LIMITS]:[]) + ($this->stream?['stream_version'=>1]:[])
            : ['version'=>self::VERSION,'purpose'=>'history_summary','tools'=>[],'coverage'=>$this->summaryCoverage];
    }

    public function assertScope(DispatchExecutionScope $scope, int $environmentId, string $requestId): void
    {
        if ($scope->scope !== 'agent' || $scope->taskRunId === null || $scope->taskStepId === null) self::invalid();
        if ($this->summaryCoverage===null) {
            if (preg_match('/\Aagent_round_[1-4]\z/D',$scope->logicalNode??'')!==1) self::invalid();
        } elseif ($scope->logicalNode!=='agent_summary_1') self::invalid();
        $task = TaskRun::where('id', $scope->taskRunId)->where('environment_id', $environmentId)->find();
        $step = TaskStep::where('id', $scope->taskStepId)->where('task_id', $scope->taskRunId)->find();
        if ($task === null || $step === null || (string)$task->task_type !== 'agent_run'
            || !is_array($task->input) || ($task->input['_agent_neuron'] ?? false) !== true
            || (string)$step->state !== 'running' || (int)$step->attempt_count !== (int)$task->attempt_count
            || $task->content_purged_time !== null || (string)$task->state !== 'running'
            || (string)$step->step_code !== $scope->logicalNode
            || (string)$task->lease_owner !== $scope->leaseOwner || (string)$task->lease_token !== $scope->leaseToken
            || (int)$task->lease_fence !== $scope->leaseFence) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Agent round requires its current task lease');
        }
        if ($this->summaryCoverage!==null) {
            $context=is_object($step->context)?(array)$step->context:$step->context;
            if (($this->summaryCoverage['context_turn_limit']??null)!==($task->input['context_turn_limit']??null)
                || !is_array($context) || !is_array($context['coverage']??null)
                || !\app\Application\Gateway\RequestPayloadFingerprint::same($this->summaryCoverage,$context['coverage'],[])) self::invalid();
            foreach (($this->summaryCoverage['history_task_ids']??[]) as $historyTaskId) {
                if (!is_int($historyTaskId) || $historyTaskId<1 || $historyTaskId>=$scope->taskRunId) self::invalid();
            }
        } elseif ($this->stream!==($task->input['_agent_stream']??false)) {
            throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','Agent stream intent differs from its frozen task');
        }
        $identity = \app\Application\Agent\AgentGateway::modelRequestIdentity($task, $scope->logicalNode,
            $this->summaryCoverage['coverage_sha256'] ?? null);
        if ($requestId !== $identity['request_id'] || $scope->attemptNo !== $identity['attempt_no']) self::invalid();
        \app\Application\Agent\AgentGateway::assertModelDispatchIdentity($task, [
            'parent_task_run_id' => $scope->taskRunId, 'parent_task_step_id' => $scope->taskStepId,
            'workflow_node_code' => $scope->logicalNode, 'attempt_no' => $scope->attemptNo,
            'request_id' => $requestId, 'logical_request_id' => hash('sha256', $requestId),
            'logical_execution_id' => $scope->logicalExecutionId, 'execution_snapshot_sha256' => $scope->executionSnapshotSha256,
        ]);
        if ($this->summaryCoverage === null) {
            $bindings = $task->input['_agent_readonly_tools'] ?? [];
            if (($bindings === [] && $scope->logicalNode === 'agent_round_4')
                || ($bindings !== [] && !\app\Application\Agent\AgentReadonlyToolProtocol::valid($task->input['_agent_readonly_protocol'] ?? null))) self::invalid();
            if (!is_array($bindings) || !array_is_list($bindings) || count($bindings) > 2
                || count($this->tools) !== count($bindings) + 1) self::invalid();
            foreach ($bindings as $index => $binding) {
                if (!is_array($binding) || !is_array($binding['input_schema'] ?? null)
                    || ($this->tools[$index + 1]['function']['name'] ?? null)
                        !== \app\Infrastructure\Agent\NeuronAuthorizedReadonlyTool::wireName($binding)
                    || !\app\Application\Gateway\RequestPayloadFingerprint::same(
                        $this->tools[$index + 1]['function']['parameters'] ?? [], $binding['input_schema'], [],
                    )) self::invalid();
            }
        }
    }

    /** Existing hard reservations use a verified deployment ceiling; this is solely the byte advisory projection. */
    public function budgetAdvisoryMessages(array $messages): array
    {
        $this->assertMessages($messages);
        $json=json_encode(['messages'=>$messages,'agent_tool'=>$this->fingerprint()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return array_map(static fn(string $chunk):array=>['role'=>'user','content'=>$chunk],mb_str_split($json,16000,'UTF-8'));
    }

    /** @param list<array<string,mixed>> $messages */
    public function assertMessages(array $messages): void
    {
        if (!array_is_list($messages) || $messages === [] || count($messages) > 64) self::invalid();
        if ($this->summaryCoverage!==null && (count($messages)!==2 || ($messages[0]['role']??null)!=='system'
            || ($messages[1]['role']??null)!=='user')) self::invalid();
        $pending = [];
        $seenCalls = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !is_string($message['role'] ?? null)) self::invalid();
            $role = $message['role'];
            if ($this->summaryCoverage!==null && (isset($message['tool_calls']) || $role==='tool')) self::invalid();
            if ($role === 'tool') {
                if (array_diff(array_keys($message), ['role','content','tool_call_id']) !== []
                    || !is_string($message['content'] ?? null) || strlen($message['content']) > 32768
                    || !is_string($message['tool_call_id'] ?? null) || !isset($pending[$message['tool_call_id']])) self::invalid();
                unset($pending[$message['tool_call_id']]);
            } elseif ($role === 'assistant' && isset($message['tool_calls'])) {
                if ($pending !== [] || array_diff(array_keys($message), ['role','content','tool_calls']) !== []) self::invalid();
                $normalized = self::normalizeMessage($message);
                $this->assertAllowedCalls($normalized);
                if ($normalized['response_kind'] !== 'tool_calls') self::invalid();
                foreach ($normalized['tool_calls'] as $call) {
                    if (isset($seenCalls[$call['id']])) self::invalid();
                    $pending[$call['id']] = true; $seenCalls[$call['id']] = true;
                }
            } else {
                if ($pending !== [] || !in_array($role, ['system','user','assistant'], true)
                    || array_diff(array_keys($message), ['role','content']) !== []
                    || !is_string($message['content'] ?? null) || $message['content'] === '') self::invalid();
            }
        }
        if ($pending !== [] || strlen(json_encode($messages, JSON_THROW_ON_ERROR)) > 262144) self::invalid();
    }

    /** Validate both real responses and authenticated receipt replay against this request's actual allowed tools. */
    public function assertOutcome(array $semantic, array $messages): void
    {
        self::assertFreshCalls($semantic,$messages);
        $this->assertAllowedCalls($semantic);
        if ($this->summaryCoverage!==null && (($semantic['response_kind']??null)!=='text'
            || ($semantic['tool_calls']??null)!==[] || !is_string($semantic['content']??null)
            || trim($semantic['content'])==='' || strlen($semantic['content'])>32768)) self::invalid();
    }

    /** Validate returned call identities before the SDK may execute a ToolNode. */
    public static function assertFreshCalls(array $semantic, array $messages): void
    {
        $seen=[];
        foreach ($messages as $message) foreach (($message['tool_calls']??[]) as $call) $seen[$call['id']]=true;
        foreach ($semantic['tool_calls'] as $call) if (isset($seen[$call['id']])) self::invalid();
    }

    /** @return array{content:?string,response_kind:string,tool_calls:array} */
    public static function normalizeMessage(array $message): array
    {
        $content = $message['content'] ?? null;
        $calls = $message['tool_calls'] ?? [];
        if ($calls === []) {
            if (!is_string($content) || trim($content) === '') self::invalid();
            return ['content'=>$content, 'response_kind'=>'text', 'tool_calls'=>[]];
        }
        if (!is_array($calls) || !array_is_list($calls) || count($calls) !== 1 || ($content !== null && !is_string($content))) self::invalid();
        $normalized = [];
        foreach ($calls as $call) {
            $fn = $call['function'] ?? null;
            if (!is_array($call) || ($call['type'] ?? null) !== 'function'
                || !is_string($call['id'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $call['id']) !== 1
                || !is_array($fn) || !is_string($fn['name'] ?? null)
                || ($fn['name'] !== 'retrieval_search' && preg_match('/\Amanaged_[a-f0-9]{32}\z/D', $fn['name']) !== 1)
                || !is_string($fn['arguments'] ?? null) || strlen($fn['arguments']) > ($fn['name'] === 'retrieval_search' ? self::RETRIEVAL_ARGUMENT_BYTES : self::READONLY_ARGUMENT_BYTES)) self::invalid();
            try { $args=\app\Application\Tool\StrictJsonObjectDecoder::decodeObjectValue($fn['arguments']); }
            catch (\Throwable) { self::invalid(); }
            if ($fn['name'] === 'retrieval_search' && (!is_array($args) || array_keys($args) !== ['query'] || !is_string($args['query'])
                || trim($args['query']) === '' || mb_strlen($args['query']) > 500)) self::invalid();
            $normalized[]=['id'=>$call['id'],'name'=>$fn['name'],'arguments'=>$args instanceof \stdClass ? [] : $args];
        }
        return ['content'=>$content, 'response_kind'=>'tool_calls', 'tool_calls'=>$normalized];
    }


    private function assertAllowedCalls(array $semantic): void
    {
        $schemas = [];
        foreach ($this->tools as $tool) $schemas[$tool['function']['name']] = $tool['function']['parameters'];
        foreach (($semantic['tool_calls'] ?? []) as $call) {
            if (!is_array($call) || !is_string($call['name'] ?? null) || !isset($schemas[$call['name']])
                || !is_array($call['arguments'] ?? null)) self::invalid();
            try { \app\Application\Tool\ToolSchemaPolicy::assertValue($schemas[$call['name']], $call['arguments'] === [] ? new \stdClass() : $call['arguments']); }
            catch (\Throwable) { self::invalid(); }
        }
    }

    private static function invalid(): never { throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Agent tool protocol is invalid'); }
}
