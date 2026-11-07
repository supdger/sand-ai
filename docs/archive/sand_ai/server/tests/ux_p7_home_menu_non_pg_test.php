<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// UX Phase 2 P7 contract checks that do not need PostgreSQL:
// 1) the home-menu migration keeps the 164-style idempotent DO block shape;
// 2) the frontend onboarding api exposes the frozen status() read contract;
// 3) the merged home page exists as a lightweight component and the old
//    onboarding file/route is untouched;
// 4) the one-click orchestration journals every uncertain write and only
//    resolves intents through read-back recovery.

$root = dirname(__DIR__);
$frontend = dirname($root) . '/sandadmin-artd/src/views/plugin/sand-ai';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P7 home-menu check failed: ' . $label);
    }
    ++$checks;
};

// ==== A: migration file ====

$migrationPath = $root . '/plugin/sand-ai/migrations/main-app/202611040166_ux_p7_home_menu.pgsql';
$assert(is_file($migrationPath), 'migration file exists');
$migration = (string) file_get_contents($migrationPath);
$assert(substr_count($migration, '$ux_p7_home_menu$') === 2, 'single named DO block open/close');
$assert(str_contains($migration, "to_regclass('sand_ai_configuration_navigation_ownership')"), 'ownership table guard');
$assert(str_contains($migration, 'LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE'), 'menu table lock');
$assert(substr_count($migration, 'RAISE EXCEPTION') >= 3, 'assertions present');
$assert(str_contains($migration, "code='SandAIOverview'"), 'overview node lookup');
$assert(str_contains($migration, "name='首页'"), 'overview renamed to home');
$assert(str_contains($migration, "path='/sand-ai/home'"), 'overview absolute home route');
$assert(str_contains($migration, "component='/plugin/sand-ai/home/index'"), 'overview component repointed');
$assert(str_contains($migration, "code='SandAIOnboarding'"), 'onboarding node lookup');
$assert(str_contains($migration, 'is_hidden=1'), 'onboarding hidden from navigation');
$assert(!str_contains($migration, 'DROP') && !str_contains($migration, 'DELETE FROM'), 'no destructive statements');
$assert(!str_contains($migration, 'INSERT INTO sand_system_menu'), 'no new menu nodes inserted');

// ==== B: frontend status() contract ====

$apiPath = $frontend . '/api/onboarding.ts';
$assert(is_file($apiPath), 'onboarding api file exists');
$api = (string) file_get_contents($apiPath);
$assert(str_contains($api, 'export interface OnboardingStatus'), 'OnboardingStatus type exported');
$assert(str_contains($api, "'connect_provider' | 'discover_model' | 'verify_model'"), 'stage code union part 1');
$assert(str_contains($api, "'publish_configuration' | 'bind_environment' | 'configuration_published'"), 'stage code union part 2');
$assert(str_contains($api, "url: `\${setup}/onboarding/status`"), 'status endpoint path');
$assert(str_contains($api, 'params: { environment_id: environmentId }'), 'status requires environment_id');
$assert(str_contains($api, 'export interface ProviderModelDeployment'), 'provider-reuse payload type');
$assert(str_contains($api, 'ProviderModelDeployment | SpeechDeployment'), 'save() accepts provider-reuse payload');

// ==== C: merged home page ====

$homePath = $frontend . '/home/index.vue';
$assert(is_file($homePath), 'home component exists');
$home = (string) file_get_contents($homePath);
$assert(str_contains($home, 'onboardingApi.status('), 'home reads onboarding status');
$assert(str_contains($home, 'useSetupBootstrap'), 'home reuses lazy bootstrap');
$assert(str_contains($home, 'providerApi.testConnection'), 'home reuses P6 test-connection');
$assert(str_contains($home, 'providerApi.guides'), 'home reuses P6 preset guides');
$assert(str_contains($home, 'onboardingApi.save('), 'orchestration step: save');
$assert(str_contains($home, 'onboardingApi.verify('), 'orchestration step: verify');
$assert(str_contains($home, 'onboardingApi.publish('), 'orchestration step: publish');
$assert(str_contains($home, 'onboardingApi.bindDefaultChat('), 'orchestration step: bind');
$assert(str_contains($home, 'readSavedDraftByKey'), 'save outcome read-back');
$assert(str_contains($home, 'readVerification('), 'verify outcome read-back');
$assert(str_contains($home, 'capabilityApi.readProfile'), 'bind outcome read-back');
$assert(str_contains($home, 'ChatRouteTrialPanel'), 'trial panel embedded');
$assert(str_contains($home, 'published_configuration'), 'completion flag drives the panel');
$assert(str_contains($home, "go('/sand-ai/onboarding')"), 'advanced onboarding link preserved');

$onboardingPath = $frontend . '/onboarding/index.vue';
$assert(is_file($onboardingPath), 'legacy onboarding component untouched');

$dashboardPath = $frontend . '/dashboard/index.vue';
$assert(is_file($dashboardPath), 'legacy dashboard component retained');

// ==== D: orchestration journal ====

$intentPath = $frontend . '/api/homeActivationIntent.ts';
$assert(is_file($intentPath), 'activation intent journal exists');
$intent = (string) file_get_contents($intentPath);
foreach (['save_unknown', 'saved', 'verify_unknown', 'tested', 'publish_unknown', 'published', 'bind_unknown'] as $phase) {
    $assert(str_contains($intent, "'" . $phase . "'"), 'journal phase: ' . $phase);
}
$assert(str_contains($intent, 'sessionStorage'), 'journal persists in session storage');
$assert(str_contains($intent, 'retainHomeActivationIntent'), 'retain primitive');
$assert(str_contains($intent, 'updateHomeActivationIntent'), 'update primitive');
$assert(str_contains($intent, 'resolveHomeActivationIntent'), 'resolve primitive');
$assert(!str_contains($intent, 'api_key'), 'journal never stores secrets');

fwrite(STDOUT, 'ux_p7_home_menu_non_pg_test: ' . $checks . " checks passed\n");
