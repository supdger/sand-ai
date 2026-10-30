<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\ProfessionalRerankOutcome;

/** Carries bounded professional usage even when the returned ordering is invalid. */
final class RerankVerificationFailure extends \RuntimeException
{
    public function __construct(
        public readonly ApiProblem $problem,
        public readonly ?ProfessionalRerankOutcome $evidence,
    ) {
        parent::__construct('Professional rerank deployment verification did not succeed');
    }
}
