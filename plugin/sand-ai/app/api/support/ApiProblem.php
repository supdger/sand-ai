<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\support;

use RuntimeException;

/**
 * Stable runtime failure that a plugin-local controller can map without
 * importing the platform main application's API support classes.
 */
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
