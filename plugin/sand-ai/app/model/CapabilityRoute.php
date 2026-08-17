<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class CapabilityRoute extends SandAiPluginModel
{
    protected $table = 'sand_ai_capability_route';
    protected $json = ['config'];
}
