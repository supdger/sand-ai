<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeVersionSourceScope;
use app\Identity\Contract\IdentityContextException;
use think\facade\Db;

final class KnowledgePublicationFacts
{
    public function __construct(
        private readonly ?HostSandIamEnvironmentFacts $environments = null,
        private readonly ?KnowledgeVersionSourceScope $sources = null,
    ) {
    }

    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || $actionCode !== 'sand_ai.knowledge.query'
            || preg_match('/^([1-9][0-9]{0,18}):([1-9][0-9]{0,18})(?::([1-9][0-9]{0,18}))?$/D', $resourceKey, $parts) !== 1) self::unverified();
        $publicationId = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $clientId = filter_var($parts[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($publicationId === false || $clientId === false) self::unverified();
        $pinnedVersionId = isset($parts[3])
            ? filter_var($parts[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : null;
        if ($pinnedVersionId === false) self::unverified();
        $rows = Db::connect('pgsql')->query(
            "SELECT p.id,p.knowledge_base_id,p.owner_environment_id,p.consumer_organization_id,
                    p.consumer_application_id,p.consumer_environment_id,v.id active_version_id,
                    v.source_scope_sha256,v.data_class
             FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=" . ($pinnedVersionId === null ? 'kb.active_version_id' : '?') . " AND v.knowledge_base_id=kb.id
             WHERE p.id=? AND p.state='published' AND kb.delete_time IS NULL
               AND kb.status=1 AND kb.state='published' AND v.state='published'",
            $pinnedVersionId === null ? [$publicationId] : [$pinnedVersionId, $publicationId],
        );
        if (count($rows) !== 1) self::unverified();
        return $this->fromPersisted($rows[0], $publicationId, $clientId);
    }

    /** @param array<string,mixed> $row
     *  @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public function fromPersisted(array $row, int $publicationId, int $clientId): array
    {
        if ($publicationId < 1 || $clientId < 1 || (int) ($row['id'] ?? 0) !== $publicationId
            || !is_string($row['source_scope_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $row['source_scope_sha256']) !== 1
            || !is_int($row['active_version_id'] ?? null) && !ctype_digit((string) ($row['active_version_id'] ?? ''))) {
            self::unverified();
        }
        $versionId = (int) $row['active_version_id'];
        if ($versionId < 1) self::unverified();
        try {
            $environments = $this->environments ?? new HostSandIamEnvironmentFacts();
            $consumer = $environments->resolve((int) ($row['consumer_environment_id'] ?? 0));
            $owner = $environments->resolve((int) ($row['owner_environment_id'] ?? 0));
            if ($consumer['organization_id'] !== (int) ($row['consumer_organization_id'] ?? 0)
                || $consumer['application_id'] !== (int) ($row['consumer_application_id'] ?? 0)) {
                self::unverified();
            }
            $scope = ($this->sources ?? new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
            if (!hash_equals($row['source_scope_sha256'], $scope['source_scope_sha256'])
                || ($row['data_class'] ?? null) !== $scope['data_class']) {
                self::unverified();
            }
        } catch (ApiProblem|IdentityContextException) {
            self::unverified();
        }
        return [
            'organization_id' => $consumer['organization_id'],
            'application_id' => $consumer['application_id'],
            'environment_id' => $consumer['environment_id'],
            'workload_client_id' => $clientId,
            'data_class' => $scope['data_class'],
            'resource_type' => 'sand_ai.knowledge_base_version',
            'resource_ref' => $publicationId . ':' . $versionId,
        ];
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
