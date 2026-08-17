<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\admin\validate\ModelDeploymentValidate;
use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\domain\capability\CapabilityProfilePolicy;
use plugin\SandAi\app\model\AiModel;
use plugin\SandAi\app\model\AuditLog;
use plugin\SandAi\app\model\ConfigRevision;
use plugin\SandAi\app\model\ModelDeployment;
use plugin\SandAi\app\model\Provider;
use think\facade\Db;

/** Package-local deployment draft/publish use case. */
final class ModelDeploymentLogic
{
    public function __construct(private readonly ModelDeploymentValidate $validate)
    {
    }

    /** @param array<string, mixed> $input @return array{id:int,revision:int} */
    public function draft(array $input): array
    {
        $data = $this->validate->draft($input);
        if (!AiModel::where('id', $data['model_id'])->where('status', 1)->find()
            || !Provider::where('id', $data['provider_id'])->where('status', 1)->find()) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Model or provider not found');
        }
        return Db::connect('pgsql')->transaction(function () use ($data): array {
            $revision = (int) ModelDeployment::where('model_id', $data['model_id'])->max('revision') + 1;
            $deployment = ModelDeployment::create([
                'model_id' => $data['model_id'],
                'provider_id' => $data['provider_id'],
                'remote_model' => $data['remote_model'],
                'config' => $data['config'],
                'revision' => $revision,
                'status' => 2,
            ]);
            ConfigRevision::create([
                'resource_type' => 'model_deployment',
                'resource_id' => (int) $deployment->id,
                'revision' => $revision,
                'payload' => ['remote_model' => $data['remote_model'], 'config' => $data['config']],
                'state' => CapabilityProfilePolicy::DRAFT,
                'status' => 1,
            ]);
            $this->audit('deployment.draft', (int) $deployment->id, 'Created SandAI model deployment draft');
            return ['id' => (int) $deployment->id, 'revision' => $revision];
        });
    }

    /** @param array<string, mixed> $input @return array{id:int,revision:int} */
    public function publish(array $input): array
    {
        $id = $this->validate->publish($input);
        return Db::connect('pgsql')->transaction(function () use ($id): array {
            $deployment = ModelDeployment::findOrEmpty($id);
            if ($deployment->isEmpty()) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Deployment not found');
            }
            $revision = ConfigRevision::where('resource_type', 'model_deployment')->where('resource_id', $id)->where('revision', (int) $deployment->revision)->find();
            if ($revision === null || !in_array((string) $revision->state, [CapabilityProfilePolicy::DRAFT, 'tested'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Deployment is not publishable');
            }
            ModelDeployment::where('model_id', (int) $deployment->model_id)->where('status', 1)->update(['status' => 2]);
            $deployment->save(['status' => 1]);
            $revision->save(['state' => CapabilityProfilePolicy::PUBLISHED, 'published_at' => date('Y-m-d H:i:s')]);
            $this->audit('deployment.publish', $id, 'Published SandAI model deployment');
            return ['id' => $id, 'revision' => (int) $deployment->revision];
        });
    }

    private function audit(string $action, int $deploymentId, string $summary): void
    {
        AuditLog::create(['actor_type' => 'admin', 'actor_ref' => 'saiadmin', 'action' => $action, 'resource_type' => 'model_deployment', 'resource_id' => $deploymentId, 'summary' => $summary, 'context' => []]);
    }
}
