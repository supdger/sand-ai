<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class MaterialAnalysisProfile extends SandAiModel
{
    protected $table = 'sand_ai_material_analysis_profile';

    protected $json = ['config'];
}
