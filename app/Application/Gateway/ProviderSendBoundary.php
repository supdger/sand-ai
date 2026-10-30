<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;

/** Records the precise point at which a Provider request begins transmission. */
final class ProviderSendBoundary
{
    private bool $started = false;
    private ?ApiProblem $identityRejection = null;
    /** @var array<string,array<string,bool>> */
    private array $identities = [];
    private ?ApiProblem $authorizationRejection = null;

    /**
     * @param null|\Closure():void $authorizeBeforeSend
     * @param null|\Closure(ProviderResponseIdentity):void $observeIdentity
     */
    public function __construct(private readonly ?\Closure $authorizeBeforeSend = null, private readonly ?\Closure $observeIdentity = null)
    {
    }

    public function markStarted(): void
    {
        if ($this->authorizationRejection !== null) {
            throw $this->authorizationRejection;
        }
        if (!$this->started && $this->authorizeBeforeSend !== null) {
            try {
                // Claim and SDK preparation may have waited on locks. Reuse the
                // original current IAM/resource gate at the actual I/O boundary.
                ($this->authorizeBeforeSend)();
            } catch (ApiProblem $problem) {
                $this->authorizationRejection = $problem;
                throw $problem;
            }
        }
        $this->started = true;
    }

    public function observeResponseIdentity(ProviderResponseIdentity $identity): void
    {
        if (!$this->started) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Provider identifier has no live send boundary');
        }
        // Setup probes without an invocation ledger do not claim saved evidence.
        if ($this->observeIdentity === null) return;
        if (isset($this->identities[$identity->source][$identity->value])
            || count($this->identities[$identity->source] ?? []) >= 2) return;
        try {
            ($this->observeIdentity)($identity);
        } catch (ApiProblem $problem) {
            $this->identityRejection = $problem;
            throw $problem;
        }
        $this->identities[$identity->source][$identity->value] = true;
    }

    public function identityRejection(): ?ApiProblem
    {
        return $this->identityRejection;
    }

    public function rejectedAuthorization(ApiProblem $problem): bool
    {
        return !$this->started && $this->authorizationRejection === $problem;
    }

    public function authorizationRejection(): ?ApiProblem
    {
        return $this->started ? null : $this->authorizationRejection;
    }

    public function started(): bool
    {
        return $this->started;
    }
}
