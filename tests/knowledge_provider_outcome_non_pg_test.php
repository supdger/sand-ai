<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $appRoot . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptRequest;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\OutcomeReceiptStore;
use app\Application\Knowledge\KnowledgeProviderOutcome;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Knowledge provider outcome check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$facts = [
    'version' => 1, 'content' => '{"answer":"有来源的答案","citations":[31]}',
    'input_tokens' => 12, 'output_tokens' => 7, 'usage_measured' => true,
    'latency_ms' => 20,
    'attribution' => [
        'format_version' => 1, 'environment_id' => 4,
        'organization_id' => 1, 'application_id' => 2, 'workload_client_id' => 3,
        'identity_snapshot' => [
            'organization_ref' => '1', 'application_ref' => '2', 'workload_client_ref' => '3',
        ],
        'capability_code' => 'chat', 'source_kind' => 'api_credential',
        'service_action' => 'chat.invoke',
    ],
    'provider' => ['id' => 5, 'code' => 'test', 'name' => 'Test'],
    'model' => ['id' => 6, 'code' => 'test-model', 'name' => 'Test model'],
    'deployment' => ['id' => 7, 'remote_model' => 'test-model', 'revision' => 2, 'route' => [
        'selection_kind' => 'capability_profile', 'profile_id' => 8, 'profile_revision' => 2, 'route_id' => 9,
    ]],
    'provider_adapter' => 'fake',
];
$encoded = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$decoded = KnowledgeProviderOutcome::decode($encoded);
$assert($decoded->encode() === $encoded);
$unmeasured = $facts;
$unmeasured['usage_measured'] = false;
$assert(!KnowledgeProviderOutcome::decode(json_encode($unmeasured, JSON_THROW_ON_ERROR))->usageMeasured);
$reject = static function (array $candidate) use ($assert): void {
    try {
        KnowledgeProviderOutcome::decode(json_encode($candidate, JSON_THROW_ON_ERROR));
        throw new RuntimeException('Invalid provider outcome was accepted');
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_RECEIPT_INVALID');
    }
};
$invalid = $facts;
$invalid['input_tokens'] = -1;
$reject($invalid);
$invalid = $facts;
$invalid['input_tokens'] = PHP_INT_MAX;
$reject($invalid);
$invalid = $facts;
$invalid['attribution']['identity_snapshot']['application_ref'] = 'other';
$reject($invalid);
$invalid = $facts;
unset($invalid['version']);
$reject($invalid);
$invalid = $facts;
$invalid['provider_adapter'] = 'unknown';
$reject($invalid);

$store = new class implements OutcomeReceiptStore {
    public ?array $record = null;
    public function createOrGet(array $record): array { $this->record = ['state' => 'available'] + $record; return ['created' => true, 'record' => $this->record]; }
    public function findByInvocationId(int $invocationId): ?array { return $this->record; }
    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): bool { return false; }
    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool { return false; }
    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool { return false; }
};
$service = new OutcomeReceiptService($store, new OutcomeReceiptCipher([
    'current_key_id' => 'test', 'current_key' => base64_encode(str_repeat('k', 32)),
    'max_plaintext_bytes' => 262144,
]), 3600);
$context = [
    'environment_id' => 4, 'invocation_id' => 11, 'request_fingerprint' => str_repeat('a', 64),
    'capability_code' => 'knowledge_answer', 'model_id' => 6, 'deployment_id' => 7, 'version' => 'v1',
];
$assert($service->preflight('knowledge_answer', new OutcomeReceiptRequest(262144)));
$service->write($decoded->encode(), $context, 262144);
$assert(KnowledgeProviderOutcome::decode($service->replay($context))->content === $facts['content']);
echo 'knowledge-provider-outcome ' . $checks . '/' . $checks . PHP_EOL;
