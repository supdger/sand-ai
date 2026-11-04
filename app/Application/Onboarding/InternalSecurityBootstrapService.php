<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use Dotenv\Dotenv;

/**
 * Idempotent first-use initialization of SandAI internal security material,
 * driven by the explicit administrator setup/bootstrap action.
 *
 * Generation reuses the `sand-ai:security` operator sequence: installer
 * `.env` permissions are tightened by `protect-config` first, then
 * `initialize` performs the first-use write with the same private storage,
 * locking, permission and business-history checks. Existing material is
 * never rotated and an instance that already holds business history
 * without its original material fails closed instead of receiving
 * replacement keys.
 */
final class InternalSecurityBootstrapService
{
    public const REASON = 'security_init_failed';
    private const MESSAGE = '系统安全初始化未完成，请重试或联系管理员';

    /** @var \Closure():array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} */
    private readonly \Closure $status;
    /** @var \Closure():bool */
    private readonly \Closure $protector;
    /** @var \Closure():array{ready:bool,changed:bool,recovery_action:string} */
    private readonly \Closure $initializer;
    /** @var \Closure():void */
    private readonly \Closure $environmentRefresh;

    /**
     * @param null|\Closure():array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} $status
     * @param null|\Closure():bool $protector
     * @param null|\Closure():array{ready:bool,changed:bool,recovery_action:string} $initializer
     * @param null|\Closure():void $environmentRefresh
     */
    public function __construct(
        ?\Closure $status = null,
        ?\Closure $protector = null,
        ?\Closure $initializer = null,
        ?\Closure $environmentRefresh = null,
    ) {
        $this->status = $status ?? static function (): array {
            return InternalSecurityInitialization::status();
        };
        $this->protector = $protector ?? static function (): bool {
            return (new InternalSecurityInitialization())->protectConfiguration();
        };
        $this->initializer = $initializer ?? static function (): array {
            return (new InternalSecurityInitialization())->initialize();
        };
        $this->environmentRefresh = $environmentRefresh ?? static function (): void {
            self::refreshProcessEnvironment();
        };
    }

    /** @return array{ready:bool,changed:bool,reason:?string,message:?string,retryable:bool} */
    public function ensure(): array
    {
        try {
            if (($this->status)()['ready'] === true) {
                return self::readyResult(false);
            }
            // Another worker or the operator CLI may already have written the
            // material to the shared file; refresh this process before
            // deciding first-use generation is still needed.
            ($this->environmentRefresh)();
            if (($this->status)()['ready'] === true) {
                return self::readyResult(false);
            }
            // Installer-created .env files are commonly 0644; tighten them to
            // the private 0600 the first-use write requires (no-op when the
            // file is already private), matching the operator sequence.
            ($this->protector)();
            $result = ($this->initializer)();
            if (($result['ready'] ?? false) === true) {
                ($this->environmentRefresh)();
            }
            if (($this->status)()['ready'] === true) {
                return self::readyResult(($result['changed'] ?? false) === true);
            }
            return self::failureResult(false);
        } catch (ApiProblem $problem) {
            return self::failureResult(self::isRetryable($problem));
        } catch (\Throwable) {
            return self::failureResult(true);
        }
    }

    /**
     * Non-overwriting .env reload identical to the host boot sequence; newly
     * written material becomes visible to this process without replacing any
     * operator-declared value already in the environment.
     */
    private static function refreshProcessEnvironment(): void
    {
        try {
            $file = run_path('.env');
            if (!is_file($file)) {
                return;
            }
            if (method_exists(Dotenv::class, 'createUnsafeMutable')) {
                Dotenv::createUnsafeMutable(dirname($file))->load();
            } else {
                Dotenv::createMutable(dirname($file))->load();
            }
        } catch (\Throwable) {
            // An unreadable or malformed file leaves status() as the source of truth.
        }
    }

    private static function isRetryable(ApiProblem $problem): bool
    {
        // BUSY ends when the other initialization finishes; unexpected failures
        // may be transient. Restore/permission problems need an operator first.
        return in_array($problem->errorCode, [
            'SAND_AI_SECURITY_BUSY',
            'SAND_AI_SECURITY_INITIALIZATION_FAILED',
        ], true);
    }

    /** @return array{ready:bool,changed:bool,reason:?string,message:?string,retryable:bool} */
    private static function readyResult(bool $changed): array
    {
        return [
            'ready' => true,
            'changed' => $changed,
            'reason' => null,
            'message' => null,
            'retryable' => false,
        ];
    }

    /** @return array{ready:bool,changed:bool,reason:?string,message:?string,retryable:bool} */
    private static function failureResult(bool $retryable): array
    {
        return [
            'ready' => false,
            'changed' => false,
            'reason' => self::REASON,
            'message' => self::MESSAGE,
            'retryable' => $retryable,
        ];
    }
}
