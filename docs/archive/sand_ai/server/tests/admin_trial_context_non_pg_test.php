<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The shared vendor directory resolves app\ against the source checkout it was
// installed in; this standalone test must bind app\ to its own worktree so new
// classes under test are found regardless of which checkout runs the file.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\Trial\AdminTrialActions;
use app\Application\Trial\AdminTrialContextService;
use app\Application\Trial\AdminTrialEntityStore;
use app\Application\Trial\AdminTrialRateLimiter;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Admin trial context check failed: ' . $label);
    }
    ++$checks;
};

$assertProblem = static function (callable $operation, string $expectedCode, string $label) use (&$checks): void {
    try {
        $operation();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== $expectedCode) {
            throw new RuntimeException('Admin trial context check failed: ' . $label . ' (got ' . $problem->errorCode . ')');
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('Admin trial context check failed: ' . $label . ' (no ApiProblem)');
};

/* ---------- 白名单裁剪与缺省默认集 ---------- */

$assert(
    AdminTrialActions::resolve(null) === AdminTrialActions::DEFAULT_ACTIONS,
    'absent actions fall back to the minimal default set',
);
$assert(
    AdminTrialActions::resolve(['sand_ai.chat.complete', 'sand_ai.skill.manage', 'sand_ai.file.read', 'sand_ai.catalog.manage'])
        === ['sand_ai.chat.complete', 'sand_ai.file.read'],
    'non-whitelist actions are dropped in whitelist order',
);
$assert(
    AdminTrialActions::resolve(['sand_ai.file.read', 'sand_ai.file.read', 'sand_ai.model.read'])
        === ['sand_ai.model.read', 'sand_ai.file.read'],
    'duplicates collapse once, ordered by the whitelist',
);
$assertProblem(
    static fn (): array => AdminTrialActions::resolve(['sand_ai.skill.manage', 'sand_ai.catalog.manage']),
    'SAND_AI_TRIAL_ACTION_FORBIDDEN',
    'a request naming only administrative actions is refused',
);
$assertProblem(
    static fn (): array => AdminTrialActions::resolve([]),
    'SAND_AI_TRIAL_ACTION_FORBIDDEN',
    'an explicit empty list resolves to nothing and is refused',
);
$assertProblem(
    static fn (): array => AdminTrialActions::resolve('sand_ai.chat.complete'),
    'SAND_AI_VALIDATION_ERROR',
    'a scalar actions value is a validation error',
);
$assertProblem(
    static fn (): array => AdminTrialActions::resolve(['sand_ai.chat.complete', 42]),
    'SAND_AI_VALIDATION_ERROR',
    'non-string action entries are a validation error',
);
foreach (['sand_ai.skill.manage', 'sand_ai.skill.publish', 'sand_ai.agent.submit', 'sand_ai.tool.run',
    'sand_ai.workflow.run', 'sand_ai.retrieval.search', 'sand_ai.knowledge.query', 'sand_ai.audio.speech'] as $forbidden) {
    $assert(!in_array($forbidden, AdminTrialActions::all(), true), 'management action excluded: ' . $forbidden);
}

/* ---------- 懒创建幂等（内存替身） ---------- */

final class FakeTrialEntityStore implements AdminTrialEntityStore
{
    /** @var array<string,int> */
    public array $ids = [];
    /** @var array<string,int> */
    public array $grants = [];
    public int $clientCreates = 0;
    public int $grantCreates = 0;
    public int $credentialCalls = 0;
    public bool $clientDisabled = false;
    public bool $grantInactive = false;

    public function ensureTrialClient(): array
    {
        if ($this->clientDisabled) {
            throw new ApiProblem('SAND_AI_TRIAL_CLIENT_DISABLED', 'disabled');
        }
        foreach (['organization', 'application', 'environment', 'workload_client'] as $kind) {
            if (!isset($this->ids[$kind])) {
                ++$this->clientCreates;
                $this->ids[$kind] = 100 + $this->clientCreates;
            }
        }
        return [
            'organization_id' => $this->ids['organization'],
            'application_id' => $this->ids['application'],
            'environment_id' => $this->ids['environment'],
            'workload_client_id' => $this->ids['workload_client'],
        ];
    }

    public function ensureGrants(int $workloadClientId, array $actionCodes): array
    {
        if ($this->grantInactive) {
            throw new ApiProblem('SAND_AI_TRIAL_GRANT_INACTIVE', 'inactive');
        }
        $map = [];
        foreach ($actionCodes as $index => $code) {
            if (!isset($this->grants[$code])) {
                ++$this->grantCreates;
                $this->grants[$code] = 500 + $index;
            }
            $map[$code] = $this->grants[$code];
        }
        return $map;
    }

    public function issueCredential(int $workloadClientId, string $expireTime, string $requestId, string $actor, ?int $organizationId, ?int $applicationId): array
    {
        ++$this->credentialCalls;
        return ['id' => 900 + $this->credentialCalls, 'key_prefix' => 'siam_fakeprefix', 'credential' => 'siam_plaintext_secret'];
    }
}

$store = new FakeTrialEntityStore();
$first = $store->ensureTrialClient();
$second = $store->ensureTrialClient();
$assert($first === $second && $store->clientCreates === 4, 'lazy client chain is created once and replays identically');
$grants = $store->ensureGrants($first['workload_client_id'], AdminTrialActions::all());
$assert(count($grants) === count(AdminTrialActions::all()) && $store->grantCreates === count(AdminTrialActions::all()), 'one grant per whitelist action');
$again = $store->ensureGrants($first['workload_client_id'], AdminTrialActions::all());
$assert($again === $grants && $store->grantCreates === count(AdminTrialActions::all()), 'second ensureGrants creates nothing');

/* ---------- 签发编排与双侧审计 ---------- */

final class FakeContextIssuer
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];
    public ?Throwable $failure = null;

    /** @return array<string,mixed> */
    public function issue(string $workloadCredential, string $audience, array $requestedActions, ?array $subjectScope, string $requestId, string $sourceIp = '', string $serviceCode = ''): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->calls[] = [
            'credential' => $workloadCredential,
            'audience' => $audience,
            'actions' => $requestedActions,
            'subject_scope' => $subjectScope,
            'request_id' => $requestId,
            'source_ip' => $sourceIp,
            'service_code' => $serviceCode,
        ];
        return ['context' => 'signed.ctx', 'context_id' => 'ctx01', 'expire_time' => '2026-11-04 12:00:00'];
    }
}

final class FakeServiceCatalog
{
    /** @var list<string> */
    public array $calls = [];
    public bool $fail = false;

    /** @return array<string,mixed> */
    public function register(): array
    {
        $this->calls[] = 'register';
        if ($this->fail) {
            throw new ApiProblem('SAND_AI_SERVICE_CATALOG_DISABLED', 'disabled');
        }
        return ['registered' => true];
    }

    /** @return array<string,mixed> */
    public function registerAudio(): array
    {
        $this->calls[] = 'registerAudio';
        return ['registered' => true];
    }
}

AdminTrialRateLimiter::reset();
$store = new FakeTrialEntityStore();
$issuer = new FakeContextIssuer();
$catalog = new FakeServiceCatalog();
$audits = [];
$service = new AdminTrialContextService($store, $issuer, $catalog, static function (array $entry) use (&$audits): void {
    $audits[] = $entry;
});

$result = $service->issueContext(['sand_ai.chat.complete', 'sand_ai.skill.manage'], 7, '127.0.0.1', 'atrial_test_1');
$assert(
    $result === ['context' => 'signed.ctx', 'expire_time' => '2026-11-04 12:00:00', 'actions' => ['sand_ai.chat.complete']],
    'response carries context, expire_time and the clipped actions only',
);
$assert($catalog->calls === ['register', 'registerAudio'], 'catalog registration runs before issuance');
$assert(count($issuer->calls) === 1, 'exactly one context issue call');
$call = $issuer->calls[0];
$assert($call['audience'] === 'sand-ai' && $call['service_code'] === 'sand_ai', 'issue pins the sand_ai service and sand-ai audience');
$assert($call['subject_scope'] === null, 'subject_scope is never asserted from the admin session');
$assert($call['actions'] === ['sand_ai.chat.complete'], 'issuer receives only the clipped actions');
$assert($call['source_ip'] === '127.0.0.1', 'source ip is forwarded for grant network policy');
$assert(count($audits) === 1, 'SandAI-side admin audit is written once');
$audit = $audits[0];
$assert(
    $audit['actor_type'] === 'admin' && $audit['actor_ref'] === '7' && $audit['action'] === 'admin_trial.context.issue'
        && $audit['resource_type'] === 'workload_client',
    'admin audit records the administrator, not the workload credential',
);
$auditJson = json_encode($audit['context'], JSON_UNESCAPED_UNICODE);
$assert(
    is_string($auditJson) && str_contains($auditJson, 'siam_fakeprefix') && !str_contains($auditJson, 'siam_plaintext_secret'),
    'audit context keeps key_prefix only, never the credential plaintext',
);

$assertProblem(
    static fn (): array => $service->issueContext(null, 0, '127.0.0.1', 'atrial_test_2'),
    'SAND_AI_AUTHENTICATION_FAILED',
    'a non-positive admin id cannot issue',
);
$assertProblem(
    static fn (): array => $service->issueContext(null, 7, '0.0.0.0', 'atrial_test_3'),
    'SAND_AI_TRIAL_ISSUE_FAILED',
    'an untrusted source ip refuses before touching SandIAM',
);

$disabledStore = new FakeTrialEntityStore();
$disabledStore->clientDisabled = true;
$assertProblem(
    static fn (): array => (new AdminTrialContextService($disabledStore, new FakeContextIssuer(), new FakeServiceCatalog(), null))->issueContext(null, 7, '127.0.0.1', 'atrial_test_4'),
    'SAND_AI_TRIAL_CLIENT_DISABLED',
    'a disabled trial client degrades instead of reviving entities',
);
$inactiveStore = new FakeTrialEntityStore();
$inactiveStore->grantInactive = true;
$assertProblem(
    static fn (): array => (new AdminTrialContextService($inactiveStore, new FakeContextIssuer(), new FakeServiceCatalog(), null))->issueContext(null, 7, '127.0.0.1', 'atrial_test_5'),
    'SAND_AI_TRIAL_GRANT_INACTIVE',
    'a revoked grant degrades instead of being silently re-enabled',
);
$failingIssuer = new FakeContextIssuer();
$failingIssuer->failure = new RuntimeException('SAND_IAM_CREDENTIAL_REVOKED');
$assertProblem(
    static fn (): array => (new AdminTrialContextService(new FakeTrialEntityStore(), $failingIssuer, new FakeServiceCatalog(), null))->issueContext(null, 7, '127.0.0.1', 'atrial_test_6'),
    'SAND_AI_TRIAL_CLIENT_DISABLED',
    'a revoked credential maps to the disabled guidance',
);
$failingCatalog = new FakeServiceCatalog();
$failingCatalog->fail = true;
$assertProblem(
    static fn (): array => (new AdminTrialContextService(new FakeTrialEntityStore(), new FakeContextIssuer(), $failingCatalog, null))->issueContext(null, 7, '127.0.0.1', 'atrial_test_7'),
    'SAND_AI_TRIAL_CATALOG_INCOMPLETE',
    'a disabled catalog degrades with setup guidance',
);
$absentStore = new AdminTrialContextService(null, new FakeContextIssuer(), new FakeServiceCatalog(), null);
// SandIAM classes genuinely absent in the unit environment => unavailable degrade.
try {
    $absentStore->issueContext(null, 7, '127.0.0.1', 'atrial_test_8');
    // If a SandIAM copy is autoloadable the call may proceed to a later failure;
    // either path must still produce an ApiProblem, never a fatal.
    throw new RuntimeException('Admin trial context check failed: missing store did not degrade');
} catch (ApiProblem $problem) {
    $assert(in_array($problem->errorCode, [
        'SAND_AI_TRIAL_IAM_UNAVAILABLE',
        'SAND_AI_TRIAL_CATALOG_INCOMPLETE',
        'SAND_AI_TRIAL_ISSUE_FAILED',
    ], true), 'a missing or broken SandIAM surface degrades cleanly');
}

/* ---------- 速率限制 ---------- */

AdminTrialRateLimiter::reset();
for ($i = 0; $i < AdminTrialRateLimiter::maxIssues(); ++$i) {
    AdminTrialRateLimiter::assertAllowed(42, 1_000 + $i);
}
$assertProblem(
    static fn () => AdminTrialRateLimiter::assertAllowed(42, 1_000 + AdminTrialRateLimiter::maxIssues()),
    'SAND_AI_TRIAL_RATE_LIMITED',
    'the per-admin per-minute ceiling blocks the next issue',
);
AdminTrialRateLimiter::assertAllowed(43, 1_000 + AdminTrialRateLimiter::maxIssues());
$assert(true, 'the limiter is scoped per administrator');
AdminTrialRateLimiter::assertAllowed(42, 1_000 + AdminTrialRateLimiter::windowSeconds() + 5);
$assert(true, 'hits outside the sliding window no longer count');
AdminTrialRateLimiter::reset();

/* ---------- 端点、权限与迁移登记 ---------- */

$routeFile = file_get_contents(dirname(__DIR__) . '/plugin/sand-ai/config/route.php');
$assert(is_string($routeFile), 'route.php readable');
$groupPos = strpos($routeFile, "Route::group('/app/sand-ai/admin'");
$trialPos = strpos($routeFile, "'/trial/context'");
$assert($groupPos !== false && $trialPos !== false && $trialPos > $groupPos, 'trial endpoint sits inside the /app/sand-ai/admin group');
$assert(strpos($routeFile, "Route::post('/trial/context', [AdminTrialController::class, 'context'])") !== false, 'trial context route is explicit POST');
$assert(strpos($routeFile, '/api/sand-ai/v1/trial') === false && strpos($routeFile, "v1' ]") === false, 'no trial endpoint on the public v1 surface');
$groupSection = substr($routeFile, $groupPos, strpos($routeFile, '});', $trialPos) - $groupPos);
$assert(str_contains($groupSection, 'CheckLogin::class') && str_contains($groupSection, 'CheckAuth::class'), 'admin group keeps CheckLogin + CheckAuth');

$controllerFile = file_get_contents(dirname(__DIR__) . '/plugin/sand-ai/app/admin/controller/AdminTrialController.php');
$assert(is_string($controllerFile), 'AdminTrialController readable');
$assert(str_contains($controllerFile, "'sand_ai:trial:context'"), 'controller declares the trial permission slug');
$assert(str_contains($controllerFile, 'authenticatedAdminId'), 'controller requires an authenticated administrator');
$assert(str_contains($controllerFile, "'Cache-Control', 'no-store'"), 'context response is marked no-store');

$migrationFile = file_get_contents(dirname(__DIR__) . '/plugin/sand-ai/migrations/main-app/202611040165_ux_phase2.pgsql');
$assert(is_string($migrationFile), 'ux_phase2 migration readable');
$assert(substr_count($migrationFile, 'sand_ai:trial:context') >= 2, 'migration seeds the trial permission slug');
$assert(str_contains($migrationFile, 'DO $ux_phase2_trial_permission$'), 'migration block is an idempotent DO block');

/* ---------- 试用实体链收敛到规范默认（ux-p2b） ---------- */

$storeFile = file_get_contents(dirname(__DIR__) . '/app/Application/Trial/SandIamAdminTrialEntityStore.php');
$assert(is_string($storeFile), 'SandIamAdminTrialEntityStore readable');

// The trial chain resolves the same canonical organization/application/
// environment codes as the access-matrix ensure-defaults path — one shared
// source of truth instead of a parallel `system`/`production` chain.
foreach (['DEFAULT_ORGANIZATION_CODE', 'DEFAULT_ORGANIZATION_NAME', 'DEFAULT_APPLICATION_CODE', 'DEFAULT_APPLICATION_NAME', 'DEFAULT_ENVIRONMENT_CODE', 'DEFAULT_ENVIRONMENT_NAME', 'DEFAULT_AUDIENCE'] as $canonical) {
    $assert(
        str_contains($storeFile, 'AccessMatrixInput::' . $canonical),
        'trial chain must reuse SandIAM canonical constant ' . $canonical,
    );
}
$assert(
    str_contains($storeFile, "AccessMatrixInput::class"),
    'AccessMatrixInput belongs to the availability contract',
);
$assert(
    !str_contains($storeFile, "'production'"),
    'no private production environment code may remain on the trial chain',
);
// The pre-unification `system` application is only adopted when it already
// exists — it is never created anymore.
$assert(
    str_contains($storeFile, "LEGACY_APPLICATION_CODE = 'system'"),
    'legacy system application adoption stays explicit',
);
$assert(
    substr_count($storeFile, 'LEGACY_APPLICATION_CODE') >= 2
        && !str_contains($storeFile, "'code' => self::LEGACY_APPLICATION_CODE"),
    'the legacy application is looked up only, never created',
);
// The dedicated trial client converges onto the canonical environment:
// a leftover client under a legacy environment of the same application is
// re-parented, not duplicated, keeping the channel single-client revocable.
$assert(
    str_contains($storeFile, "->where('code', self::CLIENT_CODE)")
        && str_contains($storeFile, "['environment_id' => \$environmentId]"),
    'trial client lookup pins code admin_trial and re-parents onto the canonical environment',
);
$assert(
    str_contains($storeFile, 'whereIn'),
    'client convergence scans every environment of the resolved application',
);
// Trial grants carry data_class NULL: catalog reads and every trial-owned
// resource resolve unclassified facts, and SandIAM requires an exact match.
$assert(
    str_contains($storeFile, "'data_class' => null"),
    'trial grants are created with a NULL data class',
);
$assert(
    str_contains($storeFile, "\$grant->save(['data_class' => null])"),
    'legacy internal-classified trial grants are normalized in place',
);
// The trial whitelist itself stays the minimal read/run set.
foreach (['sand_ai.agent.submit', 'sand_ai.skill.manage', 'sand_ai.tool.run', 'sand_ai.workflow.run', 'sand_ai.retrieval.search'] as $excluded) {
    $assert(!in_array($excluded, AdminTrialActions::all(), true), 'management action stays excluded: ' . $excluded);
}

echo 'Admin trial context test passed (' . $checks . " checks)\n";
