<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeConversationContextMask;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;

/** Actual SDK Agent/ChatNode/ToolNode/MessageStore execution, with SandAI controlling every inference. */
final class NeuronAgentCaller
{
    public function complete(string $model,string $instructions,string $query,string $threadId,array $historyMessages,\Closure $dispatchRound,\Closure $retrieve,?\Closure $dispatchSummary=null,array $summaryScope=[],bool $stream=false,array $readonlyTools=[],?NeuronAgentCheckpoint $checkpoint=null): array
    {
        if (!\Composer\InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) \Composer\InstalledVersions::getVersion('neuron-core/neuron-ai'), '4.0.2', '>=')) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID','The required Neuron agent runtime is unavailable');
        }
        if (count($historyMessages)>48) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Authorized agent history exceeds its message limit');
        $history=[];
        foreach ($historyMessages as $row) {
            if (!is_array($row) || !in_array($row['role']??null,['user','assistant'],true) || !is_string($row['content']??null)) {
                throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Authorized agent history is invalid');
            }
            $message=new Message(MessageRole::from($row['role']),KnowledgeConversationContextMask::text($row['content']));
            if ($dispatchSummary!==null) {
                if (!is_int($row['task_id']??null) || $row['task_id']<1
                    || !is_array($row['source_references']??null)) {
                    throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','Summary requires canonical task and source coverage');
                }
                $message->addMetadata('sandai_task_id',$row['task_id'])
                    ->addMetadata('sandai_source_references',$row['source_references']);
            }
            $history[]=$message;
        }
        if (count($readonlyTools)>2 || !array_is_list($readonlyTools)) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Readonly tool count is invalid');
        foreach ($readonlyTools as $readonlyTool) if (!$readonlyTool instanceof NeuronAuthorizedReadonlyTool) throw new ApiProblem('SAND_AI_AGENT_TOOL_FORBIDDEN','Readonly tool instance is invalid');
        if ($checkpoint!==null && $checkpoint->workflowId!==$threadId) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','Checkpoint thread differs from the original agent thread');
        $journal=new NeuronAgentExecutionJournal($checkpoint);
        if ($checkpoint!==null) $journal->assertDefinition(hash('sha256',json_encode([
            'version'=>1,'model'=>$model,'instructions'=>$instructions,'query'=>$query,'thread_id'=>$threadId,
            'history'=>$historyMessages,'summary_scope'=>$summaryScope,'summary_enabled'=>$dispatchSummary!==null,
            'stream'=>$stream,'readonly_tools'=>array_map(static fn(NeuronAuthorizedReadonlyTool $tool):array=>$tool->checkpointBinding(),$readonlyTools),
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        foreach ($readonlyTools as $readonlyTool) $readonlyTool->bindJournal($journal);
        $tool=new NeuronAuthorizedRetrievalTool($retrieve,$readonlyTools===[]?2:1,$journal);
        $provider=new NeuronControlledProvider($model,$dispatchRound,$stream,$checkpoint===null?null:$journal);
        $store=new NeuronScopedMessageStore($threadId,$history,$checkpoint===null?null:$journal);
        $agent=new Agent($threadId);
        $agent->setAiProvider($provider)->setInstructions($instructions)->setTools([$tool,...$readonlyTools])
            ->toolMaxRuns($readonlyTools===[]?2:3)->parallelToolCalls(false)->setMessageStore($store)->setContextWindow(1000000);
        if ($checkpoint!==null) {
            $agent->setPersistence($checkpoint->persistence)->setSerializer(new NeuronCheckpointSerializer())->retainCompletionUntilAcknowledged();
            $agent->subscribe(WorkflowStart::class,static function(WorkflowStart $event) use ($journal):void { $journal->captureAdmission($event->execution); });
            $middleware=new NeuronCheckpointMiddleware($journal,$provider);
            $agent->addGlobalMiddleware(static fn():NeuronCheckpointMiddleware=>$middleware);
        }
        $summaryProvider=null;
        if ($dispatchSummary!==null) {
            $summaryProvider=new NeuronSummaryProvider($model,$dispatchSummary,$checkpoint===null?null:$journal);
            $summary=new NeuronScopedSummarization($summaryProvider,$store,$summaryScope,$checkpoint===null?null:$journal);
            // Return this execution-scoped instance; SDK clones directly registered middleware.
            $agent->addMiddleware(ChatNode::class,static fn():NeuronScopedSummarization=>$summary);
        }
        $executionRequest=null;
        if ($checkpoint!==null) {
            $startEvent=new AgentStartEvent();
            $startEvent->options->stream=$stream;
            $startEvent->messages=[new UserMessage($query)];
            $executionRequest=$checkpoint->request($startEvent,$journal->runtime());
        }
        if ($stream) {
            $events=$executionRequest===null?$agent->stream(new UserMessage($query)):$agent->events($executionRequest);
            // Actual provider chunks were authorized/persisted by dispatchRound before this generator yields them.
            foreach ($events as $event) {}
            $state=$events->getReturn();
        } else {
            $state=$executionRequest===null?$agent->chat(new UserMessage($query)):$agent->run($executionRequest);
        }
        $rounds=$checkpoint===null?$provider->rounds():$state->get('__sandai_rounds',[]);
        $last=$rounds===[]?null:$rounds[array_key_last($rounds)];
        if ($state->getStatus()!==WorkflowStatus::Completed || $state->getResponse()===null || !is_array($last) || ($last['response_kind']??null)!=='text'
            || $tool->executions()===[]) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Agent must complete an authorized retrieval tool call before its final answer');
        try { \support\Log::info('SandAI Neuron agent completed',['framework_version'=>'4.0.2','model_round_count'=>count($rounds),'tool_run_count'=>count($tool->executions())]); } catch (\Throwable) {}
        return ['response'=>$last,'rounds'=>$rounds,'tool_calls'=>$tool->executions(),'readonly_tool_runs'=>array_merge(...array_map(static fn(NeuronAuthorizedReadonlyTool $tool):array=>$tool->executions(),$readonlyTools)),'summary'=>$summaryProvider?->result()??self::restoredSummary($journal),...($checkpoint===null?[]:['checkpoint'=>['run_id'=>$state->getRunId(),'execution_attempt'=>$state->getExecutionAttempt(),'state'=>$state->getStatus()->value]])];
    }
    private static function restoredSummary(NeuronAgentExecutionJournal $journal): ?array
    {
        $summary=$journal->summary();
        if ($summary===null) return null;
        $outcome=$summary['outcome'];
        return ['coverage'=>$summary['coverage'],'invocation'=>$outcome['invocation'],'invocation_id'=>$outcome['invocation_id'],'usage'=>$outcome['usage']];
    }
}

