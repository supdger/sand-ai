<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use think\facade\Db;

/** Read-only trial of the active published version for its owning environment. */
final class KnowledgeBaseTrialGateway
{
    public function __construct(private readonly ?HostSandIamEnvironmentFacts $environmentFacts = null)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function query(int $ownerEnvironmentId, int $versionId, array $input): array
    {
        if ($ownerEnvironmentId < 1 || $versionId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge trial target is invalid');
        }
        $normalized = KnowledgeBasePolicy::normalizeQuery($input);
        try {
            $owner = ($this->environmentFacts ?? new HostSandIamEnvironmentFacts())->resolve($ownerEnvironmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }

        return Db::connect('pgsql')->transaction(function () use ($ownerEnvironmentId, $versionId, $owner, $normalized): array {
            $rows = Db::connect('pgsql')->query(
                "SELECT kb.id,v.source_scope_sha256,v.data_class
                 FROM sand_ai_knowledge_base kb
                 JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
                 WHERE kb.environment_id=? AND v.id=? AND kb.status=1 AND kb.delete_time IS NULL
                   AND kb.state='published' AND v.state='published'
                 FOR SHARE OF kb,v",
                [$ownerEnvironmentId, $versionId],
            );
            if (count($rows) !== 1 || !is_string($rows[0]['source_scope_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $rows[0]['source_scope_sha256']) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge version is unavailable');
            }
            $scope = (new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
            if (!hash_equals($rows[0]['source_scope_sha256'], $scope['source_scope_sha256'])
                || $rows[0]['data_class'] !== $scope['data_class']) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source ownership changed');
            }
            $result = (new KnowledgeBaseQueryGateway())->queryVersion(
                $ownerEnvironmentId, $versionId, $normalized['query'], ['limit' => $normalized['limit']],
            );
            $currentScope = (new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
            if ($currentScope !== $scope) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Knowledge source ownership changed during trial');
            }
            return [
                'knowledge_base_id' => (int) $rows[0]['id'],
                'knowledge_base_version_id' => $versionId,
                'query' => $normalized['query'],
                'matches' => $result['items'],
            ];
        });
    }
}
