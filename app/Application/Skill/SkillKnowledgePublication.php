<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Knowledge\KnowledgeVersionSourceScope;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentFacts;
use think\facade\Db;

/** Resolves a pinned Skill knowledge version through its live consumer publication. */
final class SkillKnowledgePublication
{
    public function __construct(
        private readonly ?HostSandIamEnvironmentFacts $environments = null,
        private readonly ?KnowledgeVersionSourceScope $sources = null,
        private readonly ?object $connection = null,
    ) {
    }

    /** @return array{knowledge_base_id:int,owner_environment_id:int,publication_id:int,publication_code:string,owner_code:string,version_no:int,data_class:?string,source_scope_sha256:string} */
    public function resolve(int $consumerEnvironmentId, int $versionId, string $snapshot): array
    {
        if ($consumerEnvironmentId < 1 || $versionId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $snapshot) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Skill knowledge reference is invalid');
        }
        $rows = ($this->connection ?? Db::connect('pgsql'))->query(
            "SELECT p.id publication_id,p.code publication_code,kb.code owner_code,v.version_no,
                    p.knowledge_base_id,p.owner_environment_id,
                    p.consumer_organization_id,p.consumer_application_id,
                    v.source_scope_sha256,v.data_class
             FROM sand_ai_knowledge_publication p
             JOIN sand_ai_knowledge_base kb ON kb.id=p.knowledge_base_id
                 AND kb.environment_id=p.owner_environment_id
             JOIN sand_ai_knowledge_base_version v ON v.id=? AND v.knowledge_base_id=kb.id
             WHERE p.consumer_environment_id=? AND p.state='published'
               AND kb.status=1 AND kb.delete_time IS NULL AND kb.state='published'
               AND v.state='published' AND v.snapshot_sha256=?
             FOR SHARE OF p,kb,v",
            [$versionId, $consumerEnvironmentId, $snapshot],
        );
        if (count($rows) !== 1 || !is_string($rows[0]['source_scope_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $rows[0]['source_scope_sha256']) !== 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Published Skill knowledge is unavailable');
        }
        $row = $rows[0];
        try {
            $facts = $this->environments ?? new HostSandIamEnvironmentFacts();
            $consumer = $facts->resolve($consumerEnvironmentId);
            $owner = $facts->resolve((int) $row['owner_environment_id']);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
        if ($consumer['organization_id'] !== (int) $row['consumer_organization_id']
            || $consumer['application_id'] !== (int) $row['consumer_application_id']) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill consumer ownership changed');
        }
        $source = ($this->sources ?? new KnowledgeVersionSourceScope())->resolve($versionId, $owner);
        if (!hash_equals((string) $row['source_scope_sha256'], $source['source_scope_sha256'])
            || $row['data_class'] !== $source['data_class']) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Skill knowledge source ownership changed');
        }
        return [
            'knowledge_base_id' => (int) $row['knowledge_base_id'],
            'owner_environment_id' => $owner['environment_id'],
            'publication_id' => (int) $row['publication_id'],
            'publication_code' => (string) $row['publication_code'],
            'owner_code' => (string) $row['owner_code'],
            'version_no' => (int) $row['version_no'],
            'data_class' => $source['data_class'],
            'source_scope_sha256' => $source['source_scope_sha256'],
        ];
    }

    /** Project a verified pin's consumer alias without rewriting frozen evidence or prompts.
     *  @param array<string,mixed> $knowledge
     *  @param list<array<string,mixed>> $citations
     *  @return list<array<string,mixed>>
     */
    public static function consumerCitations(array $knowledge, array $citations): array
    {
        $projected = [];
        foreach ($citations as $citation) {
            if (!is_array($citation)
                || !in_array($citation['knowledge_base_code'] ?? null,
                    [$knowledge['owner_code'], $knowledge['publication_code']], true)
                || ($citation['knowledge_base_version'] ?? null) !== $knowledge['version_no']) {
                throw new ApiProblem('SAND_AI_KNOWLEDGE_SOURCE_DRIFTED', 'Skill citation does not match its published knowledge pin');
            }
            $citation['knowledge_base_code'] = $knowledge['publication_code'];
            $projected[] = $citation;
        }
        return $projected;
    }
}
