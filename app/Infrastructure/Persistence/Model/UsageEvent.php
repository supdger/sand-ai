<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model;

final class UsageEvent extends ImmutableSandAiModel
{
    protected $table = 'sand_ai_usage_event';

    protected $json = [
        'identity_snapshot',
        'provider_snapshot',
        'model_snapshot',
        'deployment_snapshot',
        'price_snapshot',
    ];

    protected $jsonAssoc = true;
}
