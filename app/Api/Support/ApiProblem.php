<?php

declare(strict_types=1);

namespace app\Api\Support;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
