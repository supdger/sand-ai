<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\api\support\ApiResponder;
use plugin\SandAi\app\api\support\IdentityContextProblem;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\domain\gateway\ChatGateway;
use support\Request;
use support\Response;

final class ModelsController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function index(Request $request): Response
    {
        $requestId = trim((string) $request->header('x-request-id', '')) ?: 'req_' . bin2hex(random_bytes(12));
        try {
            $context = $this->identityContexts->requireContext($request, 'sand_ai.model.read')->requireAction('sand_ai.model.read');
            return ApiResponder::success(['data' => (new ChatGateway())->availableModels($context->environmentId)], $requestId);
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
