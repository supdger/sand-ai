<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class AiModel extends SandAiModel
{
    protected $table = 'sand_ai_model';
    protected $json = ['capabilities'];
}
