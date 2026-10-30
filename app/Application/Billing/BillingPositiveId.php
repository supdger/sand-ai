<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Api\Support\ApiProblem;

/** Strict native-int boundary for public billing identifiers. */
final class BillingPositiveId
{
    public static function parse(mixed $value, string $field, string $errorCode): int
    {
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }

            throw new ApiProblem($errorCode, "{$field} must be a positive integer");
        }

        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new ApiProblem($errorCode, "{$field} must be a positive integer");
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new ApiProblem($errorCode, "{$field} must fit a signed 64-bit positive integer");
        }

        return (int) $value;
    }
}
