<?php

declare(strict_types=1);

namespace app\Application\Workflow;

/** Nonempty candidate states; missing document fields have a separate shape. */
enum MaterialAnalysisEvidenceState: string
{
    case Confirmed = 'confirmed';
    case Uncertain = 'uncertain';
}
