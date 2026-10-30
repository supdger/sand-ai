<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class SourceBlock extends SandAiModel
{
    protected $table = 'sand_ai_source_block';
    protected $json = ['locator'];
}
