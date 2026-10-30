<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Application\Retrieval\RetrievalGateway;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\RetrievalSourceAccessGuard;
use app\Identity\Infrastructure\RetrievalSearchAccessGuard;
use support\Request;
use support\Response;

final class RetrievalController
{
    public function search(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.retrieval.search');
            $attribution = UsageAttribution::fromIdentity(
                $context['context'], 'retrieval', 'sand_ai.retrieval.search', 'retrieval',
            );
            $entryGuard = new RetrievalSearchAccessGuard();
            $result = (new RetrievalGateway())->search(
                $context['environment_id'], $request->post(),
                RequestIdempotencyKey::trustedScopeForAttribution($attribution),
            );
            $sourceGuard = new RetrievalSourceAccessGuard();
            $sourceDecisions = $sourceGuard->authorize(
                $request, $context['context'], $attribution, $result['matches'], $requestId,
            );
            $sourceGuard->revalidate(
                $request, $context['context'], $attribution, $result['matches'], $requestId, $sourceDecisions,
            );
            $entryGuard->verifyCurrent($request, $context['context']);
            return ApiResponder::success($result, $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
