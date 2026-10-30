<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/** The SDK executes this Tool through its ToolNode; authorization remains the caller's current guard. */
final class NeuronAuthorizedRetrievalTool extends Tool
{
    private readonly NeuronAgentExecutionJournal $journal;

    public function __construct(private readonly \Closure $retrieve, private readonly int $runLimit = 2, ?NeuronAgentExecutionJournal $journal = null)
    {
        if (!in_array($runLimit, [1, 2], true)) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Retrieval limit is invalid');
        $this->journal = $journal ?? new NeuronAgentExecutionJournal();
        $this->name = 'retrieval_search';
        $this->description = 'Search the currently authorized knowledge sources. Return source block ids and excerpts for citations.';
        $this->properties = [new ToolProperty('query', PropertyType::STRING, 'Search query, 1 to 500 characters.', true)];
        $this->setMaxRuns($runLimit);
    }

    public function __invoke(string $query): array
    {
        if (trim($query)==='' || mb_strlen($query)>500) {
            throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Authorized retrieval input or run limit exceeded');
        }
        $callId=$this->getCallId();
        if ($this->journal->persistent() && (!is_string($callId) || $callId==='')) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Checkpointed retrieval requires a stable SDK call identity');
        $callId ??= 'local_'.(count($this->executions())+1);
        $receipt=$this->journal->retrieval($callId,$query);
        if ($receipt!==null) return $receipt['output'];
        if (count($this->executions())>=$this->runLimit) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Authorized retrieval run limit exceeded');
        $result=($this->retrieve)($query);
        if (!is_array($result) || strlen(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>32768) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Authorized retrieval output exceeds its protocol boundary');
        }
        $sourceIds=[];
        foreach (($result['citations']??[]) as $citation) {
            if (!is_array($citation) || !is_int($citation['source_block_id']??null) || $citation['source_block_id']<1) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Authorized retrieval citation is invalid');
            $sourceIds[]=$citation['source_block_id'];
        }
        $this->journal->recordRetrieval($callId,$query,$result,['tool'=>'retrieval.search','source_block_ids'=>array_values(array_unique($sourceIds)),'sequence_no'=>count($this->executions())+1,'query_sha256'=>hash('sha256',$query)]);
        return $result;
    }

    public function executions(): array { return $this->journal->retrievalExecutions(); }
}
