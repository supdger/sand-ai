<?php

declare(strict_types=1);

namespace app\Identity\Contract;

use RuntimeException;

final class IdentityContextException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
