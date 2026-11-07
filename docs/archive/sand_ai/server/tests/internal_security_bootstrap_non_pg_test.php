<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Plugin classes are resolved by the host at runtime; this standalone test
// registers the same directory convention for the two plugin namespaces.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'plugin\\')) {
        return;
    }
    $rest = substr($class, strlen('plugin\\'));
    $pos = strpos($rest, '\\');
    if ($pos === false) {
        return;
    }
    $top = substr($rest, 0, $pos);
    $dir = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $top));
    $file = dirname(__DIR__) . '/plugin/' . $dir . '/' . str_replace('\\', '/', substr($rest, $pos + 1)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentDraftSaveRequestPolicy;
use app\Application\Onboarding\InternalSecurityBootstrapService;
use app\Application\Onboarding\InternalSecurityInitialization;
use Dotenv\Dotenv;

/** Concrete probe: the abstract admin base cannot be instantiated directly. */
final class SandAiAdminControllerProbe extends \plugin\SandAi\app\admin\controller\SandAiAdminController
{
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Internal security bootstrap check failed: ' . $label);
    }
    ++$checks;
};

$envNames = [
    'SAND_AI_CONFIG_KEY',
    'SAND_AI_IDEMPOTENCY_KEY',
    'SAND_AI_IDEMPOTENCY_KEY_ID',
    'SAND_AI_IDEMPOTENCY_KEYRING',
    'SAND_AI_IAM_CONTEXT_CURRENT_KEY_ID',
    'SAND_AI_IAM_CONTEXT_CURRENT_KEY',
    'SAND_AI_IAM_CONTEXT_PREVIOUS_KEYS_JSON',
    'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID',
    'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY',
    'SAND_AI_OUTCOME_RECEIPT_PREVIOUS_KEYS_JSON',
];
$clearEnv = static function () use ($envNames): void {
    foreach ($envNames as $name) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
};

$missingStatus = ['ready' => false, 'missing_groups' => ['idempotency'], 'invalid_groups' => [], 'recovery_action' => 'initialize_or_restore'];
$readyStatus = ['ready' => true, 'missing_groups' => [], 'invalid_groups' => [], 'recovery_action' => 'none'];
$realStatus = static fn (): array => InternalSecurityInitialization::status();
$noop = static function (): void {
};

$makeTempDir = static function (): string {
    $dir = sys_get_temp_dir() . '/sand-ai-security-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    return $dir;
};

// Random material shaped exactly like the first-use initializer output; it
// only ever lives in the temporary file or process env of this test.
$freshMaterial = static function (): string {
    return implode("\n", [
        'SAND_AI_CONFIG_KEY=' . bin2hex(random_bytes(32)),
        'SAND_AI_IDEMPOTENCY_KEY=' . bin2hex(random_bytes(32)),
        'SAND_AI_IAM_CONTEXT_CURRENT_KEY_ID=initial_' . bin2hex(random_bytes(8)),
        'SAND_AI_IAM_CONTEXT_CURRENT_KEY=' . base64_encode(random_bytes(32)),
        'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID=initial_' . bin2hex(random_bytes(8)),
        'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY=' . base64_encode(random_bytes(32)),
    ]) . "\n";
};

$clearEnv();

// (0) Without key material the deployment save fingerprint fails closed. The
//     guard is a stub: the default guard would resolve material from the host
//     .env, making this check depend on whether the checkout was initialized.
$policy = new DeploymentDraftSaveRequestPolicy(static fn (): bool => false);
try {
    $policy->fingerprint(['operation' => 'probe'], 'k1', '1');
    $assert(false, 'fingerprint without material must throw');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'missing material error code');
}
$assert(InternalSecurityInitialization::status()['ready'] === false, 'status reports not ready before material');

// (1) Material already written to the shared file (by the CLI or another
//     worker) but absent from this process: ensure() reloads first and never
//     calls protect/initialize.
$dir1 = $makeTempDir();
file_put_contents($dir1 . '/.env', $freshMaterial());
$protectCalls = 0;
$initCalls = 0;
$service = new InternalSecurityBootstrapService(
    $realStatus,
    static function () use (&$protectCalls): bool {
        ++$protectCalls;
        return true;
    },
    static function () use (&$initCalls): array {
        ++$initCalls;
        return ['ready' => true, 'changed' => true, 'recovery_action' => 'reload_host'];
    },
    static function () use ($dir1): void {
        Dotenv::createUnsafeMutable($dir1)->load();
    },
);
$result = $service->ensure();
$assert($result['ready'] === true && $result['changed'] === false, 'file material reloads without generation');
$assert($initCalls === 0 && $protectCalls === 0, 'no protect or initialize when reload suffices');
$assert(strlen((string) env('SAND_AI_IDEMPOTENCY_KEY')) >= 32, 'reloaded material visible via env()');
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'k1', '1');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $fingerprint['idempotency_hmac']) === 1, 'fingerprint works after reload ensure');

// (2) Nothing declared anywhere: ensure() protects storage, runs first-use
//     initialization, then reloads so this process can serve saves at once.
$clearEnv();
$dir2 = $makeTempDir();
file_put_contents($dir2 . '/.env', "APP_DEBUG=false\n");
$protectCalls = 0;
$initCalls = 0;
$service = new InternalSecurityBootstrapService(
    $realStatus,
    static function () use (&$protectCalls): bool {
        ++$protectCalls;
        return true;
    },
    static function () use ($dir2, &$initCalls, $freshMaterial): array {
        ++$initCalls;
        file_put_contents($dir2 . '/.env', $freshMaterial());
        return ['ready' => true, 'changed' => true, 'recovery_action' => 'reload_host'];
    },
    static function () use ($dir2): void {
        Dotenv::createUnsafeMutable($dir2)->load();
    },
);
$result = $service->ensure();
$assert($result['ready'] === true && $result['changed'] === true, 'first-use generation reports changed');
$assert($initCalls === 1 && $protectCalls === 1, 'protect then initialize each ran once');
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'k2', '1');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $fingerprint['request_fingerprint_hmac']) === 1, 'save fingerprint works after ensure');

// (3) A second ensure() is idempotent: no protect/initialize, keyring unchanged.
$materialBefore = (string) env('SAND_AI_IDEMPOTENCY_KEY') . '|' . (string) env('SAND_AI_CONFIG_KEY');
$again = $service->ensure();
$assert($again['ready'] === true && $again['changed'] === false, 'repeat ensure reports no change');
$assert($initCalls === 1 && $protectCalls === 1, 'repeat ensure does not regenerate');
$assert((string) env('SAND_AI_IDEMPOTENCY_KEY') . '|' . (string) env('SAND_AI_CONFIG_KEY') === $materialBefore, 'key material unchanged');

// (4) The keyring fallback resolves a non-active key id without touching the
//     active material.
$clearEnv();
$_SERVER['SAND_AI_IDEMPOTENCY_KEY_ID'] = 'alt';
$_SERVER['SAND_AI_IDEMPOTENCY_KEYRING'] = json_encode(['alt' => str_repeat('k', 40)], JSON_THROW_ON_ERROR);
$ring = $policy->fingerprint(['operation' => 'probe'], 'k3', '1');
$assert($ring['key_id'] === 'alt', 'keyring resolves stored key id');

// (5) Failure mapping: only transient causes are retryable; the message is
//     one Chinese sentence and never leaks internals.
$clearEnv();
$busy = new InternalSecurityBootstrapService(
    static fn (): array => $missingStatus,
    static fn (): bool => true,
    static function (): array {
        throw new ApiProblem('SAND_AI_SECURITY_BUSY', 'busy');
    },
    $noop,
);
$failed = $busy->ensure();
$assert($failed['ready'] === false && $failed['reason'] === 'security_init_failed', 'busy maps to security_init_failed');
$assert($failed['retryable'] === true, 'busy is retryable');
$assert($failed['message'] === '系统安全初始化未完成，请重试或联系管理员', 'chinese fallback message');

$restore = new InternalSecurityBootstrapService(
    static fn (): array => $missingStatus,
    static fn (): bool => true,
    static function (): array {
        throw new ApiProblem('SAND_AI_SECURITY_HISTORY_RESTORE_REQUIRED', 'restore');
    },
    $noop,
);
$failed = $restore->ensure();
$assert($failed['ready'] === false && $failed['retryable'] === false, 'history restore is not retryable');

$initCalls = 0;
$storage = new InternalSecurityBootstrapService(
    static fn (): array => $missingStatus,
    static function () use (&$initCalls): bool {
        ++$initCalls;
        throw new ApiProblem('SAND_AI_SECURITY_PRIVATE_STORAGE_REQUIRED', 'private storage');
    },
    static function (): array {
        throw new RuntimeException('initializer must not run after protect failure');
    },
    $noop,
);
$failed = $storage->ensure();
$assert($failed['ready'] === false && $failed['retryable'] === false, 'private storage failure is not retryable');
$assert($initCalls === 1, 'protect ran before initialize refused');

$unexpected = new InternalSecurityBootstrapService(
    static fn (): array => $missingStatus,
    static fn (): bool => true,
    static function (): array {
        throw new RuntimeException('disk exploded');
    },
    $noop,
);
$failed = $unexpected->ensure();
$assert($failed['ready'] === false && $failed['retryable'] === true, 'unexpected failure is retryable');
$assert(!str_contains((string) $failed['message'], 'exploded'), 'no internal message leak');

// (6) Initialization that reports success but leaves status not-ready fails closed.
$half = new InternalSecurityBootstrapService(
    static fn (): array => $missingStatus,
    static fn (): bool => true,
    static fn (): array => ['ready' => true, 'changed' => true, 'recovery_action' => 'reload_host'],
    $noop,
);
$failed = $half->ensure();
$assert($failed['ready'] === false && $failed['reason'] === 'security_init_failed', 'unverified initialization fails closed');

// (7) Administrator-facing message: the key-unavailable problem surfaces as
//     one Chinese sentence, other problems keep the contract text.
$wrap = static function (ApiProblem $problem): string {
    // The sandadmin base controller touches request() in its constructor; the
    // message mapping under test does not, so instantiate without it.
    $controller = (new ReflectionClass(SandAiAdminControllerProbe::class))
        ->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($controller, 'adminProblemException');
    return $method->invoke($controller, $problem)->getMessage();
};
$assert($wrap(new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Idempotency key material is unavailable')) === '系统安全初始化未完成，请重试或联系管理员', 'admin sees chinese sentence');
$assert($wrap(new ApiProblem('SAND_AI_VALIDATION_ERROR', 'input wrong')) === 'SAND_AI_VALIDATION_ERROR: input wrong', 'other problems keep contract text');

// (8) The lazy-path .env refresh updates only the security material names it
//     owns: unrelated file keys are never loaded and a process-declared value
//     of an unmanaged key keeps its operator-set content.
$dir3 = $makeTempDir();
$idempotencyKey3 = str_repeat('d', 64);
$ring3 = json_encode(['rotated1' => str_repeat('e', 64)], JSON_THROW_ON_ERROR);
file_put_contents($dir3 . '/.env', implode("\n", [
    'SAND_AI_CONFIG_KEY=' . str_repeat('c', 64),
    'SAND_AI_IDEMPOTENCY_KEY=' . $idempotencyKey3,
    'SAND_AI_IDEMPOTENCY_KEY_ID=rotated1',
    'SAND_AI_IDEMPOTENCY_KEYRING=\'' . $ring3 . '\'',
    'SAND_AI_IAM_CONTEXT_CURRENT_KEY_ID=initial_abcd1234abcd1234',
    'SAND_AI_IAM_CONTEXT_CURRENT_KEY=' . base64_encode(random_bytes(32)),
    'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID=initial_ef567890ef567890',
    'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY=' . base64_encode(random_bytes(32)),
    'DB_HOST=file-host',
    'UNRELATED_OPERATOR_KEY=file-value',
]) . "\n");
$clearEnv();
$_SERVER['DB_HOST'] = 'process-host';
$_ENV['DB_HOST'] = 'process-host';
putenv('DB_HOST=process-host');
$_SERVER['SAND_AI_IDEMPOTENCY_KEY'] = str_repeat('s', 64);
$refresh = new ReflectionMethod(InternalSecurityBootstrapService::class, 'refreshProcessEnvironment');
$refresh->invoke(null, $dir3 . '/.env');
$assert((string) env('DB_HOST') === 'process-host', 'refresh keeps the process value of unmanaged keys');
$assert(env('UNRELATED_OPERATOR_KEY') === null, 'refresh never loads non-material keys');
$assert((string) env('SAND_AI_IDEMPOTENCY_KEY') === $idempotencyKey3, 'refresh updates stale material from file');
$assert((string) env('SAND_AI_IDEMPOTENCY_KEY_ID') === 'rotated1', 'refresh exposes the file-declared key id');
$assert((string) env('SAND_AI_IDEMPOTENCY_KEYRING') === $ring3, 'refresh exposes the keyring');
$assert((string) getenv('SAND_AI_IDEMPOTENCY_KEY') === $idempotencyKey3, 'refresh reaches the putenv channel');
$clearEnv();
unset($_SERVER['DB_HOST'], $_ENV['DB_HOST']);
putenv('DB_HOST');

echo "Internal security bootstrap test passed ({$checks} checks)\n";
