<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Skill\SkillGateway;
use app\Application\Skill\SkillConsumerContext;
use app\Application\Skill\SkillWorkloadRequest;
use app\Application\Skill\SkillRunRecoveryRequest;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\SkillInvocationAccessGuard;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\Skill;
use support\Request;
use support\Response;

final class SkillController
{
    public function readRun(Request $request, string $code, string $version): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            $originalRequestId = $request->get('request_id');
            if (!ctype_digit($version) || !is_string($originalRequestId) || $originalRequestId === '') {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request id is invalid');
            }
            $identity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.skill.run');
            $context = $identity['context'];
            $consumer = $request->get('scope_type') === null && $request->get('scope_ref') === null && $request->get('actor_ref') === null
                ? null : SkillConsumerContext::fromRequest($context, ['type' => $request->get('scope_type'), 'ref' => $request->get('scope_ref')], $request->get('actor_ref'));
            return ApiResponder::success(
                (new SkillGateway())->readRun(
                    $identity['environment_id'],
                    $code,
                    (int) $version,
                    $consumer === null ? $originalRequestId : $consumer->key($originalRequestId),
                    UsageAttribution::fromIdentity($context, 'skill', 'sand_ai.skill.run'),
                    fn (int $invocationId) => (new SkillInvocationAccessGuard())->authorize(
                        $request, $context, $invocationId, $responseRequestId,
                    ),
                    function (int $publicationId, int $versionId) use ($request, $context, $responseRequestId): void {
                        $clientId = filter_var($context->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($clientId === false) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill workload identity is incomplete');
                        }
                        (new HostSandIamInvocationAuthorizer())->authorize(
                            $request,
                            $context,
                            'sand_ai.knowledge.query',
                            'sand_ai.knowledge_publication',
                            $publicationId . ':' . $clientId . ':' . $versionId,
                            'skill-knowledge:' . bin2hex(random_bytes(16)),
                            $responseRequestId,
                        );
                    },
                    $consumer,
                    fn () => $this->authorizeSkillResource($request, $context, $code, (int) $version, $consumer, $responseRequestId),
                    $consumer === null ? null : $originalRequestId,
                    SkillRunRecoveryRequest::attemptNumber($request->get('attempt_no')),
                ),
                $responseRequestId,
            );
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId);
        }
    }

    /** Used only for a proven never-sent failure which has no invocation resource. */
    private function authorizeSkillResource(Request $request, IdentityContext $context, string $code, int $version, ?SkillConsumerContext $consumer, string $requestId): void
    {
        $skill = Skill::where('environment_id', $context->environmentId)->where('code', $code)->whereNull('delete_time')->find();
        if ($skill === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
        SkillConsumerContext::assertAccess($skill, $consumer);
        (new HostSandIamInvocationAuthorizer())->authorize($request, $context, 'sand_ai.skill.run', 'sand_ai.skill',
            (int) $skill->id . ':' . $version . ':' . $context->workloadClientId,
            'skill-never-sent-read:' . bin2hex(random_bytes(16)), $requestId);
    }

    public function run(Request $request, string $code, string $version): Response
    {
        $responseRequestId = RequestAuditId::ensure($request);
        try {
            $payload = $request->post();
            if (!ctype_digit($version) || !is_array($payload)
                || array_diff(array_keys($payload), ['input', 'request_id', 'scope', 'actor_ref', 'resume_failed', 'failed_run_id']) !== []) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request envelope is invalid');
            }
            $recovery = SkillRunRecoveryRequest::fromPayload($payload);
            $identity = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.skill.run');
            $consumer = !array_key_exists('scope', $payload) && !array_key_exists('actor_ref', $payload)
                ? null : SkillConsumerContext::fromRequest($identity['context'], $payload['scope'] ?? null, $payload['actor_ref'] ?? null);
            // Consumer runs need the caller's stable logical key. A newly generated
            // HTTP audit ID cannot recover a response lost by the consumer.
            $logicalRequestId = $payload['request_id'] ?? ($consumer === null ? $responseRequestId : null);
            $workload = SkillWorkloadRequest::fromAuthenticated($identity, $payload['input'] ?? null, $logicalRequestId);
            return ApiResponder::success(
                (new SkillGateway())->run(
                    $workload->environmentId,
                    $code,
                    (int) $version,
                    $workload->input,
                    $consumer === null ? $workload->requestId : $consumer->key($workload->requestId),
                    UsageAttribution::fromIdentity($workload->context, 'skill', 'sand_ai.skill.run'),
                    authorizePublicInvocation: fn (int $invocationId) => (new SkillInvocationAccessGuard())->authorize(
                        $request, $workload->context, $invocationId, $responseRequestId,
                    ),
                    authorizeKnowledge: function (int $publicationId, int $versionId) use ($request, $workload, $responseRequestId): void {
                        $clientId = filter_var($workload->context->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if ($clientId === false) {
                            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill workload identity is incomplete');
                        }
                        (new HostSandIamInvocationAuthorizer())->authorize(
                            $request,
                            $workload->context,
                            'sand_ai.knowledge.query',
                            'sand_ai.knowledge_publication',
                            $publicationId . ':' . $clientId . ':' . $versionId,
                            'skill-knowledge:' . bin2hex(random_bytes(16)),
                            $responseRequestId,
                        );
                    },
                    consumer: $consumer,
                    authorizeSkillResource: fn () => $this->authorizeSkillResource($request, $workload->context, $code, (int) $version, $consumer, $responseRequestId),
                    resumeFailed: $recovery->resumeFailed, failedRunId: $recovery->failedRunId,
                ),
                $responseRequestId,
            );
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $responseRequestId);
        }
    }
}
