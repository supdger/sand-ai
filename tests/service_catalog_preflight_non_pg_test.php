<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
require $vendor;
$app = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($app): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
}, true, true);

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\HostSandIamServiceCatalog;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Service catalog preflight failed at check ' . ($checks + 1));
    ++$checks;
};
$unavailable = (new HostSandIamServiceCatalog(new class {
    public function registerServiceActions(array $service, array $actions): void
    {
        throw new RuntimeException('Registration must not run without inspection');
    }
}))->preflight();
$assert($unavailable['port_available'] === false && $unavailable['service_status'] === null
    && $unavailable['action_statuses'] === [] && $unavailable['can_register'] === false);

$host = new class {
    public string $serviceStatus = 'missing';
    public ?string $disabledAction = null;
    public bool $invalid = false;
    public bool $race = false;
    public int $registerCalls = 0;
    public function inspectServiceActions(array $service, array $actions): array
    {
        if ($this->invalid) return ['service_code' => 'wrong'];
        $statuses = array_fill_keys(array_keys($actions), 'missing');
        if ($this->disabledAction !== null) $statuses[$this->disabledAction] = 'disabled';
        return [
            'service_code' => $service['code'], 'service_status' => $this->serviceStatus,
            'action_statuses' => $statuses,
            'can_register' => $this->serviceStatus !== 'disabled' && $this->disabledAction === null,
        ];
    }
    public function registerServiceActions(array $service, array $actions): void
    {
        ++$this->registerCalls;
        if ($this->race) throw new RuntimeException('SAND_IAM_SERVICE_CATALOG_DISABLED: concurrent disable');
    }
};
$catalog = new HostSandIamServiceCatalog($host);
$base = $catalog->preflight();
$assert($base['port_available'] && $base['can_register']
    && $base['service_code'] === 'sand_ai' && count($base['action_codes']) === 18);
$assert($base['action_codes'] === array_keys($base['action_statuses']));
$assert($catalog->register()['registered'] && $host->registerCalls === 1);
$skill = $catalog->preflightSkill();
$assert($skill['action_codes'] === ['sand_ai.skill.run'] && $skill['can_register']);
$audio = $catalog->preflightAudio();
$assert($audio['action_codes'] === ['sand_ai.audio.transcription', 'sand_ai.audio.review'] && $audio['can_register']);
$declared = array_fill_keys(array_merge($base['action_codes'], $skill['action_codes'], $audio['action_codes']), true);
$required = [];
foreach (glob(dirname(__DIR__) . '/app/Api/Controller/*.php') ?: [] as $controller) {
    preg_match_all("/->authenticate\\(\\s*\\\$request\\s*,\\s*'(sand_ai\\.[a-z0-9_.]+)'/", (string) file_get_contents($controller), $matches);
    foreach ($matches[1] as $action) $required[$action] = true;
}
$assert(count($required) >= 20 && array_diff_key($required, $declared) === []);

$host->serviceStatus = 'disabled';
$assert($catalog->preflight()['can_register'] === false);
try {
    $catalog->register();
    throw new RuntimeException('Disabled service was registered');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_DISABLED' && $host->registerCalls === 1);
}
$host->serviceStatus = 'active';
$host->disabledAction = 'sand_ai.task.read';
$assert($catalog->preflight()['action_statuses']['sand_ai.task.read'] === 'disabled');
try {
    $catalog->register();
    throw new RuntimeException('Disabled task action was registered');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_DISABLED' && $host->registerCalls === 1);
}
$host->disabledAction = 'sand_ai.skill.run';
$assert($catalog->preflightSkill()['action_statuses']['sand_ai.skill.run'] === 'disabled');
try {
    $catalog->registerSkill();
    throw new RuntimeException('Disabled action was registered');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_DISABLED' && $host->registerCalls === 1);
}
$host->disabledAction = null;
$host->invalid = true;
try {
    $catalog->preflight();
    throw new RuntimeException('Invalid inspection was accepted');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_UNAVAILABLE');
}
$host->invalid = false;
$host->race = true;
try {
    $catalog->registerAudio();
    throw new RuntimeException('Concurrent disable was accepted');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_SERVICE_CATALOG_DISABLED'
        && $host->registerCalls === 2);
}
echo 'service-catalog-preflight ' . $checks . '/' . $checks . PHP_EOL;
