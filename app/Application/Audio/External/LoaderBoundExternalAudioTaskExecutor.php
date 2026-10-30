<?php

declare(strict_types=1);

namespace app\Application\Audio\External;

use app\Api\Support\ApiProblem;
use app\Application\Audio\PrivateAudioFileLoader;
use app\Application\Audio\VerifiedPrivateAudioReference;
use app\Application\Task\TaskExecutionLease;

/**
 * Keeps the Provider send inside PrivateAudioFileLoader::withInput. Therefore
 * the temporary path exists for multipart transmission and is removed only
 * after dispatch returns; adapters never receive a storage key or free path.
 */
class LoaderBoundExternalAudioTaskExecutor implements ExternalAudioTaskExecutor
{
    public function __construct(private readonly PrivateAudioFileLoader $loader, private readonly ExternalAudioDispatchGateway $dispatches, private readonly ExternalAudioDispatchRequestFactory $requests, private readonly ExternalAudioAdapterFactory $adapters, private readonly ?\Closure $prepareBudgetDuration = null) {}

    public function execute(int $environmentId, int $taskRunId, TaskExecutionLease $lease, array $input): array
    {
        $request = $this->requests->create($environmentId, $taskRunId, $input);
        if ($request->egressPolicy !== 'approved_cloud' || $request->fileRef !== ($input['request']['file_ref'] ?? null) || $request->fileSha256 !== ($input['file_sha256'] ?? null) || $request->mediaType !== ($input['file_media_type'] ?? null)) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_REQUEST_INVALID', 'Frozen external audio task input is invalid');
        $reference = new VerifiedPrivateAudioReference($request->fileRef, $request->mediaType, $request->fileSha256, $request->trustedScope);
        $result = $this->loader->withInput($environmentId, $reference, function (\app\Application\Audio\ControlledAudioInput $input) use ($environmentId,$taskRunId,$lease,$request): array {
            if ($request->driverCode === 'glm_asr_audio') {
                GlmAsrAudioInputPolicy::assertParameters($request->parameters);
                $input = (new GlmAsrAudioInputPolicy())->verify($input, (string) config('sand_ai_audio_execution.glm_asr_audio.probe_executable', ''));
            }
            if ($request->driverCode === 'openai_compatible_audio' && $input->verifiedDurationSeconds === null) {
                $seconds = (new \app\Application\Audio\LocalAudioDurationProbe())->sourceSeconds(
                    (string) config('sand_ai_audio_execution.local_whisper_cpp.probe_executable', ''),
                    $input->temporaryPath, 30,
                );
                $input = new \app\Application\Audio\ControlledAudioInput($input->temporaryPath, $input->reference, $seconds);
            }
            $record = $this->dispatches->prepare($request, $environmentId, $taskRunId);
            $dispatchId = $record['id'] ?? null;
            if (!is_int($dispatchId) || $dispatchId < 1) throw new ApiProblem('SAND_AI_EXTERNAL_AUDIO_PERSISTENCE_FAILED', 'External audio dispatch cannot be prepared', true);
            if (($record['state'] ?? null) === 'prepared' && $this->prepareBudgetDuration !== null) {
                ($this->prepareBudgetDuration)($dispatchId,$request,$input);
            }
            return $this->dispatches->dispatch($dispatchId,new ExternalAudioDispatchClaim($taskRunId,$lease->owner,$lease->token,$lease->fence),$request,$this->adapters->create($input));
        });
        // Recovery owns the TaskRun/TaskStep terminal transition, never TaskGateway.
        return $result + ['_terminal_committed' => true];
    }
}
