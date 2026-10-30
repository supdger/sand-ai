<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Scalar, read-only resource facts. Each immutable usage remains one row, including tool usage. */
final class ResourceUsageAssociationSql
{
    /** @return array<string,string> Public filters and projections share precisely the same proof. */
    public static function projections(): array
    {
        return [
            'skill_run_id' => self::skillRunId(),
            'agent_definition_id' => self::agentDefinitionId(),
            'agent_version_id' => self::agentVersionId(),
            'channel_binding_id' => self::channelBindingId(),
            'delivery_platform' => self::deliveryPlatform(),
            'knowledge_base_version_id' => self::knowledgeVersionId(),
            'knowledge_query_id' => self::knowledgeQueryId(),
            'knowledge_index_id' => self::knowledgeIndexId(),
        ];
    }

    public static function skillRunId(): string
    {
        $proof = UsageSummaryAssociationSql::invocation();
        return "CASE WHEN {$proof} AND usage_event.source_kind IN ('skill','skill_test')
            AND dispatch.execution_scope='skill' THEN (
            SELECT CASE WHEN COUNT(DISTINCT resource_skill.id)=1 THEN MIN(resource_skill.id)::text ELSE NULL END
            FROM sand_ai_skill_run resource_skill
            JOIN sand_ai_skill resource_skill_definition ON resource_skill_definition.id=resource_skill.skill_id
            JOIN sand_ai_skill_version resource_skill_version ON resource_skill_version.id=resource_skill.skill_version_id
              AND resource_skill_version.skill_id=resource_skill.skill_id
            WHERE resource_skill.id=usage_event.skill_run_id AND invocation.skill_run_id=resource_skill.id
              AND resource_skill.environment_id=usage_event.environment_id
              AND resource_skill_definition.environment_id=usage_event.environment_id
              AND resource_skill.skill_id=usage_event.skill_id AND invocation.skill_id=resource_skill.skill_id
              AND resource_skill.skill_version_id=usage_event.skill_version_id
              AND invocation.skill_version_id=resource_skill.skill_version_id
              AND (resource_skill.invocation_id IS NULL OR resource_skill.invocation_id=invocation.id)
        ) ELSE NULL END";
    }

    /** Reuse the drilldown proof; provider success does not imply a valid Skill result. */
    public static function failedSkill(): string
    {
        return 'EXISTS (SELECT 1 FROM sand_ai_skill_run resource_failed_skill
            WHERE resource_failed_skill.id::text=(' . self::skillRunId() . ")
              AND resource_failed_skill.delete_time IS NULL AND resource_failed_skill.state='failed')";
    }

    public static function agentRunId(): string
    {
        return self::agentProjection('resource_agent.id');
    }

    public static function agentDefinitionId(): string
    {
        return self::agentProjection('resource_definition.id', true);
    }

    public static function agentVersionId(): string
    {
        return self::agentProjection('resource_version.id', true);
    }

    public static function channelBindingId(): string
    {
        return self::agentProjection("resource_agent.input->'_channel'->>'channel_binding_id'", true, true);
    }

    public static function deliveryPlatform(): string
    {
        // No legacy default: absence of an explicit frozen platform is unknown.
        return self::agentProjection("resource_agent.input->'_channel'->>'delivery_platform'", true, true,
            "resource_agent.input->'_channel'->>'delivery_platform' IN ('feishu','wecom')");
    }

    public static function failedAgent(): string
    {
        return 'EXISTS (SELECT 1 FROM sand_ai_task_run resource_agent WHERE '
            . self::agentProof() . " AND resource_agent.state='failed')";
    }

    private static function agentProjection(string $value, bool $definition = false, bool $channel = false, string $extra = 'TRUE'): string
    {
        $joins = $definition ? "
            JOIN sand_ai_agent_version resource_version
              ON resource_version.id::text=resource_agent.input->'_agent_definition'->>'version_id'
            JOIN sand_ai_agent_definition resource_definition ON resource_definition.id=resource_version.agent_definition_id" : '';
        $definitionProof = $definition ? "
            AND resource_definition.environment_id=usage_event.environment_id
            AND resource_definition.id::text=resource_agent.input->'_agent_definition'->>'definition_id'
            AND resource_definition.code=resource_agent.input->'_agent_definition'->>'code'
            AND resource_version.state='published'
            AND resource_version.snapshot_sha256=resource_agent.input->'_agent_definition'->>'snapshot_sha256'" : '';
        $channelProof = $channel ? "
            AND resource_agent.input->'_channel'->>'agent_definition_id'=resource_definition.id::text
            AND EXISTS (
              SELECT 1 FROM sand_ai_channel_operation resource_channel
              WHERE resource_channel.environment_id=usage_event.environment_id AND resource_channel.operation_code='save'
                AND resource_channel.channel_binding_id::text=resource_agent.input->'_channel'->>'channel_binding_id'
                AND resource_channel.response_snapshot->>'channel_binding_id'=resource_agent.input->'_channel'->>'channel_binding_id'
                AND resource_channel.response_snapshot->>'environment_id'=usage_event.environment_id::text
                AND resource_channel.response_snapshot->>'revision'=resource_agent.input->'_channel'->>'revision'
                AND resource_channel.response_snapshot->>'agent_definition_id'=resource_definition.id::text
                AND resource_channel.response_snapshot->>'code'=resource_agent.input->'_channel'->>'code'
                AND resource_channel.response_snapshot->>'application_id'=usage_event.identity_snapshot->>'application_ref'
                AND resource_channel.response_snapshot->>'workload_client_id'=usage_event.identity_snapshot->>'workload_client_ref'
            )" : '';
        $proof = self::agentProof();
        // A conflict in the parent chain suppresses attribution, even if two parents share a definition.
        return "(SELECT CASE WHEN COUNT(DISTINCT resource_agent.id)=1 THEN MIN(({$value})::text) ELSE NULL END
            FROM sand_ai_task_run resource_agent {$joins}
            WHERE {$proof} {$definitionProof} {$channelProof} AND ({$extra}))";
    }

    private static function agentProof(): string
    {
        $owner = UsageSummaryAssociationSql::agentOwner('resource_agent');
        $invocation = UsageSummaryAssociationSql::invocation();
        $direct = "dispatch.execution_scope='agent' AND usage_event.source_kind='agent'
            AND usage_event.service_action='sand_ai.agent.run' AND invocation.data_class IS NULL
            AND dispatch.parent_task_run_id=resource_agent.id
            AND (invocation.parent_task_run_id IS NULL OR invocation.parent_task_run_id=resource_agent.id)
            AND (invocation.parent_task_step_id IS NULL OR invocation.parent_task_step_id=dispatch.parent_task_step_id)
            AND EXISTS (SELECT 1 FROM sand_ai_task_step resource_step
                WHERE resource_step.task_id=resource_agent.id AND resource_step.id=dispatch.parent_task_step_id
                  AND resource_step.step_code=dispatch.workflow_node_code)";
        $retrieval = "dispatch.execution_scope='embedding' AND usage_event.source_kind='knowledge_query'
            AND usage_event.service_action='sand_ai.knowledge.query'
            AND invocation.knowledge_publication_id::text=resource_agent.input->'_agent_definition'->>'knowledge_publication_id'
            AND invocation.knowledge_base_version_id::text=resource_agent.input->'_agent_definition'->>'knowledge_version_id'
            AND invocation.knowledge_source_scope_sha256=resource_agent.input->'_agent_definition'->>'knowledge_scope_sha256'
            AND EXISTS (SELECT 1 FROM sand_ai_task_step resource_step
                WHERE resource_step.task_id=resource_agent.id AND resource_step.step_code IN ('agent_retrieval_1','agent_retrieval_2')
                  AND resource_step.context->>'child_request_id'=invocation.request_id
                  AND resource_step.context->>'query_sha256' ~ '^[a-f0-9]{64}$'
                  AND invocation.request_id='knowledge-query-embedding:' || " . self::hash("usage_event.environment_id::text || ':' ||
                      invocation.knowledge_publication_id::text || ':agent-task:' || resource_agent.id::text || ':retrieval.search' ||
                      CASE WHEN resource_step.context->>'query_sha256'=" . self::hash("resource_agent.input->>'query'") . "
                        THEN '' ELSE ':' || left(resource_step.context->>'query_sha256',32) END") . ")";
        $tool = "usage_event.invocation_id IS NULL AND usage_event.source_kind='tool'
            AND usage_event.service_action='sand_ai.tool.run'
            AND EXISTS (SELECT 1 FROM sand_ai_tool_run resource_tool
                JOIN sand_ai_task_step resource_step ON resource_step.id=resource_tool.parent_task_step_id
                  AND resource_step.task_id=resource_tool.parent_task_run_id
                WHERE resource_tool.id=usage_event.tool_run_id AND resource_tool.parent_task_run_id=resource_agent.id
                  AND resource_tool.environment_id=usage_event.environment_id
                  AND resource_tool.organization_id=usage_event.organization_id
                  AND resource_tool.application_id=usage_event.application_id
                  AND resource_tool.workload_client_id=usage_event.workload_client_id
                  AND resource_tool.identity_snapshot->>'organization_ref'=usage_event.identity_snapshot->>'organization_ref'
                  AND resource_tool.identity_snapshot->>'application_ref'=usage_event.identity_snapshot->>'application_ref'
                  AND resource_tool.identity_snapshot->>'workload_client_ref'=usage_event.identity_snapshot->>'workload_client_ref'
                  AND resource_tool.tool_id=usage_event.tool_id AND resource_tool.tool_version_id=usage_event.tool_version_id
                  AND resource_tool.tool_connection_id=usage_event.tool_connection_id
                  AND resource_tool.tool_connection_revision_id=usage_event.tool_connection_revision_id
                  AND resource_step.context->>'child_request_id'=resource_tool.request_id)";
        return "({$owner}) AND ((({$invocation}) AND (({$direct}) OR ({$retrieval}))) OR ({$tool}))";
    }

    public static function knowledgeBaseId(): string
    {
        return self::knowledgeProjection('knowledge_base_id');
    }

    public static function knowledgeVersionId(): string
    {
        return self::knowledgeProjection('version_id');
    }

    public static function knowledgeIndexId(): string
    {
        return self::knowledgeProjection('index_id');
    }

    private static function knowledgeProjection(string $field): string
    {
        $invocation = UsageSummaryAssociationSql::invocation();
        $index = self::indexProof();
        // A shared library version can have multiple matching source rows. Only one version/index is attributable.
        return "CASE WHEN {$invocation} THEN (
            SELECT CASE WHEN COUNT(DISTINCT resource_knowledge.version_id)=1
                AND COUNT(DISTINCT resource_knowledge.knowledge_base_id)=1
                AND COUNT(DISTINCT resource_knowledge.index_id)<=1
              THEN MIN(resource_knowledge.{$field})::text ELSE NULL END
            FROM (
                SELECT resource_version.knowledge_base_id,resource_version.id AS version_id,NULL::bigint AS index_id
                FROM sand_ai_knowledge_base_version resource_version
                JOIN sand_ai_knowledge_base resource_base ON resource_base.id=resource_version.knowledge_base_id
                JOIN sand_ai_knowledge_publication resource_publication ON resource_publication.knowledge_base_id=resource_base.id
                WHERE resource_version.id=invocation.knowledge_base_version_id
                  AND resource_publication.id=invocation.knowledge_publication_id
                  AND resource_publication.owner_environment_id=resource_base.environment_id
                  AND resource_publication.consumer_environment_id=usage_event.environment_id
                  AND resource_publication.consumer_organization_id=usage_event.organization_id
                  AND resource_publication.consumer_application_id=usage_event.application_id
                  AND resource_version.source_scope_sha256=invocation.knowledge_source_scope_sha256
                  AND resource_version.data_class IS NOT DISTINCT FROM invocation.data_class
                UNION
                SELECT resource_version.knowledge_base_id,resource_version.id AS version_id,resource_index.id AS index_id
                FROM sand_ai_knowledge_embedding_index resource_index
                JOIN sand_ai_knowledge_base_version resource_version ON resource_version.id=resource_index.knowledge_base_version_id
                JOIN sand_ai_knowledge_base resource_base ON resource_base.id=resource_version.knowledge_base_id
                WHERE {$index}
            ) resource_knowledge
        ) ELSE NULL END";
    }

    private static function indexProof(): string
    {
        // Matches the existing index gateway's immutable request + logical execution derivation,
        // including failed model calls that never produced a stored vector.
        return "dispatch.execution_scope='embedding' AND usage_event.source_kind='knowledge_index'
            AND usage_event.service_action='sand_ai.knowledge.index'
            AND resource_index.owner_environment_id=usage_event.environment_id
            AND resource_base.environment_id=resource_index.owner_environment_id
            AND resource_index.source_snapshot_sha256=resource_version.snapshot_sha256
            AND resource_index.source_scope_sha256=resource_version.source_scope_sha256
            AND EXISTS (SELECT 1 FROM sand_ai_knowledge_base_source resource_source
                WHERE resource_source.knowledge_base_version_id=resource_version.id
                  AND invocation.request_id='knowledge-embedding:' || " . self::hash("resource_index.id::text || ':' || resource_source.source_block_id::text") . "
                  AND dispatch.logical_execution_id=left(" . self::hash("'embedding:' || resource_index.owner_environment_id::text || ':' || resource_index.id::text || ':' || resource_source.source_block_id::text") . ",48))";
    }

    public static function knowledgeQueryId(): string
    {
        $invocation = UsageSummaryAssociationSql::invocation();
        return "CASE WHEN {$invocation} THEN (
            SELECT CASE WHEN COUNT(DISTINCT resource_query.id)=1 THEN MIN(resource_query.id)::text ELSE NULL END
            FROM sand_ai_knowledge_query_intent resource_query
            WHERE resource_query.organization_id=usage_event.organization_id
              AND resource_query.application_id=usage_event.application_id
              AND resource_query.environment_id=usage_event.environment_id
              AND resource_query.workload_client_id=usage_event.workload_client_id
              AND resource_query.publication_id=invocation.knowledge_publication_id
              AND resource_query.knowledge_base_version_id=invocation.knowledge_base_version_id
              AND resource_query.source_scope_sha256=invocation.knowledge_source_scope_sha256
              AND resource_query.data_class IS NOT DISTINCT FROM invocation.data_class
              AND (
                (dispatch.execution_scope='embedding' AND usage_event.source_kind='knowledge_query'
                  AND usage_event.service_action='sand_ai.knowledge.query'
                  AND invocation.request_id='knowledge-query-embedding:' ||
                    " . self::hash("resource_query.environment_id::text || ':' || resource_query.publication_id::text || ':' || resource_query.request_id") . ")
                OR (((dispatch.execution_scope='chat' AND usage_event.source_kind='chat'
                      AND usage_event.service_action='sand_ai.chat.complete')
                    OR (dispatch.execution_scope='rerank' AND usage_event.source_kind='knowledge_query'
                      AND usage_event.service_action='sand_ai.knowledge.query'))
                  AND invocation.request_id='knowledge-rerank:' ||
                    " . self::hash('resource_query.request_id') . ")
              )
        ) ELSE NULL END";
    }

    private static function hash(string $value): string
    {
        return "encode(sha256(convert_to({$value},'UTF8')),'hex')";
    }
}
