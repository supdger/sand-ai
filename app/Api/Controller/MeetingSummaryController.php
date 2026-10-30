<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Task\AiTaskGateway;
use app\Application\Task\TaskInputAccessGuard;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\TaskRunAccessGuard;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;
use support\Response;

/** Creates a billed AI task from a completed, private audio transcription. */
final class MeetingSummaryController
{
    /** Locate a persisted summary by its original caller key; the formal reader owns resource authorization. */
    public function readByRequestKey(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $query = $request->get();
            $callerKey = $query['request_key'] ?? null;
            if ($id === false || array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($callerKey)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Meeting summary lookup requires a valid audio task id, only request_key and no body');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.read');
            $identity = $authenticated['context'];
            $requestId = RequestIdempotencyKey::fromCallerKey($callerKey, 'meeting_summary', $identity);
            $task = TaskRun::where('environment_id', $authenticated['environment_id'])
                ->where('request_id', $requestId)->where('task_type', 'ai_completion')->find();
            if ($task === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Meeting summary task not found');
            }
            (new TaskInputAccessGuard())->assert($task, RequestIdempotencyKey::trustedScopeForAttribution(
                UsageAttribution::fromIdentity($identity, 'task', 'sand_ai.task.read', 'task'),
            ));
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $meeting = is_array($input) ? ($input['_meeting_summary'] ?? null) : null;
            if (!is_array($meeting) || !is_int($meeting['audio_task_id'] ?? null) || $meeting['audio_task_id'] !== (int) $id) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Meeting summary task not found');
            }
            return (new TaskController())->read($request, (string) $task->id)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    public function submit(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid audio task id is required');
            }
            $authenticator = new SandIamContextAuthenticator();
            $chat = $authenticator->authenticate($request, 'sand_ai.chat.complete');
            $audio = $authenticator->authenticate($request, 'sand_ai.audio.transcription');
            if ((int) $chat['environment_id'] !== (int) $audio['environment_id']
                || !hash_equals(
                    RequestIdempotencyKey::trustedScope($chat['context']),
                    RequestIdempotencyKey::trustedScope($audio['context']),
                )) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
            }
            $body = $request->post();
            if (!is_array($body) || array_diff(array_keys($body), ['model', 'max_output_tokens', 'review_revision']) !== []
                || !is_string($body['model'] ?? null) || trim($body['model']) === '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A chat model is required for meeting summary');
            }
            $reviewRevision = $body['review_revision'] ?? 0;
            if (!is_int($reviewRevision) || $reviewRevision < 0 || $reviewRevision > 10000) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'An exact audio review revision is required');
            }
            $attribution = UsageAttribution::fromIdentity(
                $chat['context'], 'ai_task', 'sand_ai.chat.complete',
            );
            $requestId = RequestIdempotencyKey::required(
                $request, 'meeting_summary', $chat['context'],
            );
            $oldMeetingTask = \app\Infrastructure\Persistence\Model\TaskRun::where('request_id', $requestId)->find();
            $durable = $oldMeetingTask === null
                ? \app\Application\Audio\MeetingExecutionRuntime::available()
                : \app\Application\Audio\MeetingExecutionRuntime::forTask($oldMeetingTask);
            $sourceReader = new MeetingSummarySource();
            $source = $durable
                ? $sourceReader->compact((int) $chat['environment_id'], (int) $id, $attribution, $reviewRevision)
                : $sourceReader->load((int) $chat['environment_id'], (int) $id, $attribution, null, $reviewRevision, true);
            if ($source['data_egress_policy'] !== 'approved_cloud') {
                throw new ApiProblem('SAND_AI_DATA_EGRESS_FORBIDDEN', 'This audio source cannot use the published summary route');
            }
            $audioAuthorization = (new AudioTaskAccessGuard())->authorize(
                $request, $audio['context'], $source['file_id'], $source['file_ref'], $trace,
            );
            return ApiResponder::success((new AiTaskGateway())->submit(
                (int) $chat['environment_id'],
                [
                    'model' => trim($body['model']),
                    'messages' => MeetingSummarySource::taskMarker((int) $id, $source['sha256']),
                    'source_block_ids' => [],
                    'max_output_tokens' => $body['max_output_tokens'] ?? null,
                ],
                $requestId,
                $attribution,
                static fn (int $summaryTaskId): array => (new TaskRunAccessGuard())->authorize(
                    $request, $chat['context'], $summaryTaskId, 'ai_completion', $trace,
                ),
                [
                    'audio_task_id' => (int) $id,
                    'file_id' => $source['file_id'],
                    'sha256' => $source['sha256'],
                    'review_revision' => $reviewRevision,
                    'data_class' => $source['data_class'],
                    'data_egress_policy' => $source['data_egress_policy'],
                    'audio_authorization' => $audioAuthorization,
                    'transcript_seal' => $source['transcript_seal'],
                    'aggregation_source' => $source['aggregation_source'] ?? null,
                ],
            ), $trace);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }
}
