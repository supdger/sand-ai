<?php

declare(strict_types=1);

namespace plugin\SandAi\app\contract;

/** Verified caller context issued by the host's SandIAM installation. */
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

    public function allows(string $serviceAction): bool
    {
        return in_array($serviceAction, $this->serviceActions, true);
    }

    /** @throws IdentityContextException when a verified context lacks the requested grant. */
    public function requireAction(string $serviceAction): self
    {
        if (!$this->allows($serviceAction)) {
            throw new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN');
        }

        return $this;
    }
}
