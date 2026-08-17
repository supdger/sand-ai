<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class ModelDeployment extends SandAiPluginModel
{
    protected $table = 'sand_ai_model_deployment';
    protected $json = ['config'];
}
