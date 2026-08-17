<?php

declare(strict_types=1);

namespace plugin\SandAi\app\model;

final class SourceBlock extends SandAiPluginModel
{
    protected $table = 'sand_ai_source_block';
    protected $json = ['locator'];
}
