<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class Provider extends SandAiModel
{
    protected $table = 'sand_ai_provider';
    protected $json = ['encrypted_config'];
    protected $hidden = ['encrypted_config', 'delete_time'];
}
