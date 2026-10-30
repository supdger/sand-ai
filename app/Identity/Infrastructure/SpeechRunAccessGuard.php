<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use support\Request;

final class SpeechRunAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, int $runId, string $action, string $requestId): array
    {
        $decision = $this->host()->authorize(
            $request, $identity, $action, 'sand_ai.speech_run', (string) $runId,
            'speech-run:' . bin2hex(random_bytes(16)), $requestId,
        );
        if (!array_key_exists('data_class', $decision) || $decision['data_class'] !== null) self::unverified();
        return $decision;
    }

    /** @param array<string,mixed> $decision */
    public function revalidate(Request $request, IdentityContext $identity, int $runId, string $action, string $requestId, array $decision): void
    {
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) $signedContext = trim(substr($signedContext, 7));
        $verified = $this->host()->revalidate(
            (int) ($decision['authorization_id'] ?? 0),
            $signedContext,
            $identity, $action, 'sand_ai.speech_run', (string) $runId,
            $request->getRemoteIp(), $requestId,
        );
        if (!array_key_exists('data_class', $verified) || $verified['data_class'] !== null) self::unverified();
    }

    private function host(): HostSandIamInvocationAuthorizer
    {
        return $this->authorizer ?? new HostSandIamInvocationAuthorizer();
    }

    private static function unverified(): never
    {
        throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
    }
}
