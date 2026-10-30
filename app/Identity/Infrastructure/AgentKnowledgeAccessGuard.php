<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;

/** Authorizes the published knowledge resource used by an Agent task. */
final class AgentKnowledgeAccessGuard
{
    public function __construct(private readonly ?HostSandIamInvocationAuthorizer $authorizer = null)
    {
    }

    /**
     * @param array<string,mixed> $publication
     * @param array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string} $queued
     * @return array<string,mixed>
     */
    public function authorizeQueued(array $publication, array $queued): array
    {
        $identity = self::forKnowledge($queued['identity']);
        $key = self::resourceKey($publication, $identity);
        $decision = $this->host()->authorizeQueued(
            $queued['signed_context'], $identity, 'sand_ai.knowledge.query',
            'sand_ai.knowledge_publication', $key, $queued['source_ip'], $queued['request_id'],
        );
        self::assertDecision($decision, $publication);
        return $decision;
    }

    /**
     * @param array<string,mixed> $publication
     * @param array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string} $queued
     * @param array<string,mixed> $decision
     */
    public function revalidateQueued(array $publication, array $queued, array $decision): void
    {
        $identity = self::forKnowledge($queued['identity']);
        $key = self::resourceKey($publication, $identity);
        $current = $this->host()->revalidate(
            (int) ($decision['authorization_id'] ?? 0), $queued['signed_context'], $identity,
            'sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
            $key, $queued['source_ip'], $queued['request_id'],
        );
        self::assertDecision($current, $publication);
    }

    public function authorizeRead(Request $request, IdentityContext $identity, TaskRun $task, string $requestId): void
    {
        $publication = self::taskPublication($task);
        $decision = $this->host()->authorize(
            $request, $identity, 'sand_ai.knowledge.query', 'sand_ai.knowledge_publication',
            self::resourceKey($publication, $identity),
            'agent-knowledge-read:' . bin2hex(random_bytes(16)), $requestId,
        );
        self::assertDecision($decision, $publication);
    }

    public function authorizeReadSigned(
        string $authorizationHeader, string $sourceIp, IdentityContext $identity,
        TaskRun $task, string $requestId,
    ): void {
        $publication = self::taskPublication($task);
        $decision = $this->host()->authorizeSigned(
            $authorizationHeader, $sourceIp, $identity, 'sand_ai.knowledge.query',
            'sand_ai.knowledge_publication', self::resourceKey($publication, $identity),
            'agent-knowledge-read:' . bin2hex(random_bytes(16)), $requestId,
        );
        self::assertDecision($decision, $publication);
    }

    /** @return array<string,mixed> */
    private static function taskPublication(TaskRun $task): array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        $binding = is_array($input) ? ($input['_agent_definition'] ?? null) : null;
        $code = is_array($input) ? ($input['knowledge_base_code'] ?? null) : null;
        if (!is_array($binding) || !is_string($code)) self::invalid();
        (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $binding);
        $publication = (new KnowledgePublicationGateway())->findForConsumer((int) $task->environment_id, $code);
        if ((int) $publication['owner_environment_id'] !== (int) $task->environment_id
            || (int) $publication['id'] !== ($binding['knowledge_publication_id'] ?? null)
            || (int) $publication['active_version_id'] !== ($binding['knowledge_version_id'] ?? null)
            || (string) $publication['source_scope_sha256'] !== ($binding['knowledge_scope_sha256'] ?? null)) {
            self::invalid();
        }
        return $publication;
    }

    /** @param array<string,mixed> $publication */
    private static function resourceKey(array $publication, IdentityContext $identity): string
    {
        $clientId = filter_var($identity->workloadClientId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($clientId === false || (int) ($publication['consumer_environment_id'] ?? 0) !== $identity->environmentId
            || (string) ($publication['consumer_application_id'] ?? '') !== $identity->applicationId
            || (string) ($publication['consumer_organization_id'] ?? '') !== $identity->organizationId
            || (int) ($publication['id'] ?? 0) < 1 || (int) ($publication['active_version_id'] ?? 0) < 1) {
            self::invalid();
        }
        return $publication['id'] . ':' . $clientId . ':' . $publication['active_version_id'];
    }

    private static function forKnowledge(IdentityContext $identity): IdentityContext
    {
        return new IdentityContext(
            $identity->organizationId, $identity->applicationId, $identity->environmentId,
            $identity->workloadClientId, $identity->audience, ['sand_ai.knowledge.query'], $identity->expiresAt,
        );
    }

    /** @param array<string,mixed> $decision @param array<string,mixed> $publication */
    private static function assertDecision(array $decision, array $publication): void
    {
        if (!array_key_exists('data_class', $decision)
            || $decision['data_class'] !== ($publication['data_class'] ?? null)) self::invalid();
    }

    private function host(): HostSandIamInvocationAuthorizer
    {
        return $this->authorizer ?? new HostSandIamInvocationAuthorizer();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent knowledge facts are unavailable');
    }
}
