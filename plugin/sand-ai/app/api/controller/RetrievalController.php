<?php

declare(strict_types=1);

namespace plugin\SandAi\app\api\controller;

use plugin\SandAi\app\api\support\ApiProblem;
use plugin\SandAi\app\api\support\ApiResponder;
use plugin\SandAi\app\api\support\IdentityContextProblem;
use plugin\SandAi\app\contract\IdentityContextException;
use plugin\SandAi\app\contract\IdentityContextProvider;
use plugin\SandAi\app\domain\retrieval\RetrievalGateway;
use support\Request;
use support\Response;

final class RetrievalController
{
    public function __construct(private readonly IdentityContextProvider $identityContexts)
    {
    }

    public function search(Request $request): Response
    {
        $requestId = trim((string) $request->header('x-request-id', '')) ?: 'req_' . bin2hex(random_bytes(12));
        try {
            return ApiResponder::success(
                (new RetrievalGateway())->search($this->identityContexts->requireContext($request, 'sand_ai.retrieval.search')->requireAction('sand_ai.retrieval.search'), $request->post()),
                $requestId,
            );
        } catch (IdentityContextException $exception) {
            return ApiResponder::error(IdentityContextProblem::from($exception), $requestId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId);
        }
    }
}
