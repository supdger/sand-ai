<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentExecutionSnapshot;
use app\Application\Onboarding\DeploymentProcessingPolicy;
use app\Application\Onboarding\DeploymentValidationEvidence;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** An audio driver must execute the same tested target, never a remote surrogate cost identity. */
final class LocalAudioDeploymentPolicy
{
    public static function assertTarget(string $adapter, string $modelType, string $runtimeCode): void
    {
        if ($adapter !== 'local_runtime' || $modelType !== 'audio_transcription' || $runtimeCode !== 'local_whisper_cpp') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Local runtime requires the registered local transcription model');
        }
    }

    public static function assertDriverTarget(string $driverCode, Provider $provider, AiModel $model, ModelDeployment $deployment): void
    {
        if ($driverCode === 'local_whisper_cpp') {
            self::assertTarget((string) $provider->adapter, (string) $model->type, (string) $deployment->remote_model);
        } elseif ((string) $provider->adapter !== 'openai_compatible') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'External audio requires an external deployment');
        }
    }

    public static function assertPublishedRuntime(Provider $provider, AiModel $model, ModelDeployment $deployment, ConfigRevision $revision): void
    {
        self::assertTarget((string) $provider->adapter, (string) $model->type, (string) $deployment->remote_model);
        $payload = is_array($revision->payload) ? $revision->payload : [];
        $processing = (new DeploymentProcessingPolicy())->fromPublishedPayload($payload);
        if ((string) $revision->state !== 'published' || (int) $revision->status !== 1
            || (int) $deployment->status !== 1
            || $processing['processing_location'] !== 'local_runtime'
            || (new DeploymentValidationEvidence())->fromPayload($payload, (int) $deployment->id, (int) $deployment->revision) === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The local transcription deployment has no current published verification');
        }
        (new DeploymentExecutionSnapshot())->assertMatches(
            is_array($payload['published_execution_snapshot'] ?? null) ? $payload['published_execution_snapshot'] : [],
            is_string($payload['published_execution_snapshot_digest'] ?? null) ? $payload['published_execution_snapshot_digest'] : '',
            $provider,
            $model,
            $deployment,
        );
    }
}
