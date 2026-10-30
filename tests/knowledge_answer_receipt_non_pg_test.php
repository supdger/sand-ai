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
use app\Application\Knowledge\KnowledgeAnswerPolicy;

$store = new class implements OutcomeReceiptStore {
    public ?array $record = null;
    public function createOrGet(array $record): array
    {
        if ($this->record !== null) return ['created' => false, 'record' => $this->record];
        $this->record = ['state' => 'available'] + $record;
        return ['created' => true, 'record' => $this->record];
    }
    public function findByInvocationId(int $invocationId): ?array
    {
        return $this->record !== null && $this->record['invocation_id'] === $invocationId ? $this->record : null;
    }
    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): bool { return false; }
    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool { return false; }
    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool { return false; }
};
$service = new OutcomeReceiptService($store, new OutcomeReceiptCipher([
    'current_key_id' => 'test',
    'current_key' => base64_encode(str_repeat('k', 32)),
    'max_plaintext_bytes' => 262144,
]), 86400);
$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Knowledge answer receipt check ' . ($checks + 1) . ' failed');
    ++$checks;
};
$assert($service->preflight('knowledge_answer', new OutcomeReceiptRequest(262144)));
try {
    $service->preflight('chat', new OutcomeReceiptRequest(262144));
    throw new RuntimeException('Public chat receipt accepted');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_RECEIPT_FORBIDDEN');
}
$context = [
    'environment_id' => 4, 'invocation_id' => 9,
    'request_fingerprint' => str_repeat('a', 64), 'capability_code' => 'knowledge_answer',
    'model_id' => 12, 'deployment_id' => 15, 'version' => 'v1',
];
$content = '{"answer":"来源支持的回答","citations":[31]}';
$service->write($content, $context, 262144);
$assert($service->replay($context) === $content);
$answer = KnowledgeAnswerPolicy::result($service->replay($context), [['source_block_id' => 31, 'excerpt' => '来源']]);
$assert($answer['answer_state'] === 'answered' && $answer['answer'] === '来源支持的回答');
try {
    $service->replay([...$context, 'request_fingerprint' => str_repeat('b', 64)]);
    throw new RuntimeException('Different request replayed');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_RECEIPT_INVALID');
}
echo 'knowledge-answer-receipt ' . $checks . '/' . $checks . PHP_EOL;
