<?php

declare(strict_types=1);

namespace app\Application\Workflow;

enum MaterialReviewReason: string
{
    case CONFLICT = 'conflict';
    case AMBIGUOUS = 'ambiguous';
}
