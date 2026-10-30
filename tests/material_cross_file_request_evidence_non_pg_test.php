<?php

declare(strict_types=1);

$root = getenv('SANDPACKAGE_TEST_ROOT') ?: dirname(__DIR__);
foreach ([
    'Api/Support/ApiProblem.php',
    'Application/Usage/UsageAttribution.php',
    'Application/Gateway/ChatCompletionOptions.php',
    'Application/Gateway/ChatCompletionOptionsPolicy.php',
    'Application/Gateway/RequestIdempotencyKey.php',
    'Application/Gateway/RequestPayloadFingerprint.php',
    'Application/Gateway/CanonicalProviderRequest.php',
    'Application/Gateway/ProviderRequestBodyBuilder.php',
] as $file) {
    require $root . '/app/' . $file;
}
$candidate = __DIR__ . '/app/Application/Workflow/MaterialCrossFileReviewRequestEvidence.php';
require is_file($candidate) ? $candidate : $root . '/app/Application/Workflow/MaterialCrossFileReviewRequestEvidence.php';

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Application\Workflow\MaterialCrossFileReviewRequestEvidence;

$attribution = new UsageAttribution(
    1, 2, 3, 4,
    ['organization_ref' => '2', 'application_ref' => '3', 'workload_client_ref' => '4'],
    'structured_workflow', 'sand_ai.workflow.run', 'structured_extraction',
);
$messages = [['role' => 'system', 'content' => 'Review'], ['role' => 'user', 'content' => 'Two sealed files']];
$route = [
    'model_id' => 9, 'deployment_id' => 10, 'provider_id' => 11,
    'model_code' => 'review-model', 'deployment_remote_model' => 'remote-model',
    'provider_adapter' => 'openai_compatible',
];
$fingerprint = RequestPayloadFingerprint::fingerprint([
    'environment_id' => 1,
    'trusted_identity_scope' => RequestIdempotencyKey::trustedScopeForAttribution($attribution),
    'model' => 'review-model', 'messages' => $messages,
    'max_output_tokens' => 1024,
    'capability_code' => 'structured_extraction',
    'capability_fingerprint_version' => 1,
], []);
$digest = ProviderRequestBodyBuilder::digest([
    'model' => 'remote-model', 'messages' => $messages,
    'stream' => false, 'max_completion_tokens' => 1024,
]);
$checks = 0;
MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $route, 1024, 'max_completion_tokens', $messages, $fingerprint, $digest,
);
++ $checks;
$reject = static function (callable $callback) use (&$checks): void {
    try {
        $callback();
    } catch (ApiProblem $problem) {
        if ($problem->errorCode !== 'SAND_AI_ARTIFACT_PROVENANCE_INVALID') {
            throw $problem;
        }
        ++$checks;
        return;
    }
    throw new RuntimeException('Invalid review request evidence was accepted');
};
$reject(static fn () => MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $route, 2048, 'max_completion_tokens', $messages, $fingerprint, $digest,
));
$reject(static fn () => MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $route, 1024, 'max_tokens', $messages, $fingerprint, $digest,
));
$reject(static fn () => MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $route, 1024, 'max_completion_tokens', $messages, str_repeat('0', 64), $digest,
));
$fake = $route;
$fake['provider_adapter'] = 'fake';
$reject(static fn () => MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $fake, 1024, 'max_completion_tokens', $messages, $fingerprint, $digest,
));
$fakeDigest = ProviderRequestBodyBuilder::digest([
    'model' => 'remote-model', 'messages' => $messages, 'stream' => false, 'max_tokens' => 1024,
]);
MaterialCrossFileReviewRequestEvidence::assert(
    1, $attribution, 9, 10, $fake, 1024, 'max_tokens', $messages, $fingerprint, $fakeDigest,
);
++ $checks;
echo "cross-file-request-evidence $checks/$checks\n";
