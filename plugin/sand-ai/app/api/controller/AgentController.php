<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\api\support\ApiResponder;
use plugin\SandAi\app\api\support\IdentityContextProblem;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\domain\agent\AgentGateway;
use support\Request;
use support\Response;

final class AgentController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function submit(Request $request): Response
    {
        $requestId = trim((string) $request->header('x-request-id', '')) ?: 'req_' . bin2hex(random_bytes(12));
        try {
            $context = $this->identityContexts->requireContext($request, 'sand_ai.agent.run')->requireAction('sand_ai.agent.run');
            return ApiResponder::success((new AgentGateway())->submit($context, $request->post(), $requestId), $requestId);
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
