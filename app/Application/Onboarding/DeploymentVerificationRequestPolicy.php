<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;

/** Validates and fingerprints the request boundary without persisting its raw key. */
final class DeploymentVerificationRequestPolicy
{
    /** @return array{idempotency_hmac:string,request_sha256:string} */
    public function fingerprint(int $deploymentId, int $revision, string $processingLocation, string $idempotencyKey): array
    {
        $hmac = $this->keyHmac($idempotencyKey);
        $canonical = json_encode([
            'deployment_id' => $deploymentId,
            'revision' => $revision,
            'processing_location' => $processingLocation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return [
            'idempotency_hmac' => $hmac,
            'request_sha256' => hash('sha256', $canonical),
        ];
    }

    /** Shared server-key boundary; no fabricated target is needed by temporary verification. */
    public function keyHmac(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128 || preg_match('/[\r\n]/', $idempotencyKey) === 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Idempotency-Key is required and must not exceed 128 characters');
        }
        $configKey = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($configKey) < 32) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration key is unavailable');
        $key = hash_hmac('sha256', 'sand-ai-deployment-verification-idempotency@1', $configKey, true);
        return hash_hmac('sha256', $idempotencyKey, $key);
    }
}
