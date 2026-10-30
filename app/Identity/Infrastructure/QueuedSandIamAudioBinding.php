<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;

/** Binds queued audio authorization to the exact private file reference. */
final readonly class QueuedSandIamAudioBinding implements QueuedSandIamContextAad
{
    public function __construct(
        public int $fileId,
        public string $fileRef,
        public int $authorizationId,
        public string $sourceIp,
        public string $requestId,
        public int $expiresAt,
    ) {
        if ($fileId < 1 || preg_match('/\Asaf_[A-Za-z0-9_-]{16,120}\z/D', $fileRef) !== 1
            || $authorizationId < 1
            || filter_var($sourceIp, FILTER_VALIDATE_IP) === false
            || $sourceIp === '0.0.0.0' || $sourceIp === '::'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,95}$/D', $requestId) !== 1
            || $expiresAt <= 0) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued audio identity context is invalid');
        }
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function aad(): string
    {
        return json_encode([
            'version' => 1,
            'service_code' => 'sand_ai',
            'audience' => 'sand-ai',
            'action_code' => 'sand_ai.audio.transcription',
            'resolver_code' => 'sand_ai.file',
            'file_id' => $this->fileId,
            'file_ref' => $this->fileRef,
            'authorization_id' => $this->authorizationId,
            'source_ip' => $this->sourceIp,
            'request_id' => $this->requestId,
            'expires_at' => $this->expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
