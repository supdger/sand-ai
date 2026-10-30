<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\KnowledgeBaseVersion;

/** Reads the accepted public chat resource, never request fields or decoded claims. */
final class ChatInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || $actionCode !== 'sand_ai.chat.complete') {
            self::unverified();
        }
        $invocationId = self::positiveId($resourceKey);
        $invocation = Invocation::where('id', $invocationId)->where('status', 1)->whereNull('delete_time')->find();
        $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->find();
        if ($invocation === null || $dispatch === null) {
            self::unverified();
        }
        $knowledgeFacts = null;
        $knowledgeScope = null;
        if ($invocation->knowledge_publication_id !== null) {
            $publicationId = self::positiveId($invocation->knowledge_publication_id);
            $versionId = self::positiveId($invocation->knowledge_base_version_id);
            $clientId = self::positiveId($invocation->workload_client_id);
            $knowledgeFacts = (new KnowledgePublicationFacts())->resolve(
                'sand_ai', 'sand_ai.knowledge.query', $publicationId . ':' . $clientId,
            );
            $version = KnowledgeBaseVersion::where('id', $versionId)->find();
            if ($version === null) self::unverified();
            $knowledgeScope = (string) $version->source_scope_sha256;
        }
        return self::fromPersisted([
            'id' => $invocation->id,
            'status' => $invocation->status,
            'delete_time' => $invocation->delete_time,
            'organization_id' => $invocation->organization_id,
            'application_id' => $invocation->application_id,
            'environment_id' => $invocation->environment_id,
            'workload_client_id' => $invocation->workload_client_id,
            'data_class' => $invocation->data_class,
            'knowledge_publication_id' => $invocation->knowledge_publication_id,
            'knowledge_base_version_id' => $invocation->knowledge_base_version_id,
            'knowledge_source_scope_sha256' => $invocation->knowledge_source_scope_sha256,
        ], [
            'invocation_id' => $dispatch->invocation_id,
            'environment_id' => $dispatch->environment_id,
            'application_id' => $dispatch->application_id,
            'execution_scope' => $dispatch->execution_scope,
            'source_kind' => $dispatch->source_kind,
            'service_action' => $dispatch->service_action,
        ], $invocationId, $knowledgeFacts, $knowledgeScope);
    }

    /**
     * Pure projection for the persisted resource boundary.
     * @param array<string,mixed> $invocation
     * @param array<string,mixed> $dispatch
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $invocation, array $dispatch, int $invocationId, ?array $knowledgeFacts = null, ?string $knowledgeScope = null): array
    {
        if (self::positiveId($invocation['id'] ?? null) !== $invocationId
            || self::positiveId($invocation['status'] ?? null) !== 1
            || !array_key_exists('delete_time', $invocation)
            || $invocation['delete_time'] !== null
            || self::positiveId($dispatch['invocation_id'] ?? null) !== $invocationId
            || ($dispatch['execution_scope'] ?? null) !== 'chat'
            || ($dispatch['source_kind'] ?? null) !== 'chat'
            || ($dispatch['service_action'] ?? null) !== 'sand_ai.chat.complete'
            || !array_key_exists('data_class', $invocation)) {
            self::unverified();
        }
        $organizationId = self::positiveId($invocation['organization_id'] ?? null);
        $applicationId = self::positiveId($invocation['application_id'] ?? null);
        $environmentId = self::positiveId($invocation['environment_id'] ?? null);
        $workloadClientId = self::positiveId($invocation['workload_client_id'] ?? null);
        if (self::positiveId($dispatch['environment_id'] ?? null) !== $environmentId
            || self::positiveId($dispatch['application_id'] ?? null) !== $applicationId) {
            self::unverified();
        }
        $dataClass = $invocation['data_class'];
        $publicationId = $invocation['knowledge_publication_id'] ?? null;
        if ($publicationId === null) {
            if ($dataClass !== null || ($invocation['knowledge_base_version_id'] ?? null) !== null
                || ($invocation['knowledge_source_scope_sha256'] ?? null) !== null || $knowledgeFacts !== null) {
                self::unverified();
            }
        } else {
            $publicationId = self::positiveId($publicationId);
            $versionId = self::positiveId($invocation['knowledge_base_version_id'] ?? null);
            $scope = $invocation['knowledge_source_scope_sha256'] ?? null;
            if (!is_string($scope) || preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1
                || $knowledgeScope === null || !hash_equals($scope, $knowledgeScope)
                || $knowledgeFacts === null
                || ($knowledgeFacts['resource_ref'] ?? null) !== $publicationId . ':' . $versionId
                || ($knowledgeFacts['organization_id'] ?? null) !== $organizationId
                || ($knowledgeFacts['application_id'] ?? null) !== $applicationId
                || ($knowledgeFacts['environment_id'] ?? null) !== $environmentId
                || ($knowledgeFacts['workload_client_id'] ?? null) !== $workloadClientId
                || ($knowledgeFacts['data_class'] ?? null) !== $dataClass) {
                self::unverified();
            }
        }
        return [
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'environment_id' => $environmentId,
            'workload_client_id' => $workloadClientId,
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.chat_invocation',
            'resource_ref' => (string) $invocationId,
        ];
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false && (string) $parsed === $value) {
                return $parsed;
            }
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
