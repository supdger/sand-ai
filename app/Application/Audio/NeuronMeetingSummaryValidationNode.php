<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

final class NeuronMeetingSummaryValidationNode extends Node
{
    public function __invoke(
        NeuronMeetingSummaryCompleted $event,
        WorkflowState $state,
        NeuronMeetingSummaryResources $resources,
    ): StopEvent {
        $content = $event->response['content'] ?? null;
        if (!is_string($content) || mb_strlen($content, 'UTF-8') > $resources->maxResultCharacters) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_RESULT_INVALID', 'Meeting node output is invalid');
        }
        $evidence = new MeetingSummaryEvidence();
        try {
            $document = $resources->outputContract?->jsonDocument($content) ?? $content;
            $presented = $evidence->present($document, $event->prepared->sources, $resources->outputContract?->claimLimits);
            $evidence->assertMergeable($presented);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode !== 'SAND_AI_AUDIO_SUMMARY_RESULT_INVALID') throw $problem;
            $missingSourcePath = preg_match('/\AMeeting summary ((?:summary|key_points|decisions|action_items|unresolved)\[(?:0|[1-9][0-9]{0,4})\]) is missing required segment_ids\z/D',
                $problem->getMessage(), $matches) === 1 ? $matches[1] . '.segment_ids' : null;
            throw new MeetingOutputRejected($resources->nodeId, $resources->stepId,
                (int) ($event->response['invocation_id'] ?? 0), hash('sha256', $content), $missingSourcePath);
        }
        $result = [
            'content' => $content, 'summary' => $presented,
            'invocation_id' => (int) ($event->response['invocation_id'] ?? 0),
            'request_id' => $resources->requestId,
        ];
        if ($result['invocation_id'] < 1) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting node invocation is missing');
        }
        $state->set('meeting_summary_result', $result);
        return new StopEvent();
    }
}
