<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\Message;

/** Working projection of SandAI's authorized persisted history. Canonical Task/Channel history remains with its existing finalizer. */
final class NeuronScopedMessageStore extends InMemoryMessageStore
{
    private bool $summaryRewrite=false;
    private ?NeuronAgentExecutionJournal $journal=null;
    public function __construct(private readonly string $threadId, array $history, ?NeuronAgentExecutionJournal $journal=null)
    {
        foreach ($history as $position=>$message) {
            if (!$message instanceof Message) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','Authorized history message is invalid');
            $message->setId('sandai_history_'.hash('sha256',json_encode([
                $threadId,$position,$message->getRole(),$message->getContent(),
                $message->getMetadata('sandai_task_id'),$message->getMetadata('sandai_source_references'),
            ],JSON_THROW_ON_ERROR)));
            $this->append($threadId,$message);
        }
        if ($journal?->history()!==null) {
            $restored=$journal->history();
            $this->threads[$threadId]=[];
            foreach ($restored['messages'] as $message) {
                if (!$message instanceof Message) throw new ApiProblem('SAND_AI_RECEIPT_INVALID','Private agent history is invalid');
                $this->append($threadId,$message);
            }
            $this->archived[$threadId]=$restored['archived'];
        }
        $this->journal=$journal;
    }

    public function loadActive(string $threadId): array { $this->assertThread($threadId); return parent::loadActive($threadId); }
    public function loadAll(string $threadId, ?int $limit=null, ?string $before=null): array { $this->assertThread($threadId); return parent::loadAll($threadId,$limit,$before); }
    public function append(string $threadId, Message $message): void
    {
        $this->assertThread($threadId);
        $existing=$this->threads[$threadId][$message->getId()]??null;
        if ($existing !== null && json_encode($existing,JSON_THROW_ON_ERROR)!==json_encode($message,JSON_THROW_ON_ERROR)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT','History message identity has different content');
        }
        if ($existing===null && count($this->threads[$threadId]??[])>=64) throw new ApiProblem('SAND_AI_AGENT_TOOL_LIMIT','Agent history limit exceeded');
        parent::append($threadId,$message);
        $this->persistHistory();
    }
    public function archive(string $threadId,int $count): void { $this->assertThread($threadId); parent::archive($threadId,$count); $this->persistHistory(); }
    public function clear(string $threadId): void
    {
        $this->assertThread($threadId);
        if (!$this->summaryRewrite) throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED','Only the authorized summary may replace the working history');
        parent::clear($threadId);
        $this->persistHistory();
    }
    public function threadId(): string { return $this->threadId; }
    /** Atomically replace the private working projection; canonical task history remains with its existing finalizer. */
    public function rewriteForSummary(\Closure $rewrite): void
    {
        if ($this->summaryRewrite) throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID','History rewrite is already running');
        $this->summaryRewrite=true;
        $messages=$this->threads[$this->threadId]??[];
        $archived=$this->archived[$this->threadId]??0;
        try {
            $rewrite();
            $this->summaryRewrite=false;
            $this->journal?->recordHistory($this->messages($this->threadId),$this->archived[$this->threadId]??0,true);
        } catch (\Throwable $failure) {
            $this->threads[$this->threadId]=$messages;
            $this->archived[$this->threadId]=$archived;
            throw $failure;
        } finally { $this->summaryRewrite=false; }
    }
    private function persistHistory(): void
    {
        if (!$this->summaryRewrite) $this->journal?->recordHistory($this->messages($this->threadId),$this->archived[$this->threadId]??0);
    }
    private function assertThread(string $threadId): void
    {
        if (!hash_equals($this->threadId,$threadId)) throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID','Agent history thread is outside its authorized scope');
    }
}
