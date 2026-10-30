<?php

declare(strict_types=1);

namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelConversationPolicy;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Workflow\Events\Event;

/** Source/coverage guards around SDK generation, formatting, safe cutoff and working-history replacement. */
final class NeuronScopedSummarization extends Summarization
{
    private bool $visited = false;

    public function __construct(
        private readonly NeuronSummaryProvider $summaryProvider,
        private readonly NeuronScopedMessageStore $store,
        private readonly array $summaryScope,
        private readonly ?NeuronAgentExecutionJournal $journal=null,
    ) {
        // The frozen policy is two complete authorized turns before summarizing once.
        // No token estimate is represented as an exact budget or provider token count.
        parent::__construct(provider: $summaryProvider, messagesToKeep: 1);
    }

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        if (!$event instanceof AIInferenceEvent || $this->visited || $this->journal?->summaryApplied()) return;
        $this->visited = true;
        // The first ChatNode has not persisted the current query: only previously authorized history is here.
        $messages = $resources->history->getMessages();
        $this->assertFrozenHistory($messages);
        if (count($this->summaryScope['history_task_ids']) < 2) return;
        $this->store->rewriteForSummary(function () use ($resources, $messages): void {
            parent::summarizeHistory($resources->history, $messages, $this->summaryProvider);
        });
        $this->summaryProvider->assertSuccessful();
    }

    protected function generateSummary(AIProviderInterface $provider, array $messages): ?string
    {
        $all = $this->store->loadActive($this->store->threadId());
        $coverage = $this->coverage($all, $messages);
        $this->summaryProvider->prepareCoverage($coverage);
        $summary = parent::generateSummary($provider, $messages);
        $this->summaryProvider->assertSuccessful();
        if (!is_string($summary) || trim($summary) === '' || strlen($summary) > 32768) {
            throw new ApiProblem('SAND_AI_AGENT_PROTOCOL_INVALID', 'Official history summary is empty or exceeds its limit');
        }
        return $summary;
    }

    /** Only a complete frozen user/assistant pair from each canonical task can enter this middleware. */
    private function assertFrozenHistory(array $messages): void
    {
        $scope = $this->summaryScope;
        if (array_diff(array_keys($scope), ['context_turn_limit','history_task_ids','closed_after_task_id','omitted_prior_turn_count']) !== []
            || !is_int($scope['context_turn_limit'] ?? null) || $scope['context_turn_limit'] < 0
            || $scope['context_turn_limit'] > 4 || !is_array($scope['history_task_ids'] ?? null)
            || !array_is_list($scope['history_task_ids'])
            || count($scope['history_task_ids']) > $scope['context_turn_limit']
            || count(array_unique($scope['history_task_ids'], SORT_REGULAR)) !== count($scope['history_task_ids'])
            || !is_int($scope['closed_after_task_id'] ?? null) || $scope['closed_after_task_id'] < 0
            || !array_key_exists('omitted_prior_turn_count', $scope)
            || ($scope['omitted_prior_turn_count'] !== null
                && (!is_int($scope['omitted_prior_turn_count']) || $scope['omitted_prior_turn_count'] < 0))
            || count($messages) !== count($scope['history_task_ids']) * 2) self::invalid();
        foreach ($scope['history_task_ids'] as $index => $taskId) {
            if (!is_int($taskId) || $taskId <= $scope['closed_after_task_id']
                || ($index > 0 && $taskId <= $scope['history_task_ids'][$index-1])) self::invalid();
            $pairSources = null;
            foreach (['user', 'assistant'] as $offset => $role) {
                $message = $messages[$index * 2 + $offset] ?? null;
                if (!$message instanceof Message || $message->getRole() !== $role
                    || !is_string($message->getContent()) || trim($message->getContent()) === ''
                    || $message->getMetadata('sandai_task_id') !== $taskId) self::invalid();
                $sources = ChannelConversationPolicy::sourceReferences($message->getMetadata('sandai_source_references') ?? []);
                if ($pairSources !== null && $pairSources !== $sources) self::invalid();
                $pairSources = $sources;
            }
        }
    }

    private function coverage(array $all, array $old): array
    {
        $oldIds = array_fill_keys(array_map(static fn (Message $m): string => $m->getId(), $old), true);
        $total = [];
        $counts = [];
        $retained = [];
        $sources = [];
        $projection = [];
        foreach ($all as $message) {
            $taskId = $message->getMetadata('sandai_task_id');
            if (!is_int($taskId)) self::invalid();
            $total[$taskId] = ($total[$taskId] ?? 0) + 1;
            $included = isset($oldIds[$message->getId()]);
            if ($included) {
                $counts[$taskId] = ($counts[$taskId] ?? 0) + 1;
                $sources = [...$sources, ...($message->getMetadata('sandai_source_references') ?? [])];
            } else {
                $retained[$taskId] = true;
            }
            $projection[] = [
                'task_id' => $taskId, 'role' => $message->getRole(), 'content' => $message->getContent(),
                'summarized' => $included,
                'source_references' => $message->getMetadata('sandai_source_references') ?? [],
            ];
        }
        $covered = [];
        $partial = [];
        foreach ($this->summaryScope['history_task_ids'] as $taskId) {
            if (($counts[$taskId] ?? 0) === $total[$taskId]) $covered[] = $taskId;
            elseif (($counts[$taskId] ?? 0) > 0) $partial[] = $taskId;
        }
        $coverage = [
            'version' => 1,
            ...$this->summaryScope,
            'covered_task_ids' => $covered,
            'partially_covered_task_ids' => $partial,
            'retained_task_ids' => array_values(array_map('intval', array_keys($retained))),
            // Older excluded task identities were never loaded; their count is reported above, never invented.
            'excluded_task_ids' => [],
            'source_references' => ChannelConversationPolicy::sourceReferences($sources),
            'history_projection_sha256' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
        $coverage['coverage_sha256'] = hash('sha256', json_encode($coverage, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $coverage;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Authorized summary history coverage is invalid');
    }
}
