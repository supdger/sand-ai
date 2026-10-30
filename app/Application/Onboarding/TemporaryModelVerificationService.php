<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Usage\AdminValidationUsage;
use app\Infrastructure\Provider\MeasuredChatFailure;

/** Records real unregistered-target inference without inventing a provider or price. */
final class TemporaryModelVerificationService
{
    public function verify(OpenAiCompatibleOnboardingClient $client, array $connection, string $remoteModel, int $maxOutput, ?int $inputCeiling, string $key, int $actor, ?string $requestId): array
    {
        $meter = new AdminValidationUsage();
        $request = $meter->temporaryRequest($connection, $remoteModel, $maxOutput, $inputCeiling, $key);
        $attempt = $meter->beginTemporary($request, $actor);
        if ($attempt['replay']) return $meter->temporaryReplay($attempt['id']);
        $boundary = new ProviderSendBoundary();
        try {
            $verification = $client->verifyNormalized($connection, $remoteModel, $maxOutput, $inputCeiling, $boundary);
        } catch (MeasuredChatFailure $failure) {
            $problem = $failure->problem ?? new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned no usable completion', true);
            $meter->receipt($attempt['id'], 'response_known', ['usage_measured' => true, 'input_tokens' => $failure->inputTokens, 'output_tokens' => $failure->outputTokens], $problem->errorCode);
            throw $problem;
        } catch (ApiProblem $problem) {
            $meter->receipt($attempt['id'], $boundary->started() ? 'ambiguous' : 'not_dispatched', errorCode: $problem->errorCode);
            throw $problem;
        } catch (\Throwable) {
            $meter->receipt($attempt['id'], $boundary->started() ? 'ambiguous' : 'not_dispatched', errorCode: 'SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS');
            throw new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Model verification outcome cannot be confirmed; do not resend this key', true);
        }
        // This commit survives any later encryption/configuration/draft failure.
        $platform = $meter->receipt($attempt['id'], 'response_known', $verification);
        $draft = (new VerifiedModelDraftService())->create($connection, $remoteModel, $requestId, $verification, (string) $actor, $maxOutput, $inputCeiling, $attempt['id']);
        return ['state' => 'tested', 'usage_measured' => $verification['usage_measured'],
            'usage' => ['input_tokens' => $verification['input_tokens'], 'output_tokens' => $verification['output_tokens']],
            'cost_tracking_state' => 'recorded', 'draft' => $draft, 'platform_usage' => $platform, 'idempotent_replay' => false];
    }
}
