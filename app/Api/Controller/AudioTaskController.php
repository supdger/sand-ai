<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Audio\AudioTranscriptionRequestPolicy;
use app\Application\Task\AudioTaskGateway;
use app\Application\Task\TaskInputAccessGuard;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\AudioTaskAccessGuard;
use app\Identity\Infrastructure\RequestAuditId;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;
use support\Response;

final class AudioTaskController
{
    /** Find a persisted transcription by its original caller key without dispatching or resuming it. */
    public function readByRequestKey(Request $request): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $query = $request->get();
            $callerKey = $query['request_key'] ?? null;
            if (array_keys($query) !== ['request_key'] || $request->rawBody() !== '' || !is_string($callerKey)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio transcription lookup requires only request_key and no body');
            }
            $authenticated = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.task.read');
            $identity = $authenticated['context'];
            $requestId = RequestIdempotencyKey::fromCallerKey($callerKey, 'audio_transcription', $identity);
            $task = TaskRun::where('environment_id', $authenticated['environment_id'])
                ->where('request_id', $requestId)->where('task_type', 'audio_transcription')->find();
            if ($task === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Audio transcription task not found');
            }
            (new TaskInputAccessGuard())->assert($task, RequestIdempotencyKey::trustedScopeForAttribution(
                UsageAttribution::fromIdentity($identity, 'task', 'sand_ai.task.read', 'task'),
            ));
            return (new TaskController())->read($request, (string) $task->id)->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    public function submit(Request $request): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $authenticated=(new SandIamContextAuthenticator())->authenticate($request,'sand_ai.audio.transcription');
            $key=RequestIdempotencyKey::required($request,'audio_transcription',$authenticated['context']);
            $payload=$request->post();
            $normalized=(new AudioTranscriptionRequestPolicy())->normalize($payload);
            $attribution=UsageAttribution::fromIdentity($authenticated['context'],'capability','sand_ai.audio.transcription','audio_transcription');
            $gateway=new AudioTaskGateway();
            $fileId=$gateway->fileIdForAuthorization((int)$authenticated['environment_id'],$payload,$attribution);
            $authorization=(new AudioTaskAccessGuard())->authorize($request,$authenticated['context'],$fileId,$normalized['file_ref'],$trace);
            return ApiResponder::success($gateway->submit((int)$authenticated['environment_id'],$payload,$key,$attribution,$authorization),$trace);
        } catch (ApiProblem $problem) { return ApiResponder::error($problem,$trace); }
    }
}
