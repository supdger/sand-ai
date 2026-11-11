<?php

declare(strict_types=1);

/**
 * Isolated 0.5.0 UX-declutter tests: no database, no SandIAM plugin, no
 * network. Covers usage period normalization, budget reminder thresholds,
 * unlimited/soft budget windows, provider failure classification, trial
 * problem mapping and capability sole-target resolution.
 *
 * Usage: php tests/sandai-050-b1-ux-declutter-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Usage/PricingValuePolicy.php';
require_once dirname(__DIR__) . '/app/Application/Usage/UsagePeriodPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Budget/BudgetDecimal.php';
require_once dirname(__DIR__) . '/app/Application/Budget/BudgetAlertPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Budget/BudgetPolicyValidator.php';
require_once dirname(__DIR__) . '/app/Application/Budget/BudgetReservationStateMachine.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Provider/ChatProviderFailurePolicy.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialService.php';
require_once dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/CapabilityTargetLogic.php';
require_once dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/UsagePeriodLogic.php';
require_once __DIR__ . '/stubs/SupportRequest.php';

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetAlertPolicy;
use app\Application\Budget\BudgetPolicyValidator;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Trial\AdminTrialService;
use app\Application\Usage\PricingValuePolicy;
use app\Application\Usage\UsagePeriodPolicy;
use app\Infrastructure\Provider\ChatProviderFailurePolicy;
use plugin\SandAi\app\admin\logic\CapabilityTargetLogic;
use plugin\SandAi\app\admin\logic\UsagePeriodLogic;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

/** @return array{code:string,message:string,retryable:bool,details:array<string,mixed>}|null */
function problemOf(callable $run): ?array
{
    try {
        $run();
    } catch (ApiProblem $problem) {
        return [
            'code' => $problem->errorCode,
            'message' => $problem->getMessage(),
            'retryable' => $problem->retryable,
            'details' => $problem->details,
        ];
    } catch (Throwable $exception) {
        return ['code' => get_class($exception), 'message' => $exception->getMessage(), 'retryable' => false, 'details' => []];
    }

    return null;
}

function hasCjk(string $value): bool
{
    return preg_match('/[\x{4e00}-\x{9fff}]/u', $value) === 1;
}

// UsagePeriodPolicy: host-local calendar buckets over UTC storage

$shanghai = new UsagePeriodPolicy(new PricingValuePolicy(), 'Asia/Shanghai');
$utcNow = new DateTimeImmutable('2026-06-15 04:00:00', new DateTimeZone('UTC'));

$monthly = $shanghai->monthly(['month' => '2026-01'], $utcNow);
ok($monthly['month_from'] === '2026-01' && $monthly['month_to'] === '2026-01', 'monthly single month');
ok($monthly['filters']['from_time'] === '2025-12-31 16:00:00'
    && $monthly['filters']['to_time'] === '2026-01-31 16:00:00', 'monthly Asia/Shanghai UTC bounds');
ok($monthly['timezone'] === 'Asia/Shanghai' && $monthly['group_by'] === 'application', 'monthly defaults');

$range = $shanghai->monthly(['month_from' => '2025-11', 'month_to' => '2026-01'], $utcNow);
ok($range['filters']['from_time'] === '2025-10-31 16:00:00'
    && $range['filters']['to_time'] === '2026-01-31 16:00:00', 'monthly range UTC bounds');
ok($shanghai->monthly(['month_from' => '2025-01', 'month_to' => '2025-12'], $utcNow)['month_to'] === '2025-12', 'monthly 12-month boundary allowed');

$problem = problemOf(fn () => $shanghai->monthly(['month_from' => '2025-01', 'month_to' => '2026-01'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'monthly 13-month range rejected');
$problem = problemOf(fn () => $shanghai->monthly(['month' => '2026-01', 'month_from' => '2026-01'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'month cannot combine with range');
$problem = problemOf(fn () => $shanghai->monthly(['month' => '2026-13'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'malformed month rejected');
$problem = problemOf(fn () => $shanghai->monthly(['group_by' => 'provider'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'unknown group_by rejected');
$problem = problemOf(fn () => $shanghai->monthly(['application_id' => 'abc'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'non-integer filter id rejected');
$modelMonthly = $shanghai->monthly(['group_by' => 'model', 'month' => '2026-06'], $utcNow);
ok($modelMonthly['group_by'] === 'model', 'group_by=model accepted');

// DST: America/New_York March 2026 crosses the spring-forward boundary, so
// the two local midnights map to different UTC offsets.
$ny = new UsagePeriodPolicy(new PricingValuePolicy(), 'America/New_York');
$nyMonthly = $ny->monthly(['month' => '2026-03'], $utcNow);
ok($nyMonthly['filters']['from_time'] === '2026-03-01 05:00:00'
    && $nyMonthly['filters']['to_time'] === '2026-04-01 04:00:00', 'monthly New York DST bounds');

$daily = $shanghai->daily(['date_from' => '2026-06-15', 'date_to' => '2026-06-15'], $utcNow);
ok($daily['filters']['from_time'] === '2026-06-14 16:00:00'
    && $daily['filters']['to_time'] === '2026-06-15 16:00:00', 'daily single-day Shanghai bounds');
$dailyMonth = $shanghai->daily(['month' => '2026-02'], $utcNow);
ok($dailyMonth['date_from'] === '2026-02-01' && $dailyMonth['date_to'] === '2026-02-28', 'daily month uses local last day');

// 93-day inclusive boundary: 2026-01-01..2026-04-03 is exactly 93 days.
$ok93 = $shanghai->daily(['date_from' => '2026-01-01', 'date_to' => '2026-04-03'], $utcNow);
ok($ok93['date_to'] === '2026-04-03', 'daily 93-day range allowed');
$problem = problemOf(fn () => $shanghai->daily(['date_from' => '2026-01-01', 'date_to' => '2026-04-04'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'daily 94-day range rejected');
$problem = problemOf(fn () => $shanghai->daily(['date_from' => '2026-02-30', 'date_to' => '2026-03-01'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'impossible date rejected');
$problem = problemOf(fn () => $shanghai->daily(['date_from' => '2026-06-10'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'one-sided date range rejected');
$problem = problemOf(fn () => $shanghai->daily(['month' => '2026-06', 'date_from' => '2026-06-01'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'month cannot combine with dates');
$problem = problemOf(fn () => $shanghai->daily(['bogus' => '1'], $utcNow));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'unknown daily query key rejected');

// Without host config the timezone falls back to Asia/Shanghai.
$fallback = (new UsagePeriodPolicy(new PricingValuePolicy()))->timezone();
ok($fallback === 'Asia/Shanghai', 'timezone falls back to Asia/Shanghai');

// BudgetAlertPolicy: reminder percentage and unlimited windows

ok(BudgetAlertPolicy::forWindow(['limit_amount' => null, 'available_amount' => null])['level'] === 'normal', 'alert: unlimited window normal');
ok(BudgetAlertPolicy::forWindow(['limit_amount' => '', 'available_amount' => '50.000000'])['level'] === 'normal', 'alert: empty limit normal');
ok(BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => null])['level'] === 'normal', 'alert: missing available normal');
ok(BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '100.000000'])['level'] === 'normal', 'alert: untouched limit normal');
$at80 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '20.000000']);
ok($at80['level'] === 'notice' && $at80['threshold_percent'] === 80, 'alert: default 80 notice');
$at90 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '5.000000']);
ok($at90['level'] === 'warning' && $at90['threshold_percent'] === 90, 'alert: default 90 warning');
$at100 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '0.000000']);
ok($at100['level'] === 'exhausted' && $at100['threshold_percent'] === 100, 'alert: zero available exhausted');
$negative = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '-1.000000']);
ok($negative['level'] === 'exhausted', 'alert: negative available exhausted');

$custom50 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '50.000000'], 50);
ok($custom50['level'] === 'notice' && $custom50['threshold_percent'] === 50, 'alert: custom 50 notice boundary');
$below50 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '50.000001'], 50);
ok($below50['level'] === 'normal', 'alert: micro below custom threshold normal');
$warn60 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '40.000000'], 50);
ok($warn60['level'] === 'warning' && $warn60['threshold_percent'] === 60, 'alert: reminder+10 warning');
$clamped = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '1.000000'], 95);
ok($clamped['level'] === 'warning' && $clamped['threshold_percent'] === 99, 'alert: warning clamps below 100');
$notice95 = BudgetAlertPolicy::forWindow(['limit_amount' => '100.000000', 'available_amount' => '5.000000'], 95);
ok($notice95['level'] === 'notice' && $notice95['threshold_percent'] === 95, 'alert: 95 notice below clamped warning');

// BudgetPolicyValidator: empty limit and reminder_percent

$validator = new BudgetPolicyValidator();
$draftBase = [
    'environment_id' => 5,
    'period_start' => '2026-01-01 00:00:00',
    'period_end' => '2026-02-01 00:00:00',
    'currency' => 'usd',
    'enforcement' => 'soft',
    'unpriced_policy' => 'allow',
    'unmeasured_policy' => 'allow',
];
$unlimited = $validator->normalizeDraft($draftBase + ['limit_amount' => '']);
ok($unlimited['limit_amount'] === null && $unlimited['reminder_percent'] === 80, 'validator: empty limit unlimited, reminder defaults 80');
ok($unlimited['currency'] === 'USD' && $unlimited['environment_id'] === 5, 'validator: currency uppercased, scope kept');
$limited = $validator->normalizeDraft($draftBase + ['limit_amount' => '10.5', 'reminder_percent' => '85']);
ok($limited['limit_amount'] === '10.500000' && $limited['reminder_percent'] === 85, 'validator: decimal normalized, reminder parsed');
$omitted = $validator->normalizeDraft($draftBase + ['limit_amount' => '1']);
ok($omitted['reminder_percent'] === BudgetAlertPolicy::DEFAULT_REMINDER_PERCENT, 'validator: omitted reminder defaults');

foreach ([['limit_amount' => '0'], ['limit_amount' => '-1'], ['limit_amount' => 'abc'], ['limit_amount' => '1e3']] as $bad) {
    $problem = problemOf(fn () => $validator->normalizeDraft($draftBase + $bad));
    ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_VALIDATION_ERROR', 'validator rejects limit ' . json_encode($bad));
}
foreach ([0, 101, 'abc', 80.5] as $badReminder) {
    $problem = problemOf(fn () => $validator->normalizeDraft($draftBase + ['limit_amount' => '1', 'reminder_percent' => $badReminder]));
    ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_VALIDATION_ERROR', 'validator rejects reminder ' . json_encode($badReminder));
}
$problem = problemOf(fn () => $validator->normalizeDraft(array_merge($draftBase, ['limit_amount' => '1', 'enforcement' => 'hard'])));
ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_VALIDATION_ERROR', 'validator: hard budget still requires block/hold');
$hardOk = $validator->normalizeDraft(['environment_id' => 5, 'period_start' => '2026-01-01 00:00:00', 'period_end' => '2026-02-01 00:00:00', 'currency' => 'CNY', 'limit_amount' => '5', 'enforcement' => 'hard', 'unpriced_policy' => 'block', 'unmeasured_policy' => 'hold']);
ok($hardOk['enforcement'] === 'hard' && $hardOk['limit_amount'] === '5.000000', 'validator: executable hard budget accepted');
$problem = problemOf(fn () => $validator->normalizeDraft($draftBase + ['limit_amount' => '1', 'surprise' => 'x']));
ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_VALIDATION_ERROR', 'validator rejects unknown field');

// BudgetReservationStateMachine: unlimited windows and soft overrun

$machine = new BudgetReservationStateMachine();
$emptyWindow = ['currency' => 'USD', 'limit_amount' => null, 'reserved_amount' => '0', 'settled_amount' => '0', 'held_amount' => '0'];
$summary = $machine->summary($emptyWindow);
ok($summary['available_amount'] === null && $summary['limit_amount'] === null, 'state machine: unlimited summary has null availability');

$unlimitedReserve = $machine->reserve($emptyWindow, '999999.000000', 'USD');
ok($unlimitedReserve['window']['reserved_amount'] === '999999.000000'
    && $unlimitedReserve['window']['available_amount'] === null, 'state machine: unlimited reservation tracked without ceiling');
$unlimitedSettle = $machine->settle($unlimitedReserve['window'], $unlimitedReserve['reservation'], '500000.000000', 'USD');
ok($unlimitedSettle['overrun'] === false
    && $unlimitedSettle['window']['settled_amount'] === '500000.000000', 'state machine: unlimited settle within estimate never reports overrun');
$estimateExceeded = $machine->settle($unlimitedReserve['window'], $unlimitedReserve['reservation'], '1000000.000000', 'USD');
ok($estimateExceeded['overrun'] === true
    && $estimateExceeded['window']['settled_amount'] === '1000000.000000', 'state machine: settle above estimate still flags overrun');

$limitedWindow = ['currency' => 'USD', 'limit_amount' => '100.000000', 'reserved_amount' => '0', 'settled_amount' => '0', 'held_amount' => '0'];
$problem = problemOf(fn () => $machine->reserve($limitedWindow, '150.000000', 'USD'));
ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_EXCEEDED', 'state machine: hard reserve over limit rejected');
$softReserve = $machine->reserve($limitedWindow, '150.000000', 'USD', true);
ok($softReserve['window']['reserved_amount'] === '150.000000'
    && $softReserve['window']['available_amount'] === '-50.000000', 'state machine: soft reserve passes through with negative availability');
$softSettle = $machine->settle($softReserve['window'], $softReserve['reservation'], '160.000000', 'USD');
ok($softSettle['overrun'] === true && $softSettle['window']['settled_amount'] === '160.000000', 'state machine: soft settle charges actual and flags overrun');

$exactReserve = $machine->reserve($limitedWindow, '100.000000', 'USD');
ok($exactReserve['window']['available_amount'] === '0.000000', 'state machine: reserve to exact limit allowed');
$problem = problemOf(fn () => $machine->reserve($exactReserve['window'], '0.000001', 'USD'));
ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_EXCEEDED', 'state machine: one micro over limit rejected');
$problem = problemOf(fn () => $machine->reserve($limitedWindow, '1.000000', 'EUR'));
ok($problem !== null && $problem['code'] === 'SAND_AI_BUDGET_CURRENCY_MISMATCH', 'state machine: currency mismatch rejected');

// ChatProviderFailurePolicy: transport classification

$invalidKey = ChatProviderFailurePolicy::http(401);
ok($invalidKey->errorCode === 'SAND_AI_PROVIDER_REJECTED'
    && ChatProviderFailurePolicy::reason($invalidKey) === 'invalid_api_key'
    && ChatProviderFailurePolicy::family($invalidKey) === 'config', 'provider: 401 invalid key');
$balance = ChatProviderFailurePolicy::http(402);
ok($balance->errorCode === 'SAND_AI_PROVIDER_BALANCE_INSUFFICIENT'
    && ChatProviderFailurePolicy::reason($balance) === 'provider_balance_insufficient'
    && ChatProviderFailurePolicy::family($balance) === 'balance', 'provider: 402 balance insufficient');
$rateLimited = ChatProviderFailurePolicy::http(429);
ok($rateLimited->errorCode === 'SAND_AI_PROVIDER_RATE_LIMITED'
    && ChatProviderFailurePolicy::reason($rateLimited) === 'provider_rate_limited'
    && ChatProviderFailurePolicy::family($rateLimited) === 'busy' && $rateLimited->retryable, 'provider: 429 rate limited');
$serverError = ChatProviderFailurePolicy::http(500);
ok($serverError->errorCode === 'SAND_AI_PROVIDER_UNAVAILABLE'
    && ChatProviderFailurePolicy::family($serverError) === 'busy', 'provider: 5xx unconfirmed busy');
$timeout = ChatProviderFailurePolicy::network(28);
ok(ChatProviderFailurePolicy::reason($timeout) === 'provider_timeout'
    && ChatProviderFailurePolicy::family($timeout) === 'busy', 'provider: curl 28 timeout busy');
$unreachable = ChatProviderFailurePolicy::network(7);
ok(ChatProviderFailurePolicy::reason($unreachable) === 'provider_unavailable'
    && ChatProviderFailurePolicy::family($unreachable) === 'unreachable', 'provider: network failure unreachable');
ok(ChatProviderFailurePolicy::family(new ApiProblem('SAND_AI_BUDGET_EXCEEDED', 'budget')) === null, 'provider: non-provider problem has no family');
ok(ChatProviderFailurePolicy::family(new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'config')) === 'config', 'provider: config invalid family');

// AdminTrialService::mapGatewayProblem via reflection

$service = new AdminTrialService();
$mapMethod = new ReflectionMethod(AdminTrialService::class, 'mapGatewayProblem');
$mapMethod->setAccessible(true);
$map = static fn (ApiProblem $problem): ApiProblem => $mapMethod->invoke($service, $problem, 'DeepSeek');

$budget = $map(new ApiProblem('SAND_AI_BUDGET_EXCEEDED', 'budget window exhausted'));
ok($budget->errorCode === 'SAND_AI_TRIAL_BUDGET_BLOCKED'
    && $budget->details['reason_code'] === 'budget_exhausted'
    && $budget->getMessage() === '本月预算已用完，可以去费用页调高预算', 'trial: budget exhausted copy');
$budgetConfig = $map(new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'not executable'));
ok($budgetConfig->details['reason_code'] === 'budget_blocked' && hasCjk($budgetConfig->getMessage()), 'trial: budget config blocked copy');
$providerDown = $map(ChatProviderFailurePolicy::network(7));
ok($providerDown->errorCode === 'SAND_AI_TRIAL_PROVIDER_UNREACHABLE'
    && $providerDown->details['reason_code'] === 'provider_unreachable'
    && str_contains($providerDown->getMessage(), '测试连接')
    && str_contains($providerDown->getMessage(), 'DeepSeek')
    && $providerDown->retryable, 'trial: unreachable directs to provider test');
$busy = $map(ChatProviderFailurePolicy::http(429));
ok($busy->details['reason_code'] === 'provider_busy' && str_contains($busy->getMessage(), '忙'), 'trial: busy copy');
$providerBalance = $map(ChatProviderFailurePolicy::http(402));
ok($providerBalance->details['reason_code'] === 'provider_balance_insufficient'
    && str_contains($providerBalance->getMessage(), '充值'), 'trial: balance copy directs recharge');
$badKey = $map(ChatProviderFailurePolicy::http(401));
ok($badKey->details['reason_code'] === 'provider_config_invalid'
    && str_contains($badKey->getMessage(), '模型服务商'), 'trial: invalid key directs provider page');
$unknown = $map(new ApiProblem('SAND_AI_SOMETHING_INTERNAL', 'internal english diagnostic request_id=abc123'));
ok($unknown->errorCode === 'SAND_AI_TRIAL_CHAT_FAILED'
    && $unknown->details['reason_code'] === 'unknown'
    && $unknown->getMessage() === '这次试用没有成功，请稍后重试'
    && !str_contains($unknown->getMessage(), 'SAND_AI')
    && !str_contains($unknown->getMessage(), 'abc123'), 'trial: unknown failure sanitized');
foreach ([$budget, $budgetConfig, $providerDown, $busy, $providerBalance, $badKey, $unknown] as $mapped) {
    ok(!str_contains($mapped->getMessage(), 'SAND_AI_'), 'trial message hides internal code: ' . $mapped->errorCode);
}

// CapabilityTargetLogic::soleCandidate

ok(CapabilityTargetLogic::soleCandidate([7], 'empty', 'many') === 7, 'capability: sole candidate wins');
ok(CapabilityTargetLogic::soleCandidate(['7', '7', 7], 'empty', 'many') === 7, 'capability: duplicate ids dedupe');
$problem = problemOf(fn () => CapabilityTargetLogic::soleCandidate([], '还没有可管理的应用，请先开通应用', 'many'));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR'
    && $problem['message'] === '还没有可管理的应用，请先开通应用', 'capability: zero candidates refuse');
$problem = problemOf(fn () => CapabilityTargetLogic::soleCandidate([1, 2], 'empty', '有多个应用，请选择要配置的应用'));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR'
    && $problem['message'] === '有多个应用，请选择要配置的应用', 'capability: multiple candidates refuse');

// UsagePeriodLogic: catalog-name degradation without a database

$periodLogic = new UsagePeriodLogic();
$named = $periodLogic->monthlyNames('application', [
    ['month' => '2026-01', 'application_id' => null, 'currency' => 'CNY', 'amount' => '1.000000', 'call_count' => 1, 'failed_count' => 0, 'unpriced_count' => 0, 'unmeasured_count' => 0],
]);
ok($named[0]['name'] === '未归属' && $named[0]['deleted'] === false, 'usage names: unattributed row labelled');
$namedModel = $periodLogic->monthlyNames('model', [
    ['month' => '2026-01', 'model_id' => null, 'currency' => null, 'amount' => null, 'call_count' => 0, 'failed_count' => 0, 'unpriced_count' => 0, 'unmeasured_count' => 0],
]);
ok($namedModel[0]['name'] === '未归属', 'usage names: unattributed model labelled');

echo "sandai-050-b1-ux-declutter passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
