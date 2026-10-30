<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class LegalAnalysisProfile extends SandAiModel
{
    protected $table = 'sand_ai_legal_analysis_profile';

    protected $json = ['config'];
}
