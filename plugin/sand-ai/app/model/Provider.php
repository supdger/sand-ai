<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class Provider extends SandAiPluginModel
{
    protected $table = 'sand_ai_provider';
    protected $json = ['encrypted_config'];
    protected $hidden = ['encrypted_config', 'delete_time'];
}
