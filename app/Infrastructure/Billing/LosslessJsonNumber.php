<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

final class LosslessJsonNumber
{
    public function __construct(public readonly string $value)
    {
    }
}
