<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContext;
use support\Request;

/** Checks the publication grant again before private source excerpts leave the service. */
final class KnowledgePublicationAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /** @return array<string,mixed> */
    public function authorize(Request $request, IdentityContext $identity, int $publicationId, int $clientId, string $requestId, ?int $versionId = null): array
    {
        return $this->host()->authorize(
            $request, $identity, 'sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
            $publicationId . ':' . $clientId . ($versionId === null ? '' : ':' . $versionId), 'knowledge-query:' . bin2hex(random_bytes(16)), $requestId,
        );
    }

    /** @param array<string,mixed> $decision */
    public function revalidate(Request $request, IdentityContext $identity, int $publicationId, int $clientId, string $requestId, array $decision, ?int $versionId = null): void
    {
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) $signedContext = trim(substr($signedContext, 7));
        $this->host()->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity,
            'sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
            $publicationId . ':' . $clientId . ($versionId === null ? '' : ':' . $versionId), $request->getRemoteIp(), $requestId,
        );
    }

    private function host(): HostSandIamInvocationAuthorizer
    {
        return $this->authorizer ?? new HostSandIamInvocationAuthorizer();
    }
}
