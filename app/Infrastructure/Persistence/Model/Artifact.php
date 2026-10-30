<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class Artifact extends SandAiModel
{
    protected $table = 'sand_ai_artifact';

    protected $json = ['payload'];
}
