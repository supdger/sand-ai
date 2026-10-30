<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ChatCompletionOptionsPolicy;
use app\Application\Gateway\ChatCompletionOptions;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\ConfigRevision;

/** Recomputes the two hashes produced by ChatGateway from frozen review options. */
final class MaterialCrossFileReviewRequestEvidence
{
    /**
     * @param array<string,mixed> $route Frozen dispatch route.
     * @param list<array{role:string,content:string}> $messages
     */
    public static function assert(
        int $environmentId,
        UsageAttribution $attribution,
        int $modelId,
        int $deploymentId,
        array $route,
        int $maxOutputTokens,
        string $outputTokenParameter,
        array $messages,
        string $requestFingerprint,
        string $requestBodyDigest,
        ?MaterialReviewOutputContract $output = null,
    ): void {
        if ($environmentId < 1 || $attribution->environmentId !== $environmentId
            || $modelId < 1 || $deploymentId < 1
            || (int) ($route['model_id'] ?? 0) !== $modelId
            || (int) ($route['deployment_id'] ?? 0) !== $deploymentId
            || !is_int($route['provider_id'] ?? null) || $route['provider_id'] < 1
            || !is_string($route['model_code'] ?? null) || trim($route['model_code']) === ''
            || !is_string($route['deployment_remote_model'] ?? null) || trim($route['deployment_remote_model']) === ''
            || !in_array($route['provider_adapter'] ?? null, ['fake', 'openai_compatible'], true)
            || !in_array($outputTokenParameter, ['max_tokens', 'max_completion_tokens'], true)
            || ($route['provider_adapter'] === 'fake' && $outputTokenParameter !== 'max_tokens')
            || $maxOutputTokens < 1 || $maxOutputTokens > ChatCompletionOptionsPolicy::MAX_OUTPUT_TOKENS) {
            self::invalid();
        }
        $options = ChatCompletionOptionsPolicy::normalize($maxOutputTokens);
        $fingerprintFields = [
            'environment_id' => $environmentId,
            'trusted_identity_scope' => RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            'model' => $route['model_code'],
            'messages' => $messages,
            'max_output_tokens' => $options->maxOutputTokens,
            'capability_code' => 'structured_extraction',
            'capability_fingerprint_version' => 1,
        ];
        if ($output !== null) $fingerprintFields['skill_output'] = $output->fingerprint();
        $fingerprint = RequestPayloadFingerprint::fingerprint($fingerprintFields, []);
        $reasoningEffort = null;
        if ($route['provider_adapter'] === 'openai_compatible') {
            $revisionId = $route['config_revision_id'] ?? null;
            if (!is_int($revisionId) || $revisionId < 1) {
                self::invalid();
            }
            $revision = ConfigRevision::where('id', $revisionId)->find();
            if ($revision === null) {
                self::invalid();
            }
            try {
                // Historical dispatches without either frozen execution
                // snapshot can prove omission only through their saved body
                // digest below; this path never prepares a Provider send.
                if (!DispatchRouteSnapshot::legacyRevisionWithoutExecutionSnapshot($route, $revision)) {
                    $reasoningEffort = DispatchRouteSnapshot::reasoningEffortFromRevision($route, $revision);
                }
            } catch (ApiProblem) {
                self::invalid();
            }
        }
        $body = ProviderRequestBodyBuilder::chat(
            $route['deployment_remote_model'], $messages,
            new ChatCompletionOptions($options->maxOutputTokens, $reasoningEffort, $output),
            $outputTokenParameter,
        );
        if (!hash_equals($requestFingerprint, $fingerprint)
            || !hash_equals($requestBodyDigest, ProviderRequestBodyBuilder::digest($body))) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_ARTIFACT_PROVENANCE_INVALID', 'Cross-file review request evidence is invalid');
    }
}
