<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;

final readonly class SpeechExecutionTarget
{
    public function __construct(
        public int $providerId,
        public int $modelId,
        public int $deploymentId,
        public int $profileId,
        public int $profileRevision,
        public int $routeId,
        public string $credentialDigest,
    ) {
        if ($providerId < 1 || $modelId < 1 || $deploymentId < 1 || $profileId < 1
            || $profileRevision < 1 || $routeId < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $credentialDigest) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Speech execution target is invalid');
        }
    }

    /** @return array<string,int|string> */
    public function snapshot(): array
    {
        return [
            'provider_id' => $this->providerId,
            'model_id' => $this->modelId,
            'deployment_id' => $this->deploymentId,
            'profile_id' => $this->profileId,
            'profile_revision' => $this->profileRevision,
            'route_id' => $this->routeId,
            'credential_digest' => $this->credentialDigest,
        ];
    }
}
