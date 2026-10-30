<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Skill\SkillKnowledgePublication;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\KnowledgeBase;
use app\Infrastructure\Persistence\Model\KnowledgeBaseSource;
use app\Infrastructure\Persistence\Model\KnowledgeBaseVersion;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\SkillKnowledgeRef;
use app\Infrastructure\Persistence\Model\SkillRun;
use app\Infrastructure\Persistence\Model\SkillVersion;

/** Resolves a public Skill invocation from persisted SandAI resources. */
final class SkillInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, ['sand_ai.skill.run', 'sand_ai.skill.test'], true)) {
            self::unverified();
        }
        $invocationId = self::positiveId($resourceKey);
        $invocation = Invocation::where('id', $invocationId)->where('status', 1)->whereNull('delete_time')->find();
        $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->find();
        if ($invocation === null || $dispatch === null) {
            self::unverified();
        }
        $run = SkillRun::where('id', (int) $invocation->skill_run_id)->find();
        $skill = Skill::where('id', (int) $invocation->skill_id)->where('status', 1)->whereNull('delete_time')->find();
        $version = SkillVersion::where('id', (int) $invocation->skill_version_id)->find();
        if ($run === null || $skill === null || $version === null) {
            self::unverified();
        }
        $references = SkillKnowledgeRef::where('skill_version_id', (int) $version->id)->select();
        if (count($references) !== 1) {
            self::unverified();
        }
        $reference = $references[0];
        try {
            $publication = (new SkillKnowledgePublication())->resolve(
                (int) $invocation->environment_id,
                (int) $reference->knowledge_base_version_id,
                (string) $reference->snapshot_sha256,
            );
            if ($publication['knowledge_base_id'] !== (int) $reference->knowledge_base_id) self::unverified();
            $knowledgeFacts = (new KnowledgePublicationFacts())->resolve(
                'sand_ai',
                'sand_ai.knowledge.query',
                $publication['publication_id'] . ':' . (int) $invocation->workload_client_id
                    . ':' . (int) $reference->knowledge_base_version_id,
            );
        } catch (ApiProblem|IdentityContextException) {
            self::unverified();
        }
        $knowledgeVersion = KnowledgeBaseVersion::where('id', (int) $reference->knowledge_base_version_id)->find();
        $knowledgeBase = KnowledgeBase::where('id', (int) $reference->knowledge_base_id)
            ->where('status', 1)->whereNull('delete_time')->find();
        if ($knowledgeVersion === null || $knowledgeBase === null) {
            self::unverified();
        }
        $sources = KnowledgeBaseSource::where('knowledge_base_version_id', (int) $knowledgeVersion->id)->select();
        $fileFacts = [];
        foreach ($sources as $source) {
            $file = AiFile::where('id', (int) $source->file_id)->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null) {
                self::unverified();
            }
            $fileFacts[] = FileInvocationFacts::fromPersisted([
                'id' => $file->id, 'status' => $file->status,
                'delete_time' => $file->delete_time, 'state' => $file->state,
                'environment_id' => $file->environment_id,
                'owner_scope' => $file->owner_scope,
                'owner_attribution' => $file->owner_attribution,
            ], (int) $source->file_id);
        }
        return self::fromPersisted(
            [
                'id' => $invocation->id, 'status' => $invocation->status,
                'delete_time' => $invocation->delete_time, 'data_class' => $invocation->data_class,
                'organization_id' => $invocation->organization_id,
                'application_id' => $invocation->application_id,
                'environment_id' => $invocation->environment_id,
                'workload_client_id' => $invocation->workload_client_id,
                'skill_id' => $invocation->skill_id,
                'skill_version_id' => $invocation->skill_version_id,
                'skill_run_id' => $invocation->skill_run_id,
            ],
            [
                'invocation_id' => $dispatch->invocation_id,
                'environment_id' => $dispatch->environment_id,
                'application_id' => $dispatch->application_id,
                'execution_scope' => $dispatch->execution_scope,
                'source_kind' => $dispatch->source_kind,
                'service_action' => $dispatch->service_action,
                'skill_id' => $dispatch->skill_id,
                'skill_version_id' => $dispatch->skill_version_id,
                'skill_run_id' => $dispatch->skill_run_id,
            ],
            [
                'id' => $run->id, 'environment_id' => $run->environment_id,
                'skill_id' => $run->skill_id, 'skill_version_id' => $run->skill_version_id,
                'run_kind' => $run->run_kind, 'state' => $run->state, 'consumer_context' => $run->consumer_context,
                'invocation_id' => $run->invocation_id, 'delete_time' => $run->delete_time,
            ],
            [
                'id' => $skill->id, 'environment_id' => $skill->environment_id,
                'scope_type' => $skill->scope_type, 'scope_ref' => $skill->scope_ref,
                'scope_source' => $skill->scope_source, 'organization_id' => $skill->organization_id,
                'application_id' => $skill->application_id,
                'status' => $skill->status, 'state' => $skill->state,
                'active_version_id' => $skill->active_version_id,
                'delete_time' => $skill->delete_time,
            ],
            [
                'id' => $version->id, 'skill_id' => $version->skill_id,
                'state' => $version->state,
            ],
            [
                'skill_version_id' => $reference->skill_version_id,
                'knowledge_base_id' => $reference->knowledge_base_id,
                'knowledge_base_version_id' => $reference->knowledge_base_version_id,
                'snapshot_sha256' => $reference->snapshot_sha256,
            ],
            [
                'id' => $knowledgeVersion->id,
                'knowledge_base_id' => $knowledgeVersion->knowledge_base_id,
                'state' => $knowledgeVersion->state,
                'snapshot_sha256' => $knowledgeVersion->snapshot_sha256,
                'source_count' => $knowledgeVersion->source_count,
            ],
            [
                'id' => $knowledgeBase->id,
                'environment_id' => $knowledgeBase->environment_id,
                'status' => $knowledgeBase->status,
                'state' => $knowledgeBase->state,
                'delete_time' => $knowledgeBase->delete_time,
            ],
            $fileFacts,
            $invocationId,
            $knowledgeFacts,
            $publication['publication_id'],
            $actionCode,
        );
    }

    /**
     * @param array<string,mixed> $invocation
     * @param array<string,mixed> $dispatch
     * @param array<string,mixed> $run
     * @param array<string,mixed> $skill
     * @param array<string,mixed> $version
     * @param array<string,mixed> $reference
     * @param array<string,mixed> $knowledgeVersion
     * @param array<string,mixed> $knowledgeBase
     * @param list<array<string,mixed>> $fileFacts
     * @param array<string,mixed>|null $knowledgeFacts
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(
        array $invocation,
        array $dispatch,
        array $run,
        array $skill,
        array $version,
        array $reference,
        array $knowledgeVersion,
        array $knowledgeBase,
        array $fileFacts,
        int $invocationId,
        ?array $knowledgeFacts = null,
        ?int $publicationId = null,
        string $requiredAction = 'sand_ai.skill.run',
    ): array {
        $test = $requiredAction === 'sand_ai.skill.test';
        if (!in_array($requiredAction, ['sand_ai.skill.run', 'sand_ai.skill.test'], true)) self::unverified();
        $environmentId = self::positiveId($invocation['environment_id'] ?? null);
        $organizationId = self::positiveId($invocation['organization_id'] ?? null);
        $applicationId = self::positiveId($invocation['application_id'] ?? null);
        $clientId = self::positiveId($invocation['workload_client_id'] ?? null);
        $skillId = self::positiveId($invocation['skill_id'] ?? null);
        $versionId = self::positiveId($invocation['skill_version_id'] ?? null);
        $runId = self::positiveId($invocation['skill_run_id'] ?? null);
        if (self::positiveId($invocation['id'] ?? null) !== $invocationId
            || self::positiveId($invocation['status'] ?? null) !== 1
            || !array_key_exists('delete_time', $invocation)
            || $invocation['delete_time'] !== null
            || !array_key_exists('data_class', $invocation)
            || self::positiveId($dispatch['invocation_id'] ?? null) !== $invocationId
            || self::positiveId($dispatch['environment_id'] ?? null) !== $environmentId
            || self::positiveId($dispatch['application_id'] ?? null) !== $applicationId
            || ($dispatch['execution_scope'] ?? null) !== 'skill'
            || ($dispatch['source_kind'] ?? null) !== ($test ? 'skill_test' : 'skill')
            || ($dispatch['service_action'] ?? null) !== $requiredAction
            || self::positiveId($dispatch['skill_id'] ?? null) !== $skillId
            || self::positiveId($dispatch['skill_version_id'] ?? null) !== $versionId
            || self::positiveId($dispatch['skill_run_id'] ?? null) !== $runId
            || self::positiveId($run['id'] ?? null) !== $runId
            || self::positiveId($run['environment_id'] ?? null) !== $environmentId
            || self::positiveId($run['skill_id'] ?? null) !== $skillId
            || self::positiveId($run['skill_version_id'] ?? null) !== $versionId
            || ($run['run_kind'] ?? null) !== ($test ? 'test' : 'workload')
            || !in_array($run['state'] ?? null, ['running', 'succeeded', 'failed'], true)
            || !array_key_exists('delete_time', $run)
            || $run['delete_time'] !== null
            || (($run['invocation_id'] ?? null) !== null && self::positiveId($run['invocation_id']) !== $invocationId)
            || self::positiveId($skill['id'] ?? null) !== $skillId
            || self::positiveId($skill['environment_id'] ?? null) !== $environmentId
            || self::positiveId($skill['status'] ?? null) !== 1
            || !in_array($skill['state'] ?? null, $test ? ['draft', 'published'] : ['published'], true)
            || !array_key_exists('delete_time', $skill)
            || $skill['delete_time'] !== null
            || self::positiveId($version['id'] ?? null) !== $versionId
            || self::positiveId($version['skill_id'] ?? null) !== $skillId
            || ($version['state'] ?? null) !== ($test ? 'draft' : 'published')
            || self::positiveId($reference['skill_version_id'] ?? null) !== $versionId
            || self::positiveId($reference['knowledge_base_version_id'] ?? null) !== self::positiveId($knowledgeVersion['id'] ?? null)
            || self::positiveId($reference['knowledge_base_id'] ?? null) !== self::positiveId($knowledgeVersion['knowledge_base_id'] ?? null)
            || ($reference['snapshot_sha256'] ?? null) !== ($knowledgeVersion['snapshot_sha256'] ?? null)
            || ($knowledgeVersion['state'] ?? null) !== 'published'
            || self::positiveId($knowledgeBase['id'] ?? null) !== self::positiveId($knowledgeVersion['knowledge_base_id'] ?? null)
            || self::positiveId($knowledgeBase['status'] ?? null) !== 1
            || ($knowledgeBase['state'] ?? null) !== 'published'
            || !array_key_exists('delete_time', $knowledgeBase)
            || $knowledgeBase['delete_time'] !== null
            || self::positiveId($knowledgeVersion['source_count'] ?? null) !== count($fileFacts)) {
            self::unverified();
        }
        if (($skill['scope_type'] ?? 'legacy') !== 'legacy') {
            $consumer = $run['consumer_context'] ?? null;
            if (!is_array($consumer) || ($consumer['scope_source'] ?? null) !== 'consumer_declared'
                || ($consumer['scope']['type'] ?? null) !== $skill['scope_type']
                || ($consumer['scope']['ref'] ?? null) !== $skill['scope_ref']
                || ($consumer['organization_id'] ?? null) !== $organizationId
                || ($consumer['application_id'] ?? null) !== $applicationId
                || ($consumer['environment_id'] ?? null) !== $environmentId
                || ($consumer['workload_client_id'] ?? null) !== $clientId
                || (int) ($skill['organization_id'] ?? 0) !== $organizationId
                || (int) ($skill['application_id'] ?? 0) !== $applicationId) self::unverified();
        } elseif ($test) {
            self::unverified();
        }
        $dataClass = $invocation['data_class'];
        if ($dataClass !== null && (!is_string($dataClass)
            || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) {
            self::unverified();
        }
        if ($knowledgeFacts === null
            || ($knowledgeFacts['organization_id'] ?? null) !== $organizationId
            || ($knowledgeFacts['application_id'] ?? null) !== $applicationId
            || ($knowledgeFacts['environment_id'] ?? null) !== $environmentId
            || ($knowledgeFacts['workload_client_id'] ?? null) !== $clientId
            || ($knowledgeFacts['data_class'] ?? null) !== $dataClass
            || ($knowledgeFacts['resource_type'] ?? null) !== 'sand_ai.knowledge_base_version'
            || $publicationId === null
            || ($knowledgeFacts['resource_ref'] ?? null) !== $publicationId . ':' . $reference['knowledge_base_version_id']) {
            self::unverified();
        }
        return [
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'environment_id' => $environmentId,
            'workload_client_id' => $clientId,
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.skill_invocation',
            'resource_ref' => (string) $invocationId,
        ];
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false && (string) $parsed === $value) return $parsed;
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
