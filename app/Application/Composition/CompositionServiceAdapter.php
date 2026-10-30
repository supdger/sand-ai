<?php

declare(strict_types=1);

namespace app\Application\Composition;

use app\Api\Controller\AgentController;
use app\Api\Controller\KnowledgeBaseController;
use app\Api\Controller\SkillController;
use app\Api\Controller\StructuredWorkflowController;
use app\Api\Controller\TaskController;
use app\Api\Controller\ToolController;
use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentGateway;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use support\Request;
use support\Response;

/** Calls the existing workload surfaces in process; never introduces a second dispatch or billing path. */
final class CompositionServiceAdapter
{
    public function __construct(private readonly Request $original) {}

    public function submit(array $frozen, array $input, string $originalKey): array
    {
        $this->current($frozen, true);
        $this->validateInput($frozen, $input);
        $request = $this->request('POST', $input, [], $originalKey);
        $keywordProof = null;
        try {
            $data = match ($frozen['kind']) {
                'agent' => $this->submitAgent($frozen, $request),
                'skill' => $this->data((new SkillController())->run($this->request('POST',['input'=>$input,'request_id'=>$originalKey],[],$originalKey),$frozen['code'],(string)$frozen['version'])),
                'material' => $this->data((new StructuredWorkflowController())->submit($this->request('POST',$input+[
                    'workflow'=>$frozen['target']['workflow'],'analysis_profile'=>['code'=>$frozen['code'],'version'=>$frozen['version']],
                    'output_schema'=>$frozen['target']['output_schema'],'review_required'=>true,
                ],[],$originalKey))),
                'knowledge' => $this->data((new KnowledgeBaseController())->query($request,$frozen['code'],
                    static function (array $proof) use (&$keywordProof): void { $keywordProof = $proof; })),
                'tool' => $this->data((new ToolController())->run($request,$frozen['code'],(string)$frozen['version'])),
                default => throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Unknown node service'),
            };
        } catch (ApiProblem $problem) {
            // Admission could have persisted or sent before an error response. No automatic re-submission.
            return $this->unknown($frozen,$originalKey,$problem->errorCode);
        } catch (\Throwable) {
            return $this->unknown($frozen,$originalKey,'SAND_AI_COMPOSITION_NODE_UNKNOWN');
        }
        $this->current($frozen, true);
        $result = $this->result($frozen,$originalKey,$data);
        if ($keywordProof !== null && $frozen['kind'] === 'knowledge' && $result['state'] === 'succeeded') {
            $result['child']['keyword_cost_proof'] = $keywordProof + [
                'node_request_key' => $originalKey,
                'reference_sha256' => $frozen['reference_sha256'],
                'input_sha256' => CompositionGraphPolicy::digest($input),
                'output_sha256' => CompositionGraphPolicy::digest($result['output']),
            ];
        }
        return $result;
    }

    public function recover(array $frozen, array $input, string $originalKey, ?array $child): array
    {
        $this->current($frozen);
        if ($child !== null && (($child['kind'] ?? null)!==$frozen['kind'] || ($child['code'] ?? null)!==$frozen['code']
            || ($child['version'] ?? null)!==$frozen['version'] || ($child['request_key'] ?? null)!==$originalKey)) {
            throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Child locator does not match its frozen service');
        }
        if ($child!==null && isset($child['continuation_task_id'])
            && ($frozen['kind']!=='agent' || !is_int($child['continuation_task_id']) || $child['continuation_task_id']<1
                || $child['continuation_task_id']!==($child['task_id'] ?? null) || !is_string($child['continuation_request_key'] ?? null))) {
            throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Continuation locator does not match the original task');
        }
        try {
            $data = match ($frozen['kind']) {
                'agent' => $child !== null && is_int($child['continuation_task_id'] ?? null)
                    ? $this->data((new TaskController())->read($this->request('GET',[],[],$originalKey),(string)$child['continuation_task_id']))
                    : $this->data((new AgentController())->readPublishedByRequestKey($this->request('GET',[],[],$originalKey),$frozen['code'])),
                'skill' => $this->data((new SkillController())->readRun($this->request('GET',[],['request_id'=>$originalKey],$originalKey),$frozen['code'],(string)$frozen['version'])),
                'material' => $this->data((new StructuredWorkflowController())->runByRequestKey($this->request('GET',[],['request_key'=>$originalKey],$originalKey))),
                'knowledge' => $this->recoverKnowledge($frozen,$input,$originalKey),
                'tool' => $this->data((new ToolController())->readByKey($this->request('GET',[],['request_key'=>$originalKey],$originalKey))),
                default => throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Unknown node service'),
            };
        } catch (ApiProblem $problem) {
            if (in_array($problem->errorCode,['SAND_AI_RESOURCE_NOT_FOUND','SAND_AI_DISPATCH_AMBIGUOUS','SAND_AI_INVOCATION_NOT_SUCCEEDED','SAND_AI_COMPOSITION_INPUT_PURGED'],true)) {
                return $this->unknown($frozen,$originalKey,$problem->errorCode,$child);
            }
            // Current IAM denials propagate; the parent must withhold earlier output.
            throw $problem;
        }
        $this->current($frozen);
        $result=$this->result($frozen,$originalKey,$data);
        if ($child!==null) foreach (['continuation_task_id','continuation_request_key'] as $field) {
            if (isset($child[$field])) $result['child'][$field]=$child[$field];
        }
        return $result;
    }

    public function retry(array $frozen, array $input, string $originalKey, array $child, string $retryKey): array
    {
        $current=$this->recover($frozen,$input,$originalKey,$child);
        if ($current['state']!=='failed' || !in_array($frozen['kind'],['agent','material'],true)
            || !is_int($current['child']['task_id'] ?? null) || $current['child']['task_id']<1) {
            throw new ApiProblem('SAND_AI_COMPOSITION_RETRY_UNSUPPORTED','Only an existing proven failed task may use its formal retry operation');
        }
        $taskId=(string)$current['child']['task_id'];
        $this->current($frozen);
        if ($frozen['kind']==='agent') {
            if (($current['child']['continuation_available'] ?? false)!==true) throw new ApiProblem('SAND_AI_COMPOSITION_RETRY_UNSUPPORTED','Original Agent task has no safe continuation');
            $continuationChild=[...$current['child'],'continuation_task_id'=>(int)$taskId,'continuation_request_key'=>$retryKey];
            try {
                $response=(new AgentController())->continueTask($this->request('POST',[],[],$retryKey),$taskId);
                $admitted=$this->data($response);
            } catch (\Throwable) {
                return $this->unknown($frozen,$originalKey,'SAND_AI_COMPOSITION_NODE_UNKNOWN',$continuationChild);
            }
            // The existing SDK continuation updates the SAME task in place.
            // Use the formal returned task id; never resolve/re-submit the published Agent.
            if (!is_int($admitted['id'] ?? null) || $admitted['id']!==(int)$taskId) {
                return $this->unknown($frozen,$originalKey,'SAND_AI_COMPOSITION_NODE_UNKNOWN',$continuationChild);
            }
            return $this->recover($frozen,$input,$originalKey,$continuationChild);
        } else {
            // TaskController/TaskGateway decide dispatch evidence, attempts and original qualification.
            $response=(new TaskController())->retry($this->request('POST',[],[],$retryKey),$taskId);
        }
        $this->data($response);
        return $this->recover($frozen,$input,$originalKey,$current['child']);
    }

    private function submitAgent(array $frozen, Request $request): array
    {
        $authenticator=new SandIamContextAuthenticator();
        $submit=$authenticator->authenticate($request,'sand_ai.agent.submit');
        $run=$authenticator->authenticate($request,'sand_ai.agent.run');
        $knowledge=$authenticator->authenticate($request,'sand_ai.knowledge.query');
        foreach ([$run,$knowledge] as $part) foreach (['organizationId','applicationId','environmentId','workloadClientId'] as $dimension) {
            if ($submit['context']->{$dimension}!==$part['context']->{$dimension}) throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE','Agent identity scope changed');
        }
        if ($submit['environment_id']!==$frozen['environment_id']) throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Node identity environment changed');
        $target=$frozen['target'];
        (new AgentDefinitionGateway())->assertCurrent($submit['environment_id'],$target['binding']);
        $key=RequestIdempotencyKey::requiredForResource($request,'agent',$submit['context'],$frozen['code']);
        $audit=RequestAuditId::ensure($request);
        return (new AgentGateway())->submit($submit['environment_id'],[...$target['config'],...$request->post()],$key,
            UsageAttribution::fromIdentity($submit['context'],'agent','sand_ai.agent.submit'),
            static fn(int $taskId):array=>(new TaskRunAccessGuard())->authorize($request,$submit['context'],$taskId,'agent_run',$audit),
            $target['binding']);
    }

    private function recoverKnowledge(array $frozen,array $input,string $key): array
    {
        if ($input===[]) throw new ApiProblem('SAND_AI_COMPOSITION_INPUT_PURGED','Original private query was cleared; key remains available for usage attribution');
        // This formal endpoint reads original receipts and verifies retrieval; it never embeds/reranks anew.
        return $this->data((new KnowledgeBaseController())->queryByRequestKey($this->request('POST',$input,[],$key),$frozen['code']));
    }

    private function current(array $frozen, bool $dispatch = false): void
    {
        $environment=$frozen['environment_id'] ?? null;
        if (!is_int($environment)) throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Frozen environment is invalid');
        $action=match ($frozen['kind'] ?? null) {
            'agent'=>$dispatch?'sand_ai.agent.submit':'sand_ai.task.read',
            'skill'=>'sand_ai.skill.run',
            'material'=>$dispatch?'sand_ai.workflow.run':'sand_ai.workflow.read',
            'knowledge'=>'sand_ai.knowledge.query',
            'tool'=>$dispatch?'sand_ai.tool.run':'sand_ai.tool.read',
            default=>throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Unknown node service'),
        };
        $identity=(new SandIamContextAuthenticator())->authenticate($this->original,$action);
        if ($identity['environment_id']!==$environment) throw new ApiProblem('SAND_AI_COMPOSITION_REFERENCE_CHANGED','Current child identity environment differs from the frozen service');
        (new CompositionServiceCatalog())->assertCurrent($environment,$frozen);
    }

    public function validateInput(array $frozen,array $input): void
    {
        $properties=$frozen['input_schema']['properties'];
        if (array_diff(array_keys($input),array_keys($properties))!==[] || array_diff($frozen['input_schema']['required'] ?? [],array_keys($input))!==[]) {
            throw new ApiProblem('SAND_AI_COMPOSITION_GRAPH_INVALID','Node input fields do not match the selected service contract');
        }
        foreach ($input as $name=>$value) {
            $valid=match($properties[$name]['type']) {
                'string'=>is_string($value),'integer'=>is_int($value),'number'=>is_int($value)||is_float($value),'boolean'=>is_bool($value),
                'array'=>is_array($value)&&array_is_list($value),'object'=>is_array($value)&&($value===[]||!array_is_list($value)),
                default=>false,
            };
            if (!$valid) throw new ApiProblem('SAND_AI_COMPOSITION_GRAPH_INVALID','Node input type does not match '.$name);
        }
        if ($frozen['kind']==='agent') \app\Application\Agent\AgentDefinitionPolicy::runInput($input);
        if ($frozen['kind']==='knowledge') \app\Application\Knowledge\KnowledgeBasePolicy::normalizeConsumerQuery($input);
        if ($frozen['kind']==='material') \app\Application\Workflow\StructuredWorkflowPolicy::normalizeRequest(
            $input+['workflow'=>$frozen['target']['workflow'],'analysis_profile'=>['code'=>$frozen['code'],'version'=>$frozen['version']],
                'output_schema'=>$frozen['target']['output_schema'],'review_required'=>true],
            new \app\Application\Workflow\WorkflowDefinitionRegistry(),$frozen['environment_id'],
        );
        if ($frozen['kind']==='skill') \app\Application\Skill\SkillSchemaPolicy::assertValue($frozen['input_schema'],$input);
        if ($frozen['kind']==='tool') \app\Application\Tool\ToolSchemaPolicy::assertValue($frozen['target']['input_schema'],$input['input']);
    }

    private function request(string $method,array $body,array $query,string $key): CompositionNodeRequest
    {
        return new CompositionNodeRequest($this->original,$method,$body,$query,$key);
    }

    private function data(Response $response): array
    {
        $payload=json_decode($response->rawBody(),true,128,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || ($payload['code'] ?? null)!==200 || !is_array($payload['data'] ?? null)) {
            $code=$payload['data']['error_code'] ?? 'SAND_AI_COMPOSITION_NODE_UNKNOWN';
            throw new ApiProblem(is_string($code)?$code:'SAND_AI_COMPOSITION_NODE_UNKNOWN','Child service did not return an authorized result');
        }
        return $payload['data'];
    }

    private function locator(array $frozen,string $key): array
    {
        return ['kind'=>$frozen['kind'],'code'=>$frozen['code'],'version'=>$frozen['version'],'request_key'=>$key];
    }

    private function unknown(array $frozen,string $key,string $error,?array $child=null): array
    {
        return ['state'=>'unknown','child'=>$child ?? $this->locator($frozen,$key),'output'=>null,'error_code'=>$error];
    }

    private function result(array $frozen,string $key,array $data): array
    {
        $task=is_array($data['task'] ?? null)?$data['task']:null;
        $state=$data['state'] ?? $task['state'] ?? ($frozen['kind']==='knowledge'?'succeeded':null);
        $normalized=match($state) {
            'succeeded'=>'succeeded','failed','canceled','cancelled'=>'failed',
            'queued','running','pending','retrying','authorization_pending','recovery_pending'=>'waiting',default=>'unknown',
        };
        $child=$this->locator($frozen,$key);
        $runId=$data['run_id'] ?? ($frozen['kind']==='material'?($data['id'] ?? null):null);
        if (is_int($runId) && $runId>0) $child['run_id']=$runId;
        $taskId=$task['id'] ?? ($frozen['kind']==='agent'?($data['id'] ?? null):null);
        if (is_int($taskId) && $taskId>0) $child['task_id']=$taskId;
        foreach (['invocation_id','invocation_request_id','audit_ref'] as $field) if (isset($data[$field]) && (is_string($data[$field])||is_int($data[$field]))) $child[$field]=$data[$field];
        if ($frozen['kind']==='knowledge') {
            $child['publication_id']=(int)$frozen['target']['id'];
            $child['knowledge_version_id']=(int)$frozen['target']['active_version_id'];
            foreach (['embedding_invocation','rerank_invocation'] as $field) {
                if (is_array($data[$field] ?? null)) {
                    $reference=array_intersect_key($data[$field],array_flip(['id','invocation_id','request_id']));
                    if ($reference!==[]) $child[$field]=$reference;
                }
            }
            foreach (['embedding_usage','rerank_usage'] as $field) {
                if (is_string($data[$field]['request_id'] ?? null)) $child[$field]=['request_id'=>$data[$field]['request_id']];
            }
        }
        $invocationKey=$data['result']['invocation']['request_id'] ?? null;
        if (is_string($invocationKey)) $child['invocation_request_id']=$invocationKey;
        if (is_array($data['agent_invocations'] ?? null)) {
            $child['agent_invocations']=[];
            foreach ($data['agent_invocations'] as $invocation) if (is_array($invocation)) {
                $reference=array_intersect_key($invocation,array_flip(['id','invocation_id','request_id']));
                if ($reference!==[]) $child['agent_invocations'][]=$reference;
            }
        }
        $continuation=$data['continuation'] ?? $task['continuation'] ?? null;
        if (is_array($continuation)) $child['continuation_available']=($continuation['available'] ?? false)===true;
        // State/replay/usage fields can change on a later read. Only sealed business
        // content and source references participate in the parent's output digest.
        $output=array_intersect_key($data,array_flip(['output','result','citations','file_results','knowledge_base','review_required']));
        if ($frozen['kind']==='agent' && is_array($output['result'] ?? null)) {
            $output['result']=array_intersect_key($output['result'],array_flip(['output','source_references','tool_calls','review_required']));
        }
        if (($data['content_purged'] ?? false)===true) return $this->unknown($frozen,$key,'SAND_AI_COMPOSITION_CHILD_CONTENT_PURGED',$child);
        return ['state'=>$normalized,'child'=>$child,'output'=>$normalized==='succeeded'?$output:null,'error_code'=>is_string($data['error_code'] ?? $task['error_code'] ?? null)?($data['error_code'] ?? $task['error_code']):null];
    }
}
