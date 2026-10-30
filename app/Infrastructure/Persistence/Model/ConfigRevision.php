<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class ConfigRevision extends SandAiModel
{
    protected $table = 'sand_ai_config_revision';
    protected $json = ['payload'];
}
