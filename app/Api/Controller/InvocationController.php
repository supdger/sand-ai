<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Gateway\ChatGateway;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Identity\Contract\IdentityContext;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Infrastructure\Persistence\Model\Invocation;
use support\Request;
use support\Response;

final class InvocationController
{
    public function read(Request $request, string $requestId): Response
    {
        $traceId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.invocation.read');
            return $this->readForContext($request, $context, $requestId, $traceId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $traceId);
        }
    }

    public function readByRequestKey(Request $request): Response
    {
        $traceId = RequestAuditId::ensure($request);
        try {
            $context = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.invocation.read');
            $query = $request->get();
            if ($request->rawBody() !== '' || array_diff(array_keys($query), ['request_key']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Only request_key query is accepted without a request body');
            }
            if (array_key_exists('request_key', $query) && !is_string($query['request_key'])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'request_key must be a string');
            }
            $requestId = RequestIdempotencyKey::fromCallerKey($query['request_key'] ?? '', 'chat', $context['context']);
            return $this->readForContext($request, $context, $requestId, $traceId);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $traceId);
        }
    }

    /** @param array{context:IdentityContext,environment_id:int} $context */
    private function readForContext(Request $request, array $context, string $requestId, string $traceId): Response
    {
        $authorizer = new HostSandIamInvocationAuthorizer();
        $authorizationHeader = trim((string) $request->header('authorization', ''));
        $signedContext = str_starts_with($authorizationHeader, 'Bearer ')
            ? trim(substr($authorizationHeader, 7)) : '';
        $sourceIp = $request->getRemoteIp();
        $decision = null;
        $invocationId = null;
        $result = (new ChatGateway())->invocation(
            $context['environment_id'], $requestId,
            static function (Invocation $invocation) use ($request, $context, $traceId, $authorizer, &$decision, &$invocationId): void {
                $invocationId = (int) $invocation->id;
                $decision = $authorizer->authorize(
                    $request, $context['context'], 'sand_ai.invocation.read',
                    'sand_ai.invocation', (string) $invocationId,
                    'invocation-read:' . bin2hex(random_bytes(16)), $traceId,
                );
            },
        );
        if (!is_array($decision) || $invocationId === null || $invocationId < 1) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Invocation read authorization is unavailable');
        }
        // Reuse the same authorization after all reads, without consuming another invocation quota.
        $authorizer->revalidate(
            $decision['authorization_id'], $signedContext, $context['context'], 'sand_ai.invocation.read',
            'sand_ai.invocation', (string) $invocationId, $sourceIp, $traceId,
        );
        return ApiResponder::success($result, $traceId);
    }
}
