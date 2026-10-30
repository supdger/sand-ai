<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Gateway/OutcomeReceiptStore.php',
    'Application/Gateway/OutcomeReceiptCipher.php',
    'Application/Gateway/OutcomeReceiptService.php',
] as $path) {
    $class = 'app\\' . str_replace('/', '\\', substr($path, 0, -4));
    if (!class_exists($class) && !interface_exists($class)) {
        require $root . '/app/' . $path;
    }
}

use app\Api\Support\ApiProblem;
use app\Application\Gateway\OutcomeReceiptCipher;
use app\Application\Gateway\OutcomeReceiptService;
use app\Application\Gateway\OutcomeReceiptStore;

$store = new class implements OutcomeReceiptStore {
    public bool $allowed = true;
    public ?array $lastAcknowledgement = null;

    public function createOrGet(array $record): array
    {
        throw new RuntimeException('Unexpected receipt write');
    }

    public function findByInvocationId(int $invocationId): ?array
    {
        throw new RuntimeException('Unexpected receipt lookup');
    }

    public function acknowledgeArtifact(int $invocationId, int $artifactId, string $acknowledgedAt, string $retainUntil): bool
    {
        throw new RuntimeException('File result used artifact acknowledgement');
    }

    public function acknowledgeFileResult(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool
    {
        $this->lastAcknowledgement = ['file_result', $invocationId, $resultId, $acknowledgedAt, $retainUntil];
        return $this->allowed;
    }

    public function acknowledgeCrossFileReview(int $invocationId, int $resultId, string $acknowledgedAt, string $retainUntil): bool
    {
        $this->lastAcknowledgement = ['cross_file_review', $invocationId, $resultId, $acknowledgedAt, $retainUntil];
        return $this->allowed;
    }
};
$cipher = new OutcomeReceiptCipher([
    'current_key_id' => 'test',
    'current_key' => base64_encode(str_repeat('x', 32)),
]);
$service = new OutcomeReceiptService($store, $cipher, 60);
$service->acknowledgeFileResult(5, 7, '2026-09-25 10:00:00', '2026-09-26 10:00:00');
if ($store->lastAcknowledgement !== ['file_result', 5, 7, '2026-09-25 10:00:00', '2026-09-26 10:00:00']) {
    throw new RuntimeException('File result acknowledgement lost its identity');
}
$checks = 1;
$service->acknowledgeCrossFileReview(5, 8, '2026-09-25 10:00:00', '2026-09-26 10:00:00');
if ($store->lastAcknowledgement !== ['cross_file_review', 5, 8, '2026-09-25 10:00:00', '2026-09-26 10:00:00']) {
    throw new RuntimeException('Cross-file review acknowledgement lost its identity');
}
++ $checks;
$reject = static function (callable $call) use (&$checks): void {
    try {
        $call();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_RECEIPT_INVALID') {
            throw $problem;
        }
        ++ $checks;
        return;
    }
    throw new RuntimeException('Invalid file result acknowledgement was accepted');
};
$reject(static fn () => $service->acknowledgeFileResult(5, 7, '2026-09-25 10:00:00', '2026-09-25 09:00:00'));
$reject(static fn () => $service->acknowledgeCrossFileReview(5, 8, '2026-09-25 10:00:00', '2026-09-25 09:00:00'));
$store->allowed = false;
$reject(static fn () => $service->acknowledgeFileResult(5, 7, '2026-09-25 10:00:00', '2026-09-26 10:00:00'));
$reject(static fn () => $service->acknowledgeCrossFileReview(5, 8, '2026-09-25 10:00:00', '2026-09-26 10:00:00'));
echo 'material-file-receipt ' . $checks . '/' . $checks . PHP_EOL;
