<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentExecutionSnapshot;
use app\Application\Onboarding\DeploymentProcessingPolicy;
use app\Application\Onboarding\DeploymentValidationEvidence;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ConfigRevision;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

final class LocalOcrDeploymentPolicy
{
    public static function assertTarget(string $adapter, string $type, string $runtime): void
    {
        if ($adapter !== 'local_ocr_runtime' || $type !== 'image_ocr' || $runtime !== 'local_paddle_ocr') {
            throw new ApiProblem('SAND_AI_CAPABILITY_UNSUPPORTED', 'Local OCR requires its registered OCR deployment');
        }
    }

    public static function assertPublishedRuntime(Provider $provider, AiModel $model, ModelDeployment $deployment, ConfigRevision $revision): void
    {
        self::assertTarget((string) $provider->adapter, (string) $model->type, (string) $deployment->remote_model);
        $payload = is_array($revision->payload) ? $revision->payload : [];
        if ((int) $provider->status !== 1 || (int) $model->status !== 1 || (int) $deployment->status !== 1
            || $model->capabilities !== ['image_ocr'] || $deployment->config !== []
            || (int) $deployment->provider_id !== (int) $provider->id || (int) $deployment->model_id !== (int) $model->id
            || (int) $revision->resource_id !== (int) $deployment->id || (int) $revision->revision !== (int) $deployment->revision
            || (string) $revision->state !== 'published' || (int) $revision->status !== 1
            || (new DeploymentProcessingPolicy())->fromPublishedPayload($payload)['processing_location'] !== 'local_runtime'
            || (new DeploymentValidationEvidence())->fromPayload($payload, (int) $deployment->id, (int) $deployment->revision) === null) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The OCR deployment has no current published verification');
        }
        (new DeploymentExecutionSnapshot())->assertMatches(
            is_array($payload['published_execution_snapshot'] ?? null) ? $payload['published_execution_snapshot'] : [],
            is_string($payload['published_execution_snapshot_digest'] ?? null) ? $payload['published_execution_snapshot_digest'] : '',
            $provider, $model, $deployment,
        );
    }

    /** Same lock order as deployment publication: provider, model, deployment, revision. */
    public static function publishedTarget(int $id, bool $lock = false): array
    {
        $reference = ModelDeployment::where('id', $id)->find();
        if ($reference === null) throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The OCR deployment is unavailable');
        $providerQuery = Provider::where('id', (int) $reference->provider_id)->whereNull('delete_time');
        $modelQuery = AiModel::where('id', (int) $reference->model_id)->whereNull('delete_time');
        $deploymentQuery = ModelDeployment::where('id', $id)->whereNull('delete_time');
        if ($lock) { $providerQuery->lock(true); $modelQuery->lock(true); $deploymentQuery->lock(true); }
        $provider = $providerQuery->find(); $model = $modelQuery->find(); $deployment = $deploymentQuery->find();
        if ($provider === null || $model === null || $deployment === null) throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'The OCR deployment is unavailable');
        $revisionQuery = ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', $id)
            ->where('revision', (int) $deployment->revision)->whereNull('delete_time');
        if ($lock) $revisionQuery->lock(true);
        $revision = $revisionQuery->find();
        if ($revision === null) throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'The OCR deployment is not published');
        self::assertPublishedRuntime($provider, $model, $deployment, $revision);
        return ['provider_id' => (int) $provider->id, 'model_id' => (int) $model->id, 'deployment_id' => $id,
            'provider_snapshot' => ['id' => (int) $provider->id, 'code' => (string) $provider->code, 'name' => (string) $provider->name, 'adapter' => 'local_ocr_runtime'],
            'model_snapshot' => ['id' => (int) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->name, 'type' => 'image_ocr'],
            'deployment_snapshot' => ['id' => $id, 'remote_model' => 'local_paddle_ocr', 'revision' => (int) $deployment->revision,
                'execution_snapshot_digest' => $revision->payload['published_execution_snapshot_digest']]];
    }
}
