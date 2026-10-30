<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\UsageGateway;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Infrastructure\Persistence\Model\Invocation;
use support\Request;
use support\Response;

final class InvocationCostController
{
    public function read(Request $request, string $requestId): Response
    {
        $traceId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.usage.read');
            $authorizer = new HostSandIamInvocationAuthorizer();
            $authorizationHeader = trim((string) $request->header('authorization', ''));
            $signedContext = str_starts_with($authorizationHeader, 'Bearer ')
                ? trim(substr($authorizationHeader, 7)) : '';
            $sourceIp = $request->getRemoteIp();
            $decision = null;
            $invocationId = null;
            $result = (new UsageGateway())->invocationCost(
                $context['environment_id'], $requestId,
                static function (Invocation $invocation) use ($request, $context, $traceId, $authorizer, &$decision, &$invocationId): void {
                    $invocationId = (int) $invocation->id;
                    $decision = $authorizer->authorize(
                        $request, $context['context'], 'sand_ai.usage.read',
                        'sand_ai.invocation', (string) $invocationId,
                        'invocation-cost-read:' . bin2hex(random_bytes(16)), $traceId,
                    );
                },
            );
            if (!is_array($decision) || $invocationId === null || $invocationId < 1) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Invocation read authorization is unavailable');
            }
            // Reuse the same authorization after all reads, without consuming another invocation quota.
            $authorizer->revalidate(
                $decision['authorization_id'], $signedContext, $context['context'], 'sand_ai.usage.read',
                'sand_ai.invocation', (string) $invocationId, $sourceIp, $traceId,
            );
            return ApiResponder::success($result, $traceId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $traceId);
        }
    }
}
