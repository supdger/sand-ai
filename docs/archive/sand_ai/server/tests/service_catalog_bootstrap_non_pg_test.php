<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\Application\Onboarding\ServiceCatalogBootstrapService;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Service catalog bootstrap check failed: ' . $label);
    }
    ++$checks;
};

/** In-memory stand-in for SandIAM ServiceCatalog: insert-if-missing, never re-enables. */
$makeHost = static fn (): object => new class {
    /** @var array<string,string> */
    public array $stored = [];
    public string $serviceStatus = 'missing';
    public ?string $disabledAction = null;
    public bool $failRegistration = false;
    public int $registerCalls = 0;
    /** @var list<list<string>> */
    public array $registeredGroups = [];

    /** @param array<string,string> $actions @return array<string,mixed> */
    public function inspectServiceActions(array $service, array $actions): array
    {
        $statuses = [];
        foreach (array_keys($actions) as $code) {
            $statuses[$code] = $code === $this->disabledAction
                ? 'disabled'
                : (isset($this->stored[$code]) ? 'active' : 'missing');
        }

        return [
            'service_code' => $service['code'],
            'service_status' => $this->serviceStatus,
            'action_statuses' => $statuses,
            'can_register' => $this->serviceStatus !== 'disabled' && !in_array('disabled', $statuses, true),
        ];
    }

    /** @param array<string,string> $actions */
    public function registerServiceActions(array $service, array $actions): void
    {
        ++$this->registerCalls;
        if ($this->failRegistration) {
            throw new RuntimeException('database exploded');
        }
        $this->registeredGroups[] = array_keys($actions);
        if ($this->serviceStatus === 'missing') {
            $this->serviceStatus = 'active';
        }
        foreach ($actions as $code => $name) {
            $this->stored[$code] ??= $name;
        }
    }
};

// (1) The three declaration groups are registered in order: base, Skill, audio.
$host = $makeHost();
$result = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($host)))->bootstrap();
$assert($result['ready'] === true && $result['reason'] === null && $result['service_code'] === 'sand_ai', 'success shape');
$assert($host->registerCalls === 3, 'three registrations');
$assert($host->registeredGroups[0][0] === 'sand_ai.chat.complete', 'base first');
$assert(in_array('sand_ai.skill.run', $host->registeredGroups[1], true), 'skill second');
$assert(in_array('sand_ai.audio.transcription', $host->registeredGroups[2], true), 'audio third');
$assert($result['groups'] === [
    'base' => count($host->registeredGroups[0]),
    'skill' => count($host->registeredGroups[1]),
    'audio' => count($host->registeredGroups[2]),
], 'group counts');
$assert(count($host->stored) === count($host->registeredGroups[0]) + count($host->registeredGroups[1]) + count($host->registeredGroups[2]), 'all actions stored');

// (3) Repeated calls are idempotent: same result, no additional rows.
$storedAfterFirst = $host->stored;
$second = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($host)))->bootstrap();
$third = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($host)))->bootstrap();
$assert($second === $result && $third === $result, 'repeat result identical');
$assert($host->stored === $storedAfterFirst, 'repeat inserts nothing');

// (2) SandIAM absent (no catalog port): friendly failure, register never invoked.
$absent = new class {
    public int $registerCalls = 0;

    /** @param array<string,string> $actions */
    public function registerServiceActions(array $service, array $actions): void
    {
        ++$this->registerCalls;
    }
};
$missing = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($absent)))->bootstrap();
$assert($missing['ready'] === false && $missing['reason'] === 'sandiam_unavailable', 'missing reason');
$assert($missing['message'] === '需要先安装身份与授权插件（SandIAM）' && $missing['retryable'] === false, 'missing message');
$assert($absent->registerCalls === 0, 'register not called when SandIAM missing');
$assert($missing['groups'] === [] && $missing['service_code'] === null, 'missing carries no success data');

// SandIAM class not loadable at all (default runtime resolver): same friendly failure.
$noRuntime = (new ServiceCatalogBootstrapService())->bootstrap();
$assert(class_exists('plugin\\SandIam\\app\\runtime\\ServiceCatalog') || $noRuntime['reason'] === 'sandiam_unavailable', 'default resolver without SandIAM');

// Disabled service/action: reported, nothing registered, never re-enabled.
$disabledHost = $makeHost();
$disabledHost->serviceStatus = 'disabled';
$disabled = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($disabledHost)))->bootstrap();
$assert($disabled['ready'] === false && $disabled['reason'] === 'sandiam_disabled' && $disabled['retryable'] === false, 'disabled service');
$assert($disabledHost->registerCalls === 0, 'disabled service registers nothing');

$disabledAction = $makeHost();
$disabledAction->disabledAction = 'sand_ai.audio.review';
$partial = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($disabledAction)))->bootstrap();
$assert($partial['reason'] === 'sandiam_disabled', 'disabled audio action');
$assert($disabledAction->registerCalls === 2, 'base and skill registered before audio is refused');

// Unexpected registration failure: retryable, no raw message leaked.
$broken = $makeHost();
$broken->failRegistration = true;
$failed = (new ServiceCatalogBootstrapService(new HostSandIamServiceCatalog($broken)))->bootstrap();
$assert($failed['ready'] === false && $failed['reason'] === 'catalog_error' && $failed['retryable'] === true, 'registration failure');
$assert(!str_contains((string) $failed['message'], 'exploded'), 'no internal message leak');

echo "Service catalog bootstrap test passed ({$checks} checks)\n";
