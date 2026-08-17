<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class ConfigRevision extends SandAiPluginModel
{
    protected $table = 'sand_ai_config_revision';
    protected $json = ['payload'];
}
