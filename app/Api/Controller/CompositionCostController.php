<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\CompositionCostGateway;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

/** Read-only cost surface; never reads child output or invokes a child service. */
final class CompositionCostController
{
    public function read(Request $request, string $runId): Response
    {
        $requestId = RequestAuditId::ensure($request);
        try {
            $id = filter_var($runId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || $request->get() !== [] || $request->rawBody() !== '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '运行费用仅接受原运行编号，不接受查询字段或正文');
            }
            $authenticator = new SandIamContextAuthenticator();
            $workflow = $authenticator->authenticate($request, 'sand_ai.workflow.read')['context'];
            $scope = [$workflow->environmentId, $workflow->organizationId, $workflow->applicationId, $workflow->workloadClientId];
            $authorizer = new HostSandIamInvocationAuthorizer();
            $decisions = [];
            foreach (['sand_ai.workflow.read', 'sand_ai.usage.read'] as $action) {
                $identity = $authenticator->authenticate($request, $action)['context'];
                if ([$identity->environmentId, $identity->organizationId, $identity->applicationId, $identity->workloadClientId] !== $scope) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '原工作流费用归属已变化');
                }
                $decisions[$action] = $authorizer->authorize($request, $identity, $action,
                    'sand_ai.composition_cost', (string) $id, 'composition-cost:' . bin2hex(random_bytes(16)), $requestId);
            }
            $revalidate = static function () use ($authenticator, $authorizer, $decisions, $request, $requestId, $scope, $id): void {
                foreach (['sand_ai.workflow.read', 'sand_ai.usage.read'] as $action) {
                    $current = $authenticator->authenticate($request, $action)['context'];
                    if ([$current->environmentId, $current->organizationId, $current->applicationId, $current->workloadClientId] !== $scope) {
                        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '原工作流费用归属已变化');
                    }
                    $header = trim((string) $request->header('authorization', ''));
                    $signedContext = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
                    $authorizer->revalidate((int) $decisions[$action]['authorization_id'], $signedContext, $current,
                        $action, 'sand_ai.composition_cost', (string) $id, $request->getRemoteIp(), $requestId);
                }
            };
            $revalidate();
            return ApiResponder::success((new CompositionCostGateway())->read($workflow, (int) $id, $revalidate), $requestId)
                ->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $requestId)->header('Cache-Control', 'no-store');
        }
    }
}
