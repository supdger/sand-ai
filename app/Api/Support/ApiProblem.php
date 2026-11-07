<?php

declare(strict_types=1);

namespace app\Api\Support;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    /**
     * @param array<string, mixed> $details sanitized machine-readable fields
     *        for the admin frontend (reason code and display-safe values only;
     *        never paths, stack traces or secrets).
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
