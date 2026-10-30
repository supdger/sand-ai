<?php

declare(strict_types=1);
namespace app\Infrastructure\Agent;

use app\Api\Support\ApiProblem;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Workflow\Events\Event;

/** Recover the logical model-round cursor from committed SDK state, including inference memo replay. */
final class NeuronCheckpointMiddleware extends AgentMiddleware
{
    public function __construct(private readonly NeuronAgentExecutionJournal $journal, private readonly NeuronControlledProvider $provider) {}

    protected function beforeAgentNode(AgentNodeInterface $node, Event $event, AgentState $state, AgentResources $resources): void
    {
        $this->journal->bind($state);
        if ($node instanceof ChatNode) $this->provider->restoreCompletedRounds($state->get('__sandai_rounds', []));
    }

    protected function afterAgentNode(AgentNodeInterface $node, Event $result, AgentState $state, AgentResources $resources): void
    {
        if (!$node instanceof ChatNode) return;
        $response = $state->getResponse();
        $number = $response?->metadata('sandai_round_number');
        $outcome = $response?->metadata('sandai_accounted_outcome');
        $rounds = $state->get('__sandai_rounds', []);
        if (!is_array($rounds) || !array_is_list($rounds) || !is_int($number) || $number !== count($rounds) + 1
            || !is_array($outcome)) throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Recovered agent inference is not accounted');
        $rounds[] = $outcome;
        $state->set('__sandai_rounds', $rounds);
        $this->provider->restoreCompletedRounds($rounds);
    }
}
