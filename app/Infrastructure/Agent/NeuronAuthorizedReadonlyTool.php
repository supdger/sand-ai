<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeConversationContextMask;
use app\Application\Tool\ToolSchemaPolicy;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolPropertyFactory;

/** Official SDK Tool; only input is model supplied, the binding stays server owned. */
final class NeuronAuthorizedReadonlyTool extends Tool
{
    private NeuronAgentExecutionJournal $journal;

    public function __construct(private readonly array $binding, private readonly \Closure $executeAuthorized, ?NeuronAgentExecutionJournal $journal = null)
    {
        $this->journal = $journal ?? new NeuronAgentExecutionJournal();
        $this->name = self::wireName($binding);
        $this->description = KnowledgeConversationContextMask::text((string) $binding['description']);
        $this->properties = ToolPropertyFactory::fromSchema($binding['input_schema']);
        // Preserve the exact published constraints beyond the SDK's property representation.
        $this->setParameters(['parameters' => $binding['input_schema']]);
        $this->setMaxRuns(1);
    }

    public static function wireName(array $binding): string
    {
        return 'managed_' . substr((string) $binding['snapshot_sha256'], 0, 32);
    }

    public function __invoke(mixed ...$arguments): array
    {
        // SDK optional properties may arrive as null invocation arguments; use its original input map.
        $input = $this->getInputs();
        ToolSchemaPolicy::assertValue($this->binding['input_schema'], $input === [] ? new \stdClass() : $input);
        $callId=$this->getCallId();
        if ($this->journal->persistent() && (!is_string($callId) || $callId==='')) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Checkpointed readonly tool requires a stable SDK call identity');
        $callId ??= 'local_'.$this->name;
        $canonicalInput=$input === [] ? new \stdClass() : $input;
        $receipt=$this->journal->readonlyReceipt($callId,$this->name,$canonicalInput);
        if ($receipt!==null) return $receipt['output'];
        if ($this->executions() !== []) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT', 'Readonly tool already executed in this task');
        $summary = ($this->executeAuthorized)($canonicalInput);
        if (!is_array($summary) || ($summary['state'] ?? null) !== 'succeeded'
            || !is_int($summary['run_id'] ?? null) || $summary['run_id'] < 1
            || !is_array($summary['citations'] ?? null)) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Authorized readonly tool result is unavailable');
        }
        foreach ($summary['citations'] as $citation) {
            if (!is_array($citation) || array_key_exists('source_block_id', $citation)
                || !is_string($citation['source_ref'] ?? null) || !is_array($citation['locator'] ?? null)
                || (!is_string($citation['source_version'] ?? null) && !is_string($citation['content_hash'] ?? null))) {
                throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Readonly tool citation is invalid');
            }
        }
        $output = [
            'tool_run_id' => $summary['run_id'], 'result' => $summary['result'] ?? null,
            'tool_citations' => $summary['citations'], 'review_required' => (bool) ($summary['review_required'] ?? true),
        ];
        $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 32768) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Readonly tool result exceeds the model context boundary');
        $output=self::mask($output);
        $this->journal->recordReadonly($callId,$this->name,$canonicalInput,$output,['run_id' => $summary['run_id'], 'code' => $this->binding['selection']['code'],
            'version' => $this->binding['selection']['version'], 'review_required' => $output['review_required']]);
        return $output;
    }

    public function executions(): array { return $this->journal->readonlyExecutions($this->name); }

    public function checkpointBinding(): array { return ['snapshot_sha256'=>$this->binding['snapshot_sha256'],'wire_name'=>$this->name]; }

    public function bindJournal(NeuronAgentExecutionJournal $journal): void
    {
        if ($this->executions()!==[]) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Readonly journal must bind before execution');
        $this->journal=$journal;
    }

    private static function mask(mixed $value): mixed
    {
        if (is_string($value)) return KnowledgeConversationContextMask::text($value);
        if (is_array($value)) return array_map(self::mask(...), $value);
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) $copy->{$key} = self::mask($item);
            return $copy;
        }
        return $value;
    }
}
