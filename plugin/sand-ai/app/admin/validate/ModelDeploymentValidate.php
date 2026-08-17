<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\validate;

use plugin\SandAi\app\api\support\ApiProblem;

final class ModelDeploymentValidate
{
    /** @param array<string, mixed> $input @return array{model_id:int,provider_id:int,remote_model:string,config:array<string,mixed>} */
    public function draft(array $input): array
    {
        $modelId = (int) ($input['model_id'] ?? 0);
        $providerId = (int) ($input['provider_id'] ?? 0);
        $remoteModel = trim((string) ($input['remote_model'] ?? ''));
        $config = $input['config'] ?? [];
        if ($modelId <= 0 || $providerId <= 0 || $remoteModel === '' || !is_array($config)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'model_id, provider_id, remote_model and config are required');
        }
        return ['model_id' => $modelId, 'provider_id' => $providerId, 'remote_model' => $remoteModel, 'config' => $config];
    }

    public function publish(array $input): int
    {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'id is required');
        }
        return $id;
    }
}
