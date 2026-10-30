<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class BudgetEvent extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_budget_event';

    protected $json = ['snapshot'];
}
