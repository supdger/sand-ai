<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\TaskRun;
use support\Request;

/** Checks private file ownership or exact published source membership before prompt or delivery. */
final class AgentRetrievedSourceGuard
{
    public function __construct(
        private readonly ?HostSandIamInvocationAuthorizer $authorizer = null,
        private readonly ?AiTaskSourceFacts $sourceFacts = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string} $queued
     */
    /** @return list<array{resource_type:string,resource_ref:string,authorization_id:int}> */
    public function authorize(array $matches, TaskRun $task, UsageAttribution $attribution, array $queued): array
    {
        [$facts, $sourceIdentity] = $this->verifiedFacts($matches, $task, $attribution, $queued);
        $grants = [];
        foreach (self::resources($facts, $task, $attribution) as $resource) {
            $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeQueued(
                $queued['signed_context'], self::sourceIdentity($sourceIdentity, $resource['action']), $resource['action'],
                $resource['resource_type'], $resource['resource_ref'],
                $queued['source_ip'], $queued['request_id'],
            );
            if (!array_key_exists('data_class', $decision)
                || $decision['data_class'] !== $facts['data_class']) {
                self::unverified();
            }
            $grants[] = ['resource_type' => $resource['resource_type'], 'resource_ref' => $resource['resource_ref'],
                'authorization_id' => $decision['authorization_id']];
        }
        return $grants;
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string} $queued
     * @param list<array{resource_type:string,resource_ref:string,authorization_id:int}> $grants
     */
    public function revalidate(array $matches, TaskRun $task, UsageAttribution $attribution, array $queued, array $grants): void
    {
        [$facts, $sourceIdentity] = $this->verifiedFacts($matches, $task, $attribution, $queued);
        $resources = self::resources($facts, $task, $attribution);
        if (count($grants) !== count($resources)) self::unverified();
        foreach ($resources as $index => $resource) {
            $grant = $grants[$index] ?? null;
            if (!is_array($grant) || ($grant['resource_type'] ?? null) !== $resource['resource_type']
                || ($grant['resource_ref'] ?? null) !== $resource['resource_ref']
                || !is_int($grant['authorization_id'] ?? null) || $grant['authorization_id'] < 1) {
                self::unverified();
            }
            $decision = ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->revalidate(
                $grant['authorization_id'], $queued['signed_context'], self::sourceIdentity($sourceIdentity, $resource['action']),
                $resource['action'], $resource['resource_type'], $resource['resource_ref'],
                $queued['source_ip'], $queued['request_id'],
            );
            if (!array_key_exists('data_class', $decision)
                || $decision['data_class'] !== $facts['data_class']) {
                self::unverified();
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array{signed_context:string,identity:IdentityContext,source_ip:string,request_id:string} $queued
     * @return array{array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>},IdentityContext}
     */
    private function verifiedFacts(array $matches, TaskRun $task, UsageAttribution $attribution, array $queued): array
    {
        if ((string) $task->task_type !== 'agent_run' || count($matches) > 8) self::unverified();
        $blockIds = [];
        foreach ($matches as $match) {
            if (!is_array($match) || !is_int($match['source_block_id'] ?? null)
                || $match['source_block_id'] < 1
                || !is_int($match['file_id'] ?? null) || $match['file_id'] < 1) {
                self::unverified();
            }
            $blockIds[] = $match['source_block_id'];
        }
        $facts = (new AgentSourceFacts())->resolveFiles($task, $blockIds, $attribution, $this->sourceFacts);
        AiTaskSourceFacts::assertMatches($matches, $facts);
        $identity = $queued['identity'] ?? null;
        if (!$identity instanceof IdentityContext
            || !is_string($queued['signed_context'] ?? null)
            || !is_string($queued['source_ip'] ?? null)
            || !is_string($queued['request_id'] ?? null)
            || (string) $attribution->organizationId !== $identity->organizationId
            || (string) $attribution->applicationId !== $identity->applicationId
            || $attribution->environmentId !== $identity->environmentId
            || (string) $attribution->workloadClientId !== $identity->workloadClientId) {
            self::unverified();
        }
        return [$facts, $identity];
    }

    /**
     * @param array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>} $facts
     * @return list<array{action:string,resource_type:string,resource_ref:string}>
     */
    private static function resources(array $facts, TaskRun $task, UsageAttribution $attribution): array
    {
        if ($facts['file_ids'] === []) {
            return [['action' => 'sand_ai.agent.run', 'resource_type' => 'sand_ai.task_run', 'resource_ref' => (string) $task->id]];
        }
        $publication = (new AgentSourceFacts())->publicationResource($task, $attribution);
        if ($publication !== null) return [$publication];
        return array_map(static fn (int $fileId): array => [
            'action' => 'sand_ai.agent.run', 'resource_type' => 'sand_ai.file', 'resource_ref' => (string) $fileId,
        ], $facts['file_ids']);
    }

    public function authorizeRead(Request $request, IdentityContext $identity, TaskRun $task, string $requestId): void
    {
        $this->authorizeReadWith(
            $identity, $task, $requestId,
            fn (IdentityContext $sourceIdentity, string $action, string $resolver, string $resource, string $operation): array =>
                ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
                    $request, $sourceIdentity, $action, $resolver, $resource,
                    $operation . bin2hex(random_bytes(16)), $requestId,
                ),
        );
    }

    /** Internal persisted stream closure, never accepts caller-provided source facts. */
    public function authorizeStreamReferences(Request $request, IdentityContext $identity, TaskRun $task,
        array $references, string $requestId): void
    {
        $this->authorizeReferencesWith($identity, $task, $references,
            fn (IdentityContext $sourceIdentity, string $action, string $resolver, string $resource, string $operation): array =>
                ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorize(
                    $request, $sourceIdentity, $action, $resolver, $resource,
                    $operation . bin2hex(random_bytes(16)), $requestId,
                ));
    }

    public function authorizeReadSigned(string $authorizationHeader, string $sourceIp, IdentityContext $identity, TaskRun $task, string $requestId): void
    {
        $this->authorizeReadWith(
            $identity, $task, $requestId,
            fn (IdentityContext $sourceIdentity, string $action, string $resolver, string $resource, string $operation): array =>
                ($this->authorizer ?? new HostSandIamInvocationAuthorizer())->authorizeSigned(
                    $authorizationHeader, $sourceIp, $sourceIdentity, $action, $resolver, $resource,
                    $operation . bin2hex(random_bytes(16)), $requestId,
                ),
        );
    }

    /** @param \Closure(IdentityContext,string,string,string,string):array<string,mixed> $authorize */
    private function authorizeReadWith(IdentityContext $identity, TaskRun $task, string $requestId, \Closure $authorize): void
    {
        $result = is_object($task->result) ? (array) $task->result : $task->result;
        if ((string) $task->state !== 'succeeded' && !is_array($result)) {
            return;
        }
        $references = is_array($result) ? ($result['source_references'] ?? null) : null;
        if (!is_array($references) || !array_is_list($references)) {
            self::unverified();
        }
        $this->authorizeReferencesWith($identity, $task, $references, $authorize);
    }

    private function authorizeReferencesWith(IdentityContext $identity, TaskRun $task, array $references, \Closure $authorize): void
    {
        if (!array_is_list($references)) self::unverified();
        $blockIds = [];
        foreach ($references as $reference) {
            if (!is_array($reference) || !is_int($reference['source_block_id'] ?? null)
                || $reference['source_block_id'] < 1
                || !is_int($reference['file_id'] ?? null) || $reference['file_id'] < 1) {
                self::unverified();
            }
            $blockIds[] = $reference['source_block_id'];
        }
        $attribution = UsageAttribution::fromIdentity($identity, 'agent', 'sand_ai.agent.run');
        $facts = (new AgentSourceFacts())->resolveFiles($task, $blockIds, $attribution, $this->sourceFacts);
        AiTaskSourceFacts::assertMatches($references, $facts);
        foreach (self::resources($facts, $task, $attribution) as $resource) {
            $decision = $authorize(self::sourceIdentity($identity, $resource['action']), $resource['action'],
                $resource['resource_type'], $resource['resource_ref'], 'agent-read-source:');
            if (!array_key_exists('data_class', $decision)
                || $decision['data_class'] !== $facts['data_class']) {
                self::unverified();
            }
        }
    }

    private static function sourceIdentity(IdentityContext $identity, string $action): IdentityContext
    {
        return new IdentityContext(
            $identity->organizationId, $identity->applicationId, $identity->environmentId,
            $identity->workloadClientId, $identity->audience, [$action],
            $identity->expiresAt,
        );
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @param array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>} $facts
     */
    public static function assertMatches(array $matches, array $facts): void
    {
        AiTaskSourceFacts::assertMatches($matches, $facts);
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent source facts are unavailable');
    }
}
