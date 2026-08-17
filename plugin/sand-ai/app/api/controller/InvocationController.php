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

final class InvocationController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function read(Request $request, string $requestId): Response
    {
        $traceId = trim((string) $request->header('x-request-id', '')) ?: $requestId;
        try {
            $context = $this->identityContexts->requireContext($request, 'sand_ai.invocation.read')->requireAction('sand_ai.invocation.read');
            return ApiResponder::success((new ChatGateway())->invocation($context->environmentId, $this->requestId($requestId)), $traceId);
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $traceId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $traceId);
        }
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if ($requestId === '' || strlen($requestId) > 96) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid invocation request id is required');
        }

        return $requestId;
    }
}
