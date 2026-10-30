<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/**
 * Trusted internal opt-in for retaining a provider completion for replay.
 * This object is deliberately not constructed from a public request DTO.
 */
final readonly class OutcomeReceiptRequest
{
    public function __construct(public int $maxPlaintextBytes)
    {
        if ($maxPlaintextBytes < 1) {
            throw new ApiProblem('SAND_AI_RECEIPT_INPUT_TOO_LARGE', 'The receipt output bound must be a positive integer');
        }
    }
}
