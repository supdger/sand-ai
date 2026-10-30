<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionGateway;
use app\Application\Agent\AgentGateway;
use app\Application\Knowledge\KnowledgePublicationGateway;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Published sources belong to the authorized publication; ad hoc sources remain private to their uploader. */
final class AgentSourceFacts
{
    /** @param list<int> $blockIds
     *  @return array{data_class:?string,file_ids:list<int>,block_file_ids:array<int,int>}
     */
    public function resolveFiles(TaskRun $task, array $blockIds, UsageAttribution $attribution,
        ?AiTaskSourceFacts $privateFiles = null): array
    {
        $publication = $this->publication($task, $attribution);
        if ($publication === null) {
            return ($privateFiles ?? new AiTaskSourceFacts())->resolveFiles(
                (int) $task->environment_id, $blockIds, $attribution,
            );
        }
        if (!array_is_list($blockIds) || count($blockIds) > 64
            || count($blockIds) !== count(array_unique($blockIds))) self::unverified();
        $fileIds = [];
        $blockFileIds = [];
        foreach ($blockIds as $blockId) {
            if (!is_int($blockId) || $blockId < 1) self::unverified();
            $rows = Db::connect('pgsql')->query(
                "SELECT s.file_id
                 FROM sand_ai_knowledge_base_source s
                 JOIN sand_ai_file f ON f.id=s.file_id
                 JOIN sand_ai_file_parse p ON p.id=s.parse_id AND p.file_id=s.file_id
                 JOIN sand_ai_source_block b ON b.id=s.source_block_id AND b.file_id=s.file_id AND b.parse_id=s.parse_id
                 WHERE s.knowledge_base_version_id=? AND s.source_block_id=? AND f.environment_id=?
                   AND f.status=1 AND f.state='parsed' AND f.delete_time IS NULL
                   AND p.status=1 AND p.state='succeeded' AND p.delete_time IS NULL
                   AND NOT EXISTS (SELECT 1 FROM sand_ai_file_parse latest
                     WHERE latest.file_id=p.file_id AND latest.status=1 AND latest.state='succeeded'
                       AND latest.delete_time IS NULL AND latest.id>p.id)
                   AND b.status=1 AND b.delete_time IS NULL AND b.sequence_no=s.sequence_no
                   AND b.locator_type=s.locator_type AND b.locator=s.locator
                   AND b.content_sha256=s.content_sha256
                   AND encode(sha256(convert_to(b.content,'UTF8')),'hex')=b.content_sha256",
                [(int) $publication['active_version_id'], $blockId, (int) $publication['owner_environment_id']],
            );
            if (count($rows) !== 1 || (int) $rows[0]['file_id'] < 1) self::unverified();
            $fileId = (int) $rows[0]['file_id'];
            $fileIds[$fileId] = $fileId;
            $blockFileIds[$blockId] = $fileId;
        }
        return ['data_class' => $blockIds === [] ? null : $publication['data_class'],
            'file_ids' => array_values($fileIds), 'block_file_ids' => $blockFileIds];
    }

    /** @return array{action:string,resource_type:string,resource_ref:string}|null */
    public function publicationResource(TaskRun $task, UsageAttribution $attribution): ?array
    {
        $publication = $this->publication($task, $attribution);
        return $publication === null ? null : [
            'action' => 'sand_ai.knowledge.query',
            'resource_type' => 'sand_ai.knowledge_publication',
            'resource_ref' => $publication['id'] . ':' . $attribution->workloadClientId . ':' . $publication['active_version_id'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function publication(TaskRun $task, UsageAttribution $attribution): ?array
    {
        $input = is_object($task->input) ? (array) $task->input : $task->input;
        if ((string) $task->task_type !== 'agent_run' || !is_array($input)
            || $attribution->environmentId !== (int) $task->environment_id
            || $attribution->organizationId !== (int) $task->organization_id
            || $attribution->applicationId !== (int) $task->application_id
            || $attribution->workloadClientId !== (int) $task->workload_client_id
            || $attribution->canonicalTrustedIdentity() !== AgentGateway::attributionFromPersistedInput(
                $input, (int) $task->environment_id,
            )->canonicalTrustedIdentity()) self::unverified();
        if (!array_key_exists('_agent_definition', $input)) return null;
        $binding = $input['_agent_definition'];
        $code = $input['knowledge_base_code'] ?? null;
        if (!is_array($binding) || !is_string($code)) self::unverified();
        (new AgentDefinitionGateway())->assertCurrent((int) $task->environment_id, $binding);
        $publication = (new KnowledgePublicationGateway())->findForConsumer((int) $task->environment_id, $code);
        if ((int) $publication['owner_environment_id'] !== (int) $task->environment_id
            || (int) $publication['id'] !== ($binding['knowledge_publication_id'] ?? null)
            || (int) $publication['active_version_id'] !== ($binding['knowledge_version_id'] ?? null)
            || (string) $publication['source_scope_sha256'] !== ($binding['knowledge_scope_sha256'] ?? null)) self::unverified();
        try {
            $facts = (new KnowledgePublicationFacts())->resolve('sand_ai', 'sand_ai.knowledge.query',
                $publication['id'] . ':' . $attribution->workloadClientId . ':' . $publication['active_version_id']);
        } catch (IdentityContextException) {
            self::unverified();
        }
        if ($facts['organization_id'] !== $attribution->organizationId
            || $facts['application_id'] !== $attribution->applicationId
            || $facts['environment_id'] !== $attribution->environmentId
            || $facts['workload_client_id'] !== $attribution->workloadClientId
            || $facts['data_class'] !== $publication['data_class']) self::unverified();
        return $publication;
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Agent source facts are unavailable');
    }
}
