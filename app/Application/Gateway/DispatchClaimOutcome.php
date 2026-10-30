<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;

/** A committed coordinator result; Gateway throws its problem only afterwards. */
final readonly class DispatchClaimOutcome
{
    private function __construct(public bool $claimed, public ?ApiProblem $problem = null, public ?Provider $provider = null, public ?ModelDeployment $deployment = null)
    {
    }

    public static function claimed(?Provider $provider = null, ?ModelDeployment $deployment = null): self
    {
        return new self(true, null, $provider, $deployment);
    }

    public static function rejected(ApiProblem $problem): self
    {
        return new self(false, $problem);
    }
}
