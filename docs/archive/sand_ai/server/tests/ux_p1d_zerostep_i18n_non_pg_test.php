<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\Api\Support\ApiProblem;
use app\Application\Audit\AuditDisplayDictionary;
use app\Application\Onboarding\DeploymentDraftSaveRequestPolicy;
use app\Application\Onboarding\CatalogOperationReceipt;
use app\Application\Onboarding\InternalSecurityMaterialGuard;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P1d check failed: ' . $label);
    }
    ++$checks;
};

$envNames = [
    'SAND_AI_CONFIG_KEY',
    'SAND_AI_IDEMPOTENCY_KEY',
    'SAND_AI_IDEMPOTENCY_KEY_ID',
    'SAND_AI_IDEMPOTENCY_KEYRING',
];
$clearEnv = static function () use ($envNames): void {
    foreach ($envNames as $name) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
};

// ==== B1: lazy first-use material resolution ====

// (1) Ready status short-circuits without consulting the ensurer.
$ensurerCalls = 0;
$guard = new InternalSecurityMaterialGuard(
    static fn (): array => ['ready' => true, 'missing_groups' => [], 'invalid_groups' => [], 'recovery_action' => 'none'],
    static function () use (&$ensurerCalls): array {
        ++$ensurerCalls;
        throw new RuntimeException('ensurer must not run when already ready');
    },
);
$assert($guard->ready() === true, 'ready status resolves immediately');
$assert($ensurerCalls === 0, 'ensurer not called when ready');

// (2) Not ready + a ready ensure result resolves in one attempt.
$ensurerCalls = 0;
$guard = new InternalSecurityMaterialGuard(
    static fn (): array => ['ready' => false, 'missing_groups' => ['idempotency'], 'invalid_groups' => [], 'recovery_action' => 'initialize_or_restore'],
    static function () use (&$ensurerCalls): array {
        ++$ensurerCalls;
        return ['ready' => true, 'changed' => true, 'reason' => null, 'message' => null, 'retryable' => false];
    },
);
$assert($guard->ready() === true, 'lazy ensure resolves material');
$assert($ensurerCalls === 1, 'single ensure attempt when it succeeds');

// (3) A retryable first-use failure gets exactly one bounded retry.
$ensurerCalls = 0;
$guard = new InternalSecurityMaterialGuard(
    static fn (): array => ['ready' => false, 'missing_groups' => ['idempotency'], 'invalid_groups' => [], 'recovery_action' => 'initialize_or_restore'],
    static function () use (&$ensurerCalls): array {
        ++$ensurerCalls;
        return $ensurerCalls === 1
            ? ['ready' => false, 'changed' => false, 'reason' => 'security_init_failed', 'message' => 'x', 'retryable' => true]
            : ['ready' => true, 'changed' => false, 'reason' => null, 'message' => null, 'retryable' => false];
    },
);
$assert($guard->ready() === true, 'retryable failure succeeds on second attempt');
$assert($ensurerCalls === 2, 'exactly two ensure attempts');

// (4) Non-retryable failure stays failed with a single attempt.
$ensurerCalls = 0;
$guard = new InternalSecurityMaterialGuard(
    static fn (): array => ['ready' => false, 'missing_groups' => [], 'invalid_groups' => ['idempotency'], 'recovery_action' => 'restore_original_material'],
    static function () use (&$ensurerCalls): array {
        ++$ensurerCalls;
        return ['ready' => false, 'changed' => false, 'reason' => 'security_init_failed', 'message' => 'x', 'retryable' => false];
    },
);
$assert($guard->ready() === false, 'non-retryable failure stays failed');
$assert($ensurerCalls === 1, 'no retry for restore-class failures');

// (5) First write without bootstrap: the injected guard represents the shared
//     file material becoming visible; the fingerprint then succeeds at once.
$clearEnv();
$policy = new DeploymentDraftSaveRequestPolicy(static function (): bool {
    $_SERVER['SAND_AI_IDEMPOTENCY_KEY'] = str_repeat('a', 64);
    $_ENV['SAND_AI_IDEMPOTENCY_KEY'] = str_repeat('a', 64);
    return true;
});
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'lazy-key-1', '1');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $fingerprint['idempotency_hmac']) === 1, 'first write succeeds after lazy material resolution');
$assert($fingerprint['key_id'] === 'active', 'active key id after lazy resolution');

// (6) Material still unavailable after lazy resolution keeps the original
//     fail-closed error contract.
$clearEnv();
$policy = new DeploymentDraftSaveRequestPolicy(static fn (): bool => false);
try {
    $policy->fingerprint(['operation' => 'probe'], 'lazy-key-2', '1');
    $assert(false, 'missing material after lazy resolution must throw');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'fail-closed error code preserved');
}

// (7) Present material never consults the guard.
$clearEnv();
$_SERVER['SAND_AI_IDEMPOTENCY_KEY'] = str_repeat('b', 64);
$policy = new DeploymentDraftSaveRequestPolicy(static function (): bool {
    throw new RuntimeException('guard must not run when material is present');
});
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'lazy-key-3', '1');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $fingerprint['idempotency_hmac']) === 1, 'existing material skips lazy path');
$clearEnv();

// (7a) Cold process where the shared file declares a non-default key id
//      (rotation or install-time value not loaded yet): the declared id is
//      resolved only after lazy material loading, so the very first request
//      succeeds against `rotated1` instead of failing on the stale `active`
//      default.
$clearEnv();
$rotatedRing = json_encode(['rotated1' => str_repeat('r', 64)], JSON_THROW_ON_ERROR);
$policy = new DeploymentDraftSaveRequestPolicy(static function () use ($rotatedRing): bool {
    $_SERVER['SAND_AI_IDEMPOTENCY_KEY_ID'] = 'rotated1';
    $_ENV['SAND_AI_IDEMPOTENCY_KEY_ID'] = 'rotated1';
    $_SERVER['SAND_AI_IDEMPOTENCY_KEYRING'] = $rotatedRing;
    $_ENV['SAND_AI_IDEMPOTENCY_KEYRING'] = $rotatedRing;
    return true;
});
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'cold-key-1', '1');
$assert($fingerprint['key_id'] === 'rotated1', 'first request uses the file-declared key id');
$assert(preg_match('/\A[a-f0-9]{64}\z/', $fingerprint['idempotency_hmac']) === 1, 'cold-process first request succeeds');
$explicit = $policy->fingerprintForKeyId(['operation' => 'probe'], 'cold-key-1', '1', 'rotated1');
$assert(hash_equals($explicit['idempotency_hmac'], $fingerprint['idempotency_hmac']), 'hmac bound to the declared key id');
$clearEnv();

// (7b) A declared non-default id whose material is already loaded never
//      consults the guard.
$clearEnv();
$_SERVER['SAND_AI_IDEMPOTENCY_KEY_ID'] = 'rotated1';
$_SERVER['SAND_AI_IDEMPOTENCY_KEYRING'] = json_encode(['rotated1' => str_repeat('k', 40)], JSON_THROW_ON_ERROR);
$policy = new DeploymentDraftSaveRequestPolicy(static function (): bool {
    throw new RuntimeException('guard must not run when declared-id material is present');
});
$fingerprint = $policy->fingerprint(['operation' => 'probe'], 'cold-key-2', '1');
$assert($fingerprint['key_id'] === 'rotated1', 'declared key id resolves without guard');
$clearEnv();

// ==== N1: audit display dictionary ====

// (8) Known English codes map to Chinese on all three columns.
$assert(AuditDisplayDictionary::action('catalog.model.save') === '保存模型', 'catalog.model.save label');
$assert(AuditDisplayDictionary::action('catalog.provider.update') === '更新服务商', 'catalog.provider.update label');
$assert(AuditDisplayDictionary::resource('model') === '模型', 'model resource label');
$assert(AuditDisplayDictionary::resource('provider') === '服务商', 'provider resource label');
$assert(AuditDisplayDictionary::summary('catalog.model.save', 'Saved catalogue operation receipt') === '已保存模型目录变更', 'english summary mapped');
$assert(AuditDisplayDictionary::summary('catalog.provider.update', 'Saved catalogue operation receipt') === '已更新服务商目录变更', 'provider update summary mapped');

// (9) New Chinese stored values pass through untouched.
$assert(AuditDisplayDictionary::action('保存模型') === '保存模型', 'stored chinese action passthrough');
$assert(AuditDisplayDictionary::resource('模型') === '模型', 'stored chinese resource passthrough');
$assert(AuditDisplayDictionary::summary('保存模型', '已保存模型目录变更') === '已保存模型目录变更', 'stored chinese summary passthrough');

// (10) Every enumerated action/resource entry returns Chinese.
foreach (['billing.credential.update', 'billing.review', 'billing.evidence.upload', 'billing.import.create',
    'billing.sync.no_data', 'usage.refund.record', 'pricing.revision.draft', 'platform.validation.recorded',
    'budget.policy.publish', 'budget_policy.archive', 'budget.soft.settled',
    'legal_analysis_profile.publish', 'material_analysis_profile.revoke',
    'onboarding.model.verified', 'deployment.verification.passed', 'onboarding.deployment.published',
    'capability_profile.publish', 'sand_ai.composition.publish',
    'sand_ai.skill.create_draft', 'sand_ai.skill.publish', 'sand_ai.skill.revoke', 'sand_ai.skill.rollback',
    'sand_ai.skill.case.review', 'sand_ai.skill.source_content.purge',
    'sand_ai.tool.connection.create', 'sand_ai.tool.publish', 'sand_ai.tool.run', 'sand_ai.tool.mcp.discover',
    'knowledge_base.draft', 'knowledge_base.publication.publish', 'knowledge.sources.purge',
    'knowledge.embedding.indexed', 'knowledge_conversation.close', 'knowledge_conversation.turn.cancel',
    'file.upload', 'file.delete_failed', 'file.parse', 'file.ocr_dispatch_prepared',
    'agent.queued', 'ai_task.authorization_denied', 'agent_definition.rollback',
    'task.cancel', 'task.authorization_continued', 'task.manual_review_required', 'task.succeeded',
    'workflow.queued', 'workflow.material_output_rejected', 'workflow.recovered_from_receipt',
    'chat.completion', 'model.dispatch.failed', 'model.dispatch.ambiguous', 'outcome_receipt.destroyed',
    'channel_binding.save', 'channel_wecom_credential.save', 'channel.conversation.close',
    'channel_delivery.retry', 'wecom_delivery.retry', 'audio_transcription.segment_review',
    'draft_save@1',
] as $action) {
    $label = AuditDisplayDictionary::action($action);
    $assert($label !== $action && preg_match('/\p{Han}/u', $label) === 1, 'action mapped to chinese: ' . $action);
}
foreach (['model_deployment', 'capability_profile', 'composition_definition', 'skill', 'skill_case',
    'skill_source_suggestion', 'tool', 'tool_connection', 'tool_environment_binding', 'tool_run',
    'tool_mcp_snapshot', 'task', 'task_run', 'workflow_run', 'invocation', 'file', 'file_parse',
    'knowledge_base', 'knowledge_publication', 'knowledge_conversation', 'channel_binding',
    'legal_analysis_profile', 'material_analysis_profile', 'billing_review', 'billing_evidence',
    'billing_import', 'price_revision', 'usage_adjustment', 'budget_policy', 'rerank_soft_budget',
    'admin_validation_attempt', 'audio_transcription',
] as $resource) {
    $label = AuditDisplayDictionary::resource($resource);
    $assert($label !== $resource && preg_match('/\p{Han}/u', $label) === 1, 'resource mapped to chinese: ' . $resource);
}

// (11) Derived and unknown values fall back to Chinese, never bare codes.
$assert(AuditDisplayDictionary::action('capability.embedding.completion') === '向量检索调用完成', 'capability completion derived label');
$assert(AuditDisplayDictionary::action('capability.future_capability.completion') === '模型能力调用完成', 'unknown capability fallback');
$assert(AuditDisplayDictionary::action('some.future.operation') === '未归类操作', 'unknown action fallback');
$assert(AuditDisplayDictionary::resource('future_resource') === '未归类对象', 'unknown resource fallback');
$assert(AuditDisplayDictionary::resource('') === '未记录对象', 'empty resource fallback');
$unknownSummary = AuditDisplayDictionary::summary('some.future.operation', 'Some foreign text');
$assert(preg_match('/\p{Han}/u', $unknownSummary) === 1 && !str_contains($unknownSummary, 'foreign'), 'unknown english summary fallback');
$assert(AuditDisplayDictionary::summary('task.succeeded', 'SandAI task succeeded') === '任务成功', 'known english summary mapped');
$assert(AuditDisplayDictionary::summary('x', '') === '操作已记录', 'empty summary fallback');

// (12) Chinese search terms resolve the stored codes for both old and new values.
$candidates = AuditDisplayDictionary::actionCandidates('保存模型');
$assert(in_array('catalog.model.save', $candidates, true), 'search finds english catalog code');
$assert(in_array('保存模型', $candidates, true), 'search finds chinese stored action');
$assert(in_array('catalog.provider.save', AuditDisplayDictionary::actionCandidates('保存服务商'), true), 'provider search resolves code');

// (13) New catalogue writes store Chinese in all three audit columns while the
//      legacy codes stay valid lookup keys.
$stored = new ReflectionMethod(CatalogOperationReceipt::class, 'storedAction');
$summary = new ReflectionMethod(CatalogOperationReceipt::class, 'storedSummary');
$assert($stored->invoke(null, 'model', 'save') === '保存模型', 'stored action is chinese');
$assert($summary->invoke(null, 'provider', 'save') === '已保存服务商目录变更', 'stored summary is chinese');
$assert($summary->invoke(null, 'model', 'update') === '已更新模型目录变更', 'stored update summary is chinese');
$assert(in_array('保存模型', AuditDisplayDictionary::CATALOG_ACTIONS, true)
    && in_array('catalog.model.save', AuditDisplayDictionary::CATALOG_ACTIONS, true),
    'catalog action list covers both stored forms');

echo "UX P1d zero-step i18n test passed ({$checks} checks)\n";
