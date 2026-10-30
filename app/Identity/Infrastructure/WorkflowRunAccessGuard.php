<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\WorkflowRun;
use support\Request;

/** Authorizes a persisted workflow at submit, worker execution, and result read. */
final class WorkflowRunAccessGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?QueuedSandIamContextCipher $cipher = null,
    )
    {
    }

    /** @return array<string,mixed> Encrypted task-local authorization, never an API result. */
    public function authorizeRun(Request $request, IdentityContext $identity, int $runId, string $requestId): array
    {
        $cipher = $this->cipher();
        $sourceIp = $request->getRemoteIp();
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request,
            $identity,
            'sand_ai.workflow.run',
            'sand_ai.workflow_run',
            (string) $runId,
            'workflow-run:' . bin2hex(random_bytes(16)),
            $requestId,
        );
        $binding = new QueuedSandIamContextBinding($runId, $decision['authorization_id'], $sourceIp, $requestId, $identity->expiresAt);
        $header = trim((string) $request->header('authorization', ''));
        return [
            'version' => 1,
            'authorization_id' => $binding->authorizationId,
            'source_ip' => $binding->sourceIp,
            'request_id' => $binding->requestId,
            'expires_at' => $binding->expiresAt,
            'context' => $cipher->seal(trim(substr($header, 7)), $binding),
        ];
    }

    /** @param array<string,mixed> $authorization */
    public function revalidateQueued(array $authorization, WorkflowRun $run): void
    {
        if (($authorization['version'] ?? null) !== 1
            || !is_int($authorization['authorization_id'] ?? null)
            || !is_string($authorization['source_ip'] ?? null)
            || !is_string($authorization['request_id'] ?? null)
            || !is_int($authorization['expires_at'] ?? null)
            || !is_array($authorization['context'] ?? null)) {
            throw new ApiProblem('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE', 'Queued workload identity context is unavailable');
        }
        $binding = new QueuedSandIamContextBinding(
            (int) $run->id,
            $authorization['authorization_id'],
            $authorization['source_ip'],
            $authorization['request_id'],
            $authorization['expires_at'],
        );
        $signedContext = $this->cipher()->open($authorization['context'], $binding);
        $identity = new IdentityContext(
            (string) $run->organization_id,
            (string) $run->application_id,
            (int) $run->environment_id,
            (string) $run->workload_client_id,
            'sand-ai',
            ['sand_ai.workflow.run'],
            $binding->expiresAt,
        );
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            $binding->authorizationId,
            $signedContext,
            $identity,
            'sand_ai.workflow.run',
            'sand_ai.workflow_run',
            (string) $binding->workflowRunId,
            $binding->sourceIp,
            $binding->requestId,
        );
    }

    private function cipher(): QueuedSandIamContextCipher
    {
        return $this->cipher ?? new QueuedSandIamContextCipher((array) config('sand_ai_iam_context', []));
    }

    /** @return array<string,mixed> Internal decision and persisted resource facts, never an API result. */
    public function authorizeRead(Request $request, IdentityContext $identity, int $runId, string $requestId): array
    {
        $facts = $this->readFacts($runId);
        $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
            $request,
            $identity,
            'sand_ai.workflow.read',
            'sand_ai.workflow_run',
            (string) $runId,
            'workflow-read:' . bin2hex(random_bytes(16)),
            $requestId,
        );
        $this->assertReadFacts($runId, $facts);
        return $decision + ['_workflow_read_facts' => $facts];
    }

    /** @param array<string,mixed> $decision */
    public function revalidateRead(Request $request, IdentityContext $identity, int $runId, string $requestId, array $decision): void
    {
        $facts = $decision['_workflow_read_facts'] ?? null;
        if (!is_array($facts)) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
        }
        $this->assertReadFacts($runId, $facts);
        $signedContext = trim((string) $request->header('authorization', ''));
        if (str_starts_with($signedContext, 'Bearer ')) $signedContext = trim(substr($signedContext, 7));
        ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $signedContext, $identity,
            'sand_ai.workflow.read', 'sand_ai.workflow_run', (string) $runId,
            $request->getRemoteIp(), $requestId,
        );
        // Revalidation may wait on live IAM rows; resource facts must still match on return.
        $this->assertReadFacts($runId, $facts);
    }

    /** @return array<string,mixed> */
    private function readFacts(int $runId): array
    {
        try {
            return (new WorkflowInvocationFacts())->resolve('sand_ai', 'sand_ai.workflow.read', (string) $runId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    /** @param array<string,mixed> $facts */
    private function assertReadFacts(int $runId, array $facts): void
    {
        if ($this->readFacts($runId) !== $facts) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED'));
        }
    }
}
