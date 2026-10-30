<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class BillingLine extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_billing_line';
}
