<?php

declare(strict_types=1);

namespace app\Identity\Contract;

/** Verified workload context issued by the co-installed SandIAM host. */
final readonly class IdentityContext
{
    /** @param list<string> $serviceActions */
    public function __construct(
        public string $organizationId,
        public string $applicationId,
        public int $environmentId,
        public string $workloadClientId,
        public string $audience,
        public array $serviceActions,
        public ?int $expiresAt = null,
    ) {
    }

    public function requireAction(string $serviceAction): self
    {
        if (!in_array($serviceAction, $this->serviceActions, true)) {
            throw new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN');
        }

        return $this;
    }
}
