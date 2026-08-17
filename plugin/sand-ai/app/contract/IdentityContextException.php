<?php

declare(strict_types=1);

namespace plugin\SandAi\app\contract;

use RuntimeException;

final class IdentityContextException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
