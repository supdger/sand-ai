<?php

declare(strict_types=1);

namespace app\Infrastructure\Persistence\Model {
    final class TaskRun
    {
        /** @param array<string,mixed> $input */
        public function __construct(
            public int $id,
            public int $environment_id,
            public string $task_type,
            public string $resource_type,
            public int $resource_id,
            public array $input,
        ) {
        }
    }
}

namespace {
    $vendor = getenv('SANDPACKAGE_TEST_VENDOR');
    if (!is_string($vendor) || !is_file($vendor)) {
        throw new \RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
    }
    require $vendor;
    $app = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($app): void {
        if (!str_starts_with($class, 'app\\')) {
            return;
        }
        $path = $app . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }, true, true);

    use app\Api\Support\ApiProblem;
    use app\Application\Usage\UsageAttribution;
    use app\Identity\Contract\IdentityContext;
    use app\Identity\Infrastructure\AudioTaskAccessGuard;
    use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
    use app\Identity\Infrastructure\QueuedSandIamContextCipher;
    use app\Infrastructure\Persistence\Model\TaskRun;

    $checks = 0;
    $assert = static function (bool $condition) use (&$checks): void {
        if (!$condition) {
            throw new \RuntimeException('Audio queued authorization failed at check ' . ($checks + 1));
        }
        ++$checks;
    };
    $reject = static function (callable $run, string $code) use ($assert): void {
        try {
            $run();
        } catch (ApiProblem $problem) {
            $assert($problem->errorCode === $code);
            return;
        }
        throw new \RuntimeException('Audio queued authorization did not reject ' . $code);
    };

    $expiresAt = time() + 120;
    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.audio.transcription'], $expiresAt);
    $decision = static function (int $authorizationId, string $operationId): array {
        return [
            'authorization_id' => $authorizationId,
            'grant_id' => 55,
            'context_id' => 'audio-context',
            'replayed' => false,
            'data_class' => 'confidential',
            'operation_id' => $operationId,
            'service_code' => 'sand_ai',
            'audience' => 'sand-ai',
            'action_code' => 'sand_ai.audio.transcription',
            'organization_id' => 11,
            'application_id' => 22,
            'environment_id' => 33,
            'workload_client_id' => 44,
        ];
    };
    $host = new class($decision) {
        public int $authorizations = 0;
        public int $revalidations = 0;
        public bool $revoked = false;

        public function __construct(private readonly \Closure $decision)
        {
        }

        public function authorizeInvocation(
            string $context, string $service, string $audience, string $action,
            string $resolver, string $resource, string $sourceIp, string $operationId, string $requestId,
        ): array {
            if ($context !== 'signed-audio-context' || $service !== 'sand_ai' || $audience !== 'sand-ai'
                || $action !== 'sand_ai.audio.transcription' || $resolver !== 'sand_ai.file'
                || $resource !== '51' || $sourceIp !== '127.0.0.1' || $requestId !== 'audio-request-1234') {
                throw new \RuntimeException('Unexpected audio authorization arguments');
            }
            ++$this->authorizations;
            return ($this->decision)(71, $operationId);
        }

        public function revalidateInvocation(
            int $authorizationId, string $context, string $service, string $audience,
            string $action, string $resolver, string $resource, string $sourceIp, string $requestId,
        ): array {
            ++$this->revalidations;
            if ($this->revoked) {
                throw new \RuntimeException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: revoked');
            }
            if ($authorizationId !== 71 || $context !== 'signed-audio-context'
                || $service !== 'sand_ai' || $audience !== 'sand-ai'
                || $action !== 'sand_ai.audio.transcription' || $resolver !== 'sand_ai.file'
                || $resource !== '51' || $sourceIp !== '127.0.0.1' || $requestId !== 'audio-request-1234') {
                throw new \RuntimeException('Unexpected queued audio authorization arguments');
            }
            return ($this->decision)(71, 'audio-transcription:original');
        }
    };
    $request = new class("POST /api/sand-ai/v1/audio-transcriptions HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer signed-audio-context\r\nX-Request-ID: audio-request-1234\r\n\r\n") extends \support\Request {
        public function getRemoteIp(): string
        {
            return '127.0.0.1';
        }
    };
    $cipher = new QueuedSandIamContextCipher([
        'current_key_id' => 'test',
        'current_key' => base64_encode(random_bytes(32)),
    ]);
    $guard = new AudioTaskAccessGuard(new HostSandIamInvocationAuthorizer($host), $cipher);
    $fileRef = 'saf_1234567890abcdef';
    $authorization = $guard->authorize($request, $identity, 51, $fileRef, 'audio-request-1234');
    $assert($host->authorizations === 1 && $authorization['authorization_id'] === 71);
    $assert(!str_contains(json_encode($authorization, JSON_THROW_ON_ERROR), 'signed-audio-context'));

    $input = [
        'request' => ['file_ref' => $fileRef],
        'file_id' => 51,
        'usage_attribution' => UsageAttribution::fromIdentity(
            $identity, 'capability', 'sand_ai.audio.transcription', 'audio_transcription',
        )->toPersisted(),
    ];
    $task = new TaskRun(77, 33, 'audio_transcription', 'audio_transcription', 77, $input);
    $guard->revalidateQueued($authorization, $task);
    $assert($host->revalidations === 1);

    $changedRef = new TaskRun(77, 33, 'audio_transcription', 'audio_transcription', 77, [
        ...$input, 'request' => ['file_ref' => 'saf_ffffffffffffffff'],
    ]);
    $reject(static fn () => $guard->revalidateQueued($authorization, $changedRef), 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    $assert($host->revalidations === 1);

    $changedFile = new TaskRun(77, 33, 'audio_transcription', 'audio_transcription', 77, [...$input, 'file_id' => 52]);
    $reject(static fn () => $guard->revalidateQueued($authorization, $changedFile), 'SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE');
    $assert($host->revalidations === 1);

    $expired = [...$authorization, 'expires_at' => time() - 1];
    $reject(static fn () => $guard->revalidateQueued($expired, $task), 'SAND_AI_IDENTITY_CONTEXT_EXPIRED');
    $assert($host->revalidations === 1);

    $host->revoked = true;
    $reject(static fn () => $guard->revalidateQueued($authorization, $task), 'SAND_AI_SERVICE_ACTION_FORBIDDEN');
    $assert($host->revalidations === 2);

    echo 'audio-queued-auth ' . $checks . '/' . $checks . PHP_EOL;
}
