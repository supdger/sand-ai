<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\parsing;

use RuntimeException;
use Throwable;

final class ParseException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
