<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

final class OnboardingStatus
{
    /** @return array<string, mixed> */
    /**
     * @param list<array{tested: bool, published: bool}> $deploymentChains
     * @return array<string, mixed>
     */
    public static function fromDeploymentChains(int $providers, int $models, array $deploymentChains, int $environmentId, bool $defaultChatBound): array
    {
        $hasProvider = $providers > 0;
        $hasModel = $models > 0;
        $hasDeployment = $deploymentChains !== [];
        $hasTested = false;
        $hasPublished = false;
        foreach ($deploymentChains as $chain) {
            $hasTested = $hasTested || $chain['tested'];
            $hasPublished = $hasPublished || $chain['published'];
        }
        if (!$hasProvider) {
            $stage = ['code' => 'connect_provider', 'name' => '连接 AI 服务账号', 'next_step' => '先连接服务商并填写自己的 API Key。'];
        } elseif (!$hasModel) {
            $stage = ['code' => 'discover_model', 'name' => '发现可用模型', 'next_step' => '使用已连接服务商发现账号可用的模型。'];
        } elseif (!$hasDeployment) {
            $stage = ['code' => 'verify_model', 'name' => '测试模型', 'next_step' => '选择模型并运行一次极小样本测试。'];
        } elseif (!$hasTested) {
            $stage = ['code' => 'verify_model', 'name' => '测试模型', 'next_step' => '当前部署尚未通过测试，不能用于发布。'];
        } elseif (!$hasPublished) {
            $stage = ['code' => 'publish_configuration', 'name' => '发布配置', 'next_step' => '测试通过后，由管理员决定是否发布给业务环境使用。'];
        } elseif (!$defaultChatBound) {
            $stage = ['code' => 'bind_environment', 'name' => '绑定业务环境', 'next_step' => '选择当前业务环境，将已发布模型绑定为默认聊天能力。'];
        } else {
            $stage = ['code' => 'configuration_published', 'name' => '配置已发布', 'next_step' => '可以继续为新的业务服务复用已发布能力。'];
        }
        $stage['current_step'] = $stage['name'];

        return [
            'provider' => ['configured' => $hasProvider],
            'model' => ['configured' => $hasModel],
            'deployment' => ['configured' => $hasDeployment],
            'tested_configuration' => ['configured' => $hasTested],
            'published_deployment' => ['configured' => $hasPublished],
            'environment_binding' => ['environment_id' => $environmentId, 'configured' => $defaultChatBound],
            'published_configuration' => ['configured' => $hasPublished && $defaultChatBound],
            'stage' => $stage,
        ];
    }
}
