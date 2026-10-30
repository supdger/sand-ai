<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\SkillKnowledgeRef;
use app\Infrastructure\Persistence\Model\SkillRun;
use app\Infrastructure\Persistence\Model\SkillVersion;
use think\facade\Db;

/** Read model for the administrator's existing Skill lifecycle. */
final class SkillWorkspaceGateway
{
    /** @return array{current_page:int,per_page:int,total?:int,data:list<array<string,mixed>>} */
    public function list(int $environmentId, int $page, int $limit, ?SkillConsumerContext $consumer = null, ?int $beforeId = null): array
    {
        $this->requireEnvironment($environmentId);
        self::page($page, $limit);
        $connection = Db::connect('pgsql');
        $ownership = $consumer === null ? " AND scope_type='legacy'"
            : " AND organization_id=? AND application_id=? AND scope_type=? AND scope_ref=? AND scope_source='consumer_declared'";
        $parameters = [$environmentId];
        if ($consumer !== null) $parameters = [...$parameters, (int) $consumer->identity->organizationId,
            (int) $consumer->identity->applicationId, $consumer->scopeType, $consumer->scopeRef];
        if ($beforeId !== null) {
            if ($beforeId < 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill cursor is invalid');
            $ownership .= ' AND s.id<?';
            $parameters[] = $beforeId;
        }
        $offset = $beforeId === null ? (($page - 1) * $limit) : 0;
        $total = $consumer === null ? $connection->query(
            'SELECT count(*) total FROM sand_ai_skill s WHERE s.environment_id=? AND s.delete_time IS NULL' . $ownership,
            $parameters,
        ) : [];
        $rows = $connection->query(
            "SELECT s.id,s.code,s.name,s.state,s.status,s.active_version_id,
                    active.version_no active_version,draft.version_no draft_version
             FROM sand_ai_skill s
             LEFT JOIN sand_ai_skill_version active ON active.id=s.active_version_id AND active.skill_id=s.id
             LEFT JOIN sand_ai_skill_version draft ON draft.skill_id=s.id AND draft.state='draft'
             WHERE s.environment_id=? AND s.delete_time IS NULL{$ownership}
             ORDER BY s.id DESC LIMIT {$limit} OFFSET " . $offset,
            $parameters,
        );
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'state' => (string) $row['state'],
            'status' => (int) $row['status'],
            'active_version_id' => $row['active_version_id'] === null ? null : (int) $row['active_version_id'],
            'active_version' => $row['active_version'] === null ? null : (int) $row['active_version'],
            'draft_version' => $row['draft_version'] === null ? null : (int) $row['draft_version'],
        ], $rows);
        $result = ['current_page' => $page, 'per_page' => $limit, 'data' => $items];
        if ($consumer === null) $result['total'] = (int) ($total[0]['total'] ?? 0);
        return $result;
    }

    /** @return array{skill:array<string,mixed>,versions:list<array<string,mixed>>} */
    public function read(int $environmentId, int $skillId, ?SkillConsumerContext $consumer = null): array
    {
        $this->requireEnvironment($environmentId);
        if ($skillId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill id is invalid');
        }
        $skill = Skill::where('id', $skillId)->where('environment_id', $environmentId)
            ->whereNull('delete_time')->find();
        if ($skill === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
        }
        SkillConsumerContext::assertAccess($skill, $consumer);
        $versions = SkillVersion::where('skill_id', $skillId)->order('version_no', 'desc')->select();
        $items = [];
        foreach ($versions as $version) {
            $reference = SkillKnowledgeRef::where('skill_version_id', (int) $version->id)->find();
            if ($reference === null) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Skill knowledge reference is absent');
            }
            $items[] = [
                'id' => (int) $version->id,
                'version' => (int) $version->version_no,
                'state' => (string) $version->state,
                'name' => (string) $version->name,
                'description' => (string) $version->description,
                'instruction' => (string) $version->instruction,
                'input_schema' => $version->input_schema,
                'output_schema' => $version->output_schema,
                'review_policy' => $version->review_policy,
                'knowledge_base_version_id' => (int) $reference->knowledge_base_version_id,
                'snapshot_sha256' => (string) $reference->snapshot_sha256,
                'consumer_context' => $version->consumer_context,
                'provenance' => $version->provenance,
                'source_provenance_purged_time' => $version->source_provenance_purged_time,
                'published_consumer_context' => $version->published_consumer_context,
                'tested' => SkillRun::where('environment_id', $environmentId)
                    ->where('skill_version_id', (int) $version->id)
                    ->where('run_kind', 'test')->where('state', 'succeeded')->find() !== null,
            ];
        }
        return [
            'skill' => [
                'scope' => $consumer === null ? null : ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef],
                'scope_source' => $consumer === null ? 'legacy' : 'consumer_declared',
                'id' => (int) $skill->id,
                'environment_id' => (int) $skill->environment_id,
                'code' => (string) $skill->code,
                'name' => (string) $skill->name,
                'state' => (string) $skill->state,
                'status' => (int) $skill->status,
                'active_version_id' => $skill->active_version_id === null ? null : (int) $skill->active_version_id,
            ],
            'versions' => $items,
        ];
    }

    /** @return array{current_page:int,per_page:int,total?:int,data:list<array<string,mixed>>} */
    public function sources(int $environmentId, int $page, int $limit, ?int $beforeId = null, bool $includeTotal = true): array
    {
        if ($environmentId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill environment is invalid');
        }
        try {
            $consumer = (new HostSandIamEnvironmentFacts())->resolve($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        self::page($page, $limit);
        $connection = Db::connect('pgsql');
        $where = "p.consumer_environment_id=? AND p.state='published'
            AND p.consumer_organization_id=? AND p.consumer_application_id=?
            AND kb.status=1 AND kb.delete_time IS NULL AND kb.state='published'
            AND v.state='published' AND v.source_scope_sha256 IS NOT NULL";
        $params = [$environmentId, $consumer['organization_id'], $consumer['application_id']];
        if ($beforeId !== null) {
            if ($beforeId < 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill source cursor is invalid');
            $where .= ' AND p.id<?';
            $params[] = $beforeId;
        }
        $offset = $beforeId === null ? (($page - 1) * $limit) : 0;
        $total = $includeTotal ? $connection->query(
            'SELECT count(*) total FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE ' . $where,
            $params,
        ) : [];
        $rows = $connection->query(
            'SELECT p.id publication_id,p.code,kb.name,v.id knowledge_base_version_id,
                    v.version_no,v.snapshot_sha256,v.data_class
             FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=kb.active_version_id AND v.knowledge_base_id=kb.id
             WHERE ' . $where . " ORDER BY p.id DESC LIMIT {$limit} OFFSET " . $offset,
            $params,
        );
        $items = array_map(static fn (array $row): array => [
            'publication_id' => (int) $row['publication_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'knowledge_base_version_id' => (int) $row['knowledge_base_version_id'],
            'version' => (int) $row['version_no'],
            'snapshot_sha256' => (string) $row['snapshot_sha256'],
            'data_class' => $row['data_class'] === null ? null : (string) $row['data_class'],
        ], $rows);
        $result = ['current_page' => $page, 'per_page' => $limit, 'data' => $items];
        if ($includeTotal) $result['total'] = (int) ($total[0]['total'] ?? 0);
        return $result;
    }

    private static function page(int $page, int $limit): void
    {
        if ($page < 1 || $page > 1000000 || $limit < 1 || $limit > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill page is invalid');
        }
    }

    private function requireEnvironment(int $environmentId): void
    {
        if ($environmentId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill environment is invalid');
        }
        try {
            (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }
}
