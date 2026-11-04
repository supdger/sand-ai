<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

/**
 * On-demand first-use entry for business paths that need internal security
 * material. A freshly installed or upgraded instance may hold material in the
 * shared private file that this process has not loaded yet, or may have
 * skipped every earlier initialization opportunity. The first write that
 * reaches a key boundary resolves the material here instead of failing until
 * an administrator reloads the service: the shared file is reloaded first,
 * and only a still-empty instance runs the same idempotent protect/initialize
 * sequence the administrator bootstrap endpoint drives. Existing material is
 * never rotated.
 */
final class InternalSecurityMaterialGuard
{
    /** @var \Closure():array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} */
    private readonly \Closure $status;
    /** @var \Closure():array{ready:bool,changed:bool,reason:?string,message:?string,retryable:bool} */
    private readonly \Closure $ensurer;

    /**
     * @param null|\Closure():array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} $status
     * @param null|\Closure():array{ready:bool,changed:bool,reason:?string,message:?string,retryable:bool} $ensurer
     */
    public function __construct(?\Closure $status = null, ?\Closure $ensurer = null)
    {
        $this->status = $status ?? static function (): array {
            return InternalSecurityInitialization::status();
        };
        $this->ensurer = $ensurer ?? static function (): array {
            return (new InternalSecurityBootstrapService())->ensure();
        };
    }

    /**
     * Reports whether internal security material is usable by this process
     * right now, resolving it on demand when it is missing. A retryable
     * first-use failure (for example another worker holding the file lock)
     * gets one bounded second attempt so a parallel initialization can land.
     */
    public function ready(): bool
    {
        if (($this->status)()['ready'] === true) {
            return true;
        }
        $result = ($this->ensurer)();
        if (($result['ready'] ?? false) !== true && ($result['retryable'] ?? false) === true) {
            usleep(150_000);
            $result = ($this->ensurer)();
        }
        return ($result['ready'] ?? false) === true;
    }
}
