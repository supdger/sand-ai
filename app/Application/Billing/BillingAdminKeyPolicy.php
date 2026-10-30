<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

/** Validates the dedicated administrator key at both write and read boundaries. */
final class BillingAdminKeyPolicy
{
    public function normalize(mixed $value, string $errorCode): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ApiProblem($errorCode, 'billing administrator credential invalid');
        }

        $key = trim($value);
        if (strlen($key) < 8 || strlen($key) > 512) {
            throw new ApiProblem($errorCode, 'billing administrator credential invalid');
        }

        return $key;
    }
}
