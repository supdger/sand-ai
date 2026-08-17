<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class AiModel extends SandAiPluginModel
{
    protected $table = 'sand_ai_model';
    protected $json = ['capabilities'];
}
