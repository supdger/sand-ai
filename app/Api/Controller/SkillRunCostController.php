<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Skill\SkillConsumerContext;
use app\Application\Skill\SkillRunCostGateway;
use app\Application\Skill\SkillRunRecoveryRequest;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use app\Infrastructure\Persistence\Model\Invocation;
use support\Request;
use support\Response;

final class SkillRunCostController
{
    public function test(Request $request, string $code, string $version): Response
    {
        return $this->read($request, $code, $version, 'test');
    }

    public function run(Request $request, string $code, string $version): Response
    {
        return $this->read($request, $code, $version, 'workload');
    }

    private function read(Request $request, string $code, string $version, string $kind): Response
    {
        $traceId = RequestAuditId::ensure($request);
        try {
            $identity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.usage.read');
            $query = $request->get();
            $key = is_array($query) ? ($query['request_id'] ?? null) : null;
            $versionNo = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_array($query) || array_diff(array_keys($query), ['request_id', 'scope_type', 'scope_ref', 'actor_ref', 'attempt_no']) !== []
                || !ctype_digit($version) || $versionNo === false || !is_string($key)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill cost query is invalid');
            }
            $consumer = SkillConsumerContext::fromRequest($identity['context'],
                ['type' => $query['scope_type'] ?? null, 'ref' => $query['scope_ref'] ?? null], $query['actor_ref'] ?? null);
            $authorizer = new HostSandIamInvocationAuthorizer();
            $decision = null;
            $invocationId = null;
            $result = (new SkillRunCostGateway())->read($consumer, $code, $versionNo, $kind, $key,
                static function (Invocation $invocation) use ($request, $identity, $traceId, $authorizer, &$decision, &$invocationId): void {
                    $invocationId = (int) $invocation->id;
                    $decision = $authorizer->authorize($request, $identity['context'], 'sand_ai.usage.read',
                        'sand_ai.invocation', (string) $invocationId, 'skill-fee-read:' . bin2hex(random_bytes(16)), $traceId);
                }, SkillRunRecoveryRequest::attemptNumber($query['attempt_no'] ?? null));
            if (!is_array($decision) || $invocationId === null || $invocationId < 1) {
                throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Skill fee authorization is unavailable');
            }
            $header = trim((string) $request->header('authorization', ''));
            $signedContext = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
            $authorizer->revalidate($decision['authorization_id'], $signedContext, $identity['context'],
                'sand_ai.usage.read', 'sand_ai.invocation', (string) $invocationId, $request->getRemoteIp(), $traceId);
            return ApiResponder::success($result, $traceId);
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_RESOURCE_NOT_FOUND' => 404,
                'SAND_AI_INVOCATION_FACTS_UNVERIFIED' => 403,
                default => null,
            };
            if ($status === null) return ApiResponder::error($problem, $traceId);
            // This fee-only contract has precise statuses; existing endpoints
            // retain their shared responder and IAM error semantics.
            return json([
                'code' => $status, 'message' => $problem->getMessage(),
                'data' => ['error_code' => $problem->errorCode, 'retryable' => $problem->retryable],
                'request_id' => $traceId,
            ])->withStatus($status);
        }
    }
}
