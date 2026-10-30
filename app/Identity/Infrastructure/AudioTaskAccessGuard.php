<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;

/** Formal SandIAM authorization for a private audio file and its queued task. */
final class AudioTaskAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?QueuedSandIamContextCipher $cipher = null,
    ) {
    }

    /** @return array<string,mixed> Encrypted task input, never an API field. */
    public function authorize(Request $request, IdentityContext $identity, int $fileId, string $fileRef, string $requestId): array
    {
        $cipher = $this->cipher();
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request, $identity, 'sand_ai.audio.transcription', 'sand_ai.file', (string) $fileId,
            'audio-transcription:' . bin2hex(random_bytes(16)), $requestId,
        );
        $binding = new QueuedSandIamAudioBinding(
            $fileId, $fileRef, $decision['authorization_id'], $request->getRemoteIp(), $requestId,
            $identity->expiresAt ?? 0,
        );
        $header = trim((string) $request->header('authorization', ''));
        return [
            'version' => 1,
            'file_id' => $fileId,
            'authorization_id' => $binding->authorizationId,
            'source_ip' => $binding->sourceIp,
            'request_id' => $binding->requestId,
            'expires_at' => $binding->expiresAt,
            'context' => $cipher->seal(trim(substr($header, 7)), $binding),
        ];
    }

    /** @param array<string,mixed> $authorization */
    public function revalidateQueued(array $authorization, TaskRun $task): void
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $request = is_array($input) ? ($input['request'] ?? null) : null;
        if (!is_array($input) || !is_array($request)
            || (string) $task->task_type !== 'audio_transcription'
            || (string) $task->resource_type !== 'audio_transcription'
            || (int) $task->resource_id !== (int) $task->id
            || !is_int($input['file_id'] ?? null) || $input['file_id'] < 1
            || !is_string($request['file_ref'] ?? null)
            || ($authorization['version'] ?? null) !== 1
            || ($authorization['file_id'] ?? null) !== $input['file_id']
            || !is_int($authorization['authorization_id'] ?? null)
            || !is_string($authorization['source_ip'] ?? null)
            || !is_string($authorization['request_id'] ?? null)
            || !is_int($authorization['expires_at'] ?? null)
            || !is_array($authorization['context'] ?? null)) {
            self::unavailable();
        }
        $binding = new QueuedSandIamAudioBinding(
            $input['file_id'], $request['file_ref'], $authorization['authorization_id'],
            $authorization['source_ip'], $authorization['request_id'], $authorization['expires_at'],
        );
        $signedContext = $this->cipher()->open($authorization['context'], $binding);
        try {
            $owner = UsageAttribution::fromCurrentPersisted(
                is_array($input['usage_attribution'] ?? null) ? $input['usage_attribution'] : null,
                (int) $task->environment_id, 'capability', 'sand_ai.audio.transcription',
            );
        } catch (\InvalidArgumentException) {
            self::unavailable();
        }
        if ($owner->organizationId === null || $owner->applicationId === null || $owner->workloadClientId === null) {
            self::unavailable();
        }
        $identity = new IdentityContext(
            (string) $owner->organizationId, (string) $owner->applicationId,
            (int) $task->environment_id, (string) $owner->workloadClientId,
            'sand-ai', ['sand_ai.audio.transcription'], $binding->expiresAt,
        );
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            $binding->authorizationId, $signedContext, $identity,
            'sand_ai.audio.transcription', 'sand_ai.file', (string) $binding->fileId,
            $binding->sourceIp, $binding->requestId,
        );
    }

    private function cipher(): QueuedSandIamContextCipher
    {
        return $this->cipher ?? new QueuedSandIamContextCipher((array) config('sand_ai_iam_context', []));
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued audio identity context is unavailable');
    }
}
