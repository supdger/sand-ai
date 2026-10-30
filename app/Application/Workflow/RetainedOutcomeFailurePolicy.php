<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;

final class RetainedOutcomeFailurePolicy
{
    public function map(bool $outcomeRetained, \Throwable $failure): \Throwable
    {
        if (!$outcomeRetained || ($failure instanceof ApiProblem && $failure->errorCode === 'SAND_AI_TASK_LEASE_LOST')) {
            return $failure;
        }
        return new ApiProblem('SAND_AI_LOCAL_RECOVERY_PENDING', 'Workflow outcome is retained for receipt-only recovery', false);
    }
}
