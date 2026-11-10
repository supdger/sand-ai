<?php

declare(strict_types=1);

/**
 * Isolated 0.5.0 index-orchestration and trial-channel tests: no database,
 * no SandIAM plugin, no network. Covers index error copy, trial filter
 * normalization, trial input validation ordering and fail-closed SandIAM
 * degradation. DB-backed paths are covered by repository review.
 *
 * Usage: php tests/sandai-050-b1-index-trial-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Api/Support/IdentityContextProblem.php';
require_once dirname(__DIR__) . '/app/Identity/Contract/IdentityContext.php';
require_once dirname(__DIR__) . '/app/Identity/Contract/IdentityContextException.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamRuntime.php';
require_once dirname(__DIR__) . '/app/Identity/Infrastructure/HostSandIamErrorMapper.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/RequestIdempotencyKey.php';
require_once dirname(__DIR__) . '/app/Application/Usage/UsageAttribution.php';
require_once dirname(__DIR__) . '/app/Application/Usage/PricingValuePolicy.php';
require_once dirname(__DIR__) . '/app/Application/Usage/UsageSummaryPolicy.php';
require_once dirname(__DIR__) . '/plugin/sand-ai/app/admin/logic/UsageEventListPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialRateLimiter.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialIamGateway.php';
require_once dirname(__DIR__) . '/app/Application/Trial/AdminTrialService.php';
require_once dirname(__DIR__) . '/app/Application/Knowledge/IndexTaskGateway.php';
require_once __DIR__ . '/stubs/SupportRequest.php';

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\IndexTaskGateway;
use app\Application\Trial\AdminTrialRateLimiter;
use app\Application\Trial\AdminTrialService;
use app\Application\Usage\UsageSummaryPolicy;
use plugin\SandAi\app\admin\logic\UsageEventListPolicy;
use support\Request as SupportRequest;

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
/** @return array{code:string,message:string}|null */
function problemOf(callable $run): ?array
{
    try {
        $run();
    } catch (ApiProblem $problem) {
        return ['code' => $problem->errorCode, 'message' => $problem->getMessage()];
    } catch (Throwable $exception) {
        return ['code' => get_class($exception), 'message' => $exception->getMessage()];
    }

    return null;
}

// IndexTaskGateway::errorText projects safe Chinese copy without internal codes.
ok(IndexTaskGateway::errorText(null) === '索引没有成功，请重试', 'index error: empty code fallback');
ok(IndexTaskGateway::errorText('') === '索引没有成功，请重试', 'index error: blank code fallback');
ok(str_contains(IndexTaskGateway::errorText('SAND_AI_BUDGET_EXCEEDED'), '预算'), 'index error: budget copy');
ok(str_contains(IndexTaskGateway::errorText('SAND_AI_CONFIGURATION_NOT_PUBLISHED'), '嵌入模型'), 'index error: config copy');
ok(str_contains(IndexTaskGateway::errorText('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED'), '重新发布'), 'index error: source drift copy');
ok(str_contains(IndexTaskGateway::errorText('SAND_AI_PROVIDER_UNAVAILABLE'), '模型服务商'), 'index error: provider copy');
ok(IndexTaskGateway::errorText('SAND_AI_SOMETHING_INTERNAL') === '索引没有成功，请稍后重试', 'index error: unknown code generic');
foreach ([null, 'SAND_AI_BUDGET_EXCEEDED', 'SAND_AI_PROVIDER_TIMEOUT', 'SAND_AI_X'] as $code) {
    ok(!str_contains(IndexTaskGateway::errorText($code), 'SAND_AI_'), 'index error hides internal code');
}

// UsageSummaryPolicy/UsageEventListPolicy accept trial=only|exclude and never project the client id.
$summaryPolicy = new UsageSummaryPolicy();
$normalized = $summaryPolicy->normalize(['trial' => 'only']);
ok($normalized['trial'] === 'only', 'usage filter: trial=only accepted');
$normalized = $summaryPolicy->normalize(['trial' => 'exclude']);
ok($normalized['trial'] === 'exclude', 'usage filter: trial=exclude accepted');
$normalized = $summaryPolicy->normalize([]);
ok($normalized['trial'] === null, 'usage filter: trial absent defaults null');
$problem = problemOf(fn () => $summaryPolicy->normalize(['trial' => 'bogus']));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'usage filter: invalid trial rejected');
$problem = problemOf(fn () => $summaryPolicy->normalize(['surprise' => 'x']));
ok($problem !== null && $problem['code'] === 'SAND_AI_VALIDATION_ERROR', 'usage filter: unknown field still rejected');

$listPolicy = new UsageEventListPolicy();
$listNormalized = $listPolicy->normalize(['trial' => 'only', 'page' => 1, 'limit' => 10]);
ok($listNormalized['trial'] === 'only' && $listNormalized['page'] === 1, 'usage list: trial filter passes through');
$presented = $listPolicy->present([
    'id' => 1, 'invocation_id' => null, 'tool_run_id' => null,
    'organization_id' => null, 'application_id' => null, 'environment_id' => 1,
    'provider_id' => null, 'model_id' => null, 'skill_id' => null, 'skill_version_id' => null,
    'service_action' => 'sand_ai.chat.complete', 'source_kind' => 'chat',
    'capability_code' => 'chat', 'metering_unit' => null, 'metering_quantity' => null,
    'input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2,
    'usage_state' => 'measured', 'price_revision_id' => null,
    'metered_amount' => null, 'currency' => null, 'pricing_state' => 'unpriced',
    'create_time' => '2026-11-11 00:00:00',
]);
ok(!array_key_exists('workload_client_id', $presented), 'usage list: client id never projected');

// AdminTrialService validates trial input before touching IAM or the database.
AdminTrialRateLimiter::reset();
$service = new AdminTrialService();
$request = new SupportRequest();

$problem = problemOf(fn () => $service->knowledgeTrial(['query' => 'q'], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'knowledge trial: missing base id rejected');
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 'abc', 'query' => 'q'], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'knowledge trial: non-numeric base id rejected');
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_MESSAGE_INVALID', 'knowledge trial: empty query rejected');
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1, 'query' => str_repeat('问', 501)], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_MESSAGE_INVALID', 'knowledge trial: overlong query rejected');
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1, 'query' => 'q', 'limit' => 0], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'knowledge trial: zero limit rejected');
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1, 'query' => 'q', 'limit' => 21], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'knowledge trial: limit above 20 rejected');

$problem = problemOf(fn () => $service->agentTrial([], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'agent trial: missing application id rejected');
$problem = problemOf(fn () => $service->agentTrial(['application_id' => 1, 'agent_id' => 2], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_MESSAGE_INVALID', 'agent trial: empty query rejected');
$problem = problemOf(fn () => $service->agentTrial(['application_id' => 1, 'agent_id' => 2, 'query' => 'q', 'conversation_id' => 5], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'agent trial: non-string conversation id rejected');

/* Fail-closed: valid agent-trial input cannot proceed without SandIAM. The
 * knowledge trial resolves the base row first, so without a database it stops
 * at the persistence layer — either way it must never succeed silently. */
$problem = problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1, 'query' => 'q'], 7, null, $request));
ok($problem !== null, 'knowledge trial: unavailable dependencies never succeed');
$problem = problemOf(fn () => $service->agentTrial(['application_id' => 1, 'agent_id' => 2, 'query' => 'q'], 7, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_IAM_UNAVAILABLE', 'agent trial: missing SandIAM fails closed');
$problem = problemOf(fn () => $service->agentTask(0, 7, null));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_VALIDATION_ERROR', 'agent task: non-positive id rejected');
// Rate limit is shared across the new trial endpoints.
for ($i = 0; $i < AdminTrialRateLimiter::maxIssues(); $i++) {
    problemOf(fn () => $service->knowledgeTrial(['knowledge_base_id' => 1], 99, null, $request));
}
$problem = problemOf(fn () => $service->agentTrial([], 99, null, $request));
ok($problem !== null && $problem['code'] === 'SAND_AI_TRIAL_RATE_LIMITED', 'trial endpoints share the per-admin rate limit');
AdminTrialRateLimiter::reset();

echo "sandai-050-b1-index-trial passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
