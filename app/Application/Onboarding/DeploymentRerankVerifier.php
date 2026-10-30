<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderDispatchContext;
use app\Application\Gateway\ProviderRequestBodyBuilder;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Knowledge\ProfessionalRerankOutcome;
use app\Infrastructure\Provider\NeuronProfessionalRerankProvider;

/** Sends fixed non-sensitive samples through the actual professional SDK provider. */
final class DeploymentRerankVerifier
{
    private const QUERY = 'Which city is the capital of France?';
    private const CANDIDATES = [
        ['source_block_id' => 1, 'excerpt' => 'Paris is the capital of France.'],
        ['source_block_id' => 2, 'excerpt' => 'Berlin is the capital of Germany.'],
    ];

    public function __construct(private readonly NeuronProfessionalRerankProvider $provider = new NeuronProfessionalRerankProvider())
    {
    }

    /**
     * The caller owns the persisted verification claim, authorization and budget gate.
     * No synthetic token counts are assigned to professional rerank metering.
     * @param array<string,mixed> $config Decrypted saved configuration, never a temporary key.
     * @return array{usage_measured:bool,input_tokens:null,output_tokens:null,rerank_meter_code:string,rerank_quantity:?int}
     */
    public function verify(
        string $driver,
        array $config,
        string $remoteModel,
        int $timeoutMs,
        ProviderSendBoundary $boundary,
        string $attemptId,
    ): array {
        if (preg_match('/\A[a-f0-9]{32}\z/', $attemptId) !== 1 || $boundary->started()) {
            throw new ApiProblem('SAND_AI_DISPATCH_STATE_INVALID', 'Professional rerank verification claim is invalid');
        }
        $prepared = null;
        try {
            $body = NeuronProfessionalRerankProvider::requestBody(
                $driver, $remoteModel, self::QUERY, self::CANDIDATES,
            );
            $dispatch = new ProviderDispatchContext(
                substr(hash('sha256', 'sand-ai-rerank-verification-execution@1:' . $attemptId), 0, 48),
                ProviderRequestBodyBuilder::digest($body),
                hash('sha256', 'sand-ai-rerank-verification-provider-key@1:' . $attemptId),
            );
            $prepared = $this->provider->prepare(
                $driver, $config, $remoteModel, self::QUERY, self::CANDIDATES, $timeoutMs, $dispatch,
            );
            // Only the actual bounded transport marks the supplied boundary as started.
            $outcome = ($prepared['send'])($boundary);
            return [
                'usage_measured' => $outcome->usageMeasured,
                'input_tokens' => null,
                'output_tokens' => null,
                'rerank_meter_code' => $outcome->meteringUnit,
                'rerank_quantity' => $outcome->meteringQuantity,
            ];
        } catch (ApiProblem $problem) {
            throw new RerankVerificationFailure($problem, $this->evidence($prepared));
        } catch (\Throwable) {
            throw new RerankVerificationFailure(
                new ApiProblem('SAND_AI_DEPLOYMENT_VERIFICATION_AMBIGUOUS', 'Professional rerank verification outcome is uncertain', true),
                $this->evidence($prepared),
            );
        }
    }

    /** @param array<string,mixed>|null $prepared */
    private function evidence(?array $prepared): ?ProfessionalRerankOutcome
    {
        return $prepared === null ? null : ($prepared['usage_evidence'])();
    }
}
