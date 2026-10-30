<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\ChatGateway;
use app\Identity\Infrastructure\ModelCatalogAccessGuard;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

final class ModelsController
{
    public function index(Request $request): Response
    {
        $requestId = $this->requestId($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.model.read');
            (new ModelCatalogAccessGuard())->authorize($request, $context['context'], $requestId);
            return ApiResponder::success(['data' => (new ChatGateway())->availableModels($context['environment_id'])], $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }

    private function requestId(Request $request): string
    {
        return RequestAuditId::ensure($request);
    }
}
