<?php

declare(strict_types=1);

namespace app\Application\Usage;

/**
 * Projects one real composition node from an existing immutable usage event.
 * Scalar subqueries cannot multiply events; conflicting ownership stays unknown.
 * No child result, input, credential or receipt is selected.
 */
final class CompositionUsageAssociationSql
{
    public static function runId(): string
    {
        return self::select('MAX(composition_run.id)::text');
    }

    public static function nodeKey(): string
    {
        return self::select("MAX(composition_run.id)::text || ':' || MAX(composition_node.node_code)");
    }

    public static function failed(): string
    {
        return '(' . self::select("MAX(composition_run.state)") . ")='partial_failed'";
    }

    private static function select(string $projection): string
    {
        $reference = "(composition_version.snapshot->'references'->composition_node.node_code)";
        $kind = "{$reference}->>'kind'";
        $code = "{$reference}->>'code'";
        $version = "{$reference}->>'version'";
        // Match RequestIdempotencyKey's existing v1 JSON bytes, including the
        // original textual IAM refs. Numeric projections never replace refs.
        $scope = self::hash("'{' || '\"version\":1,\"environment_id\":' || composition_run.environment_id::text ||
            ',\"application_id\":' || to_jsonb(composition_run.application_id)::text ||
            ',\"workload_client_id\":' || to_jsonb(composition_run.workload_client_id)::text || '}'");
        $agentKey = "'agent:' || " . self::hash("'[' || to_jsonb({$scope})::text || ',' ||
            to_jsonb({$code})::text || ',' || to_jsonb(composition_node.request_key)::text || ']'");
        $queryKey = "'knowledge-query:' || " . self::hash("{$scope} || ':' || composition_node.request_key");
        $invocation = UsageSummaryAssociationSql::invocation();
        $agent = "EXISTS (
            SELECT 1 FROM sand_ai_task_run composition_agent
            WHERE composition_agent.task_type='agent_run'
              AND composition_agent.request_id={$agentKey}
              AND composition_agent.input->'_agent_definition'->>'version_id'={$reference}->'target'->'binding'->>'version_id'
              AND composition_agent.input->'_agent_definition'->>'snapshot_sha256'={$reference}->'target'->'binding'->>'snapshot_sha256'
              AND composition_agent.environment_id=usage_event.environment_id
              AND composition_agent.organization_id::text=composition_run.organization_id
              AND composition_agent.application_id::text=composition_run.application_id
              AND composition_agent.workload_client_id::text=composition_run.workload_client_id
              AND (
                (dispatch.parent_task_run_id=composition_agent.id
                  AND EXISTS(SELECT 1 FROM sand_ai_task_step composition_step
                    WHERE composition_step.id=dispatch.parent_task_step_id AND composition_step.task_id=composition_agent.id)
                  AND (invocation.parent_task_run_id IS NULL OR invocation.parent_task_run_id=composition_agent.id))
                OR EXISTS(SELECT 1 FROM sand_ai_task_step composition_step
                    WHERE composition_step.task_id=composition_agent.id
                      AND composition_step.step_code IN ('agent_retrieval_1','agent_retrieval_2')
                      AND composition_step.context->>'child_request_id'=invocation.request_id)
              )
        )";
        $skill = "EXISTS (
            SELECT 1 FROM sand_ai_skill_run composition_skill
            JOIN sand_ai_skill composition_skill_definition ON composition_skill_definition.id=composition_skill.skill_id
            JOIN sand_ai_skill_version composition_skill_version ON composition_skill_version.id=composition_skill.skill_version_id
              AND composition_skill_version.skill_id=composition_skill.skill_id
            WHERE composition_skill.request_id=composition_node.request_key
              AND composition_skill.environment_id=usage_event.environment_id
              AND composition_skill_definition.code={$code} AND composition_skill_version.version_no::text={$version}
              AND composition_skill.skill_id::text={$reference}->'target'->>'skill_id'
              AND composition_skill.skill_version_id::text={$reference}->'target'->>'version_id'
              AND composition_skill.id=usage_event.skill_run_id
              AND composition_skill.invocation_id=invocation.id
        )";
        $material = '(' . UsageSummaryAssociationSql::workflow() . ") AND workflow_run.idempotency_key=composition_node.request_key
            AND workflow_run.input_snapshot->'analysis_profile'->>'code'={$code}
            AND workflow_run.input_snapshot->'analysis_profile'->>'version'={$version}";
        $knowledge = "EXISTS (
            SELECT 1 FROM sand_ai_knowledge_query_intent composition_query
            WHERE composition_query.request_id={$queryKey}
              AND composition_query.environment_id=usage_event.environment_id
              AND composition_query.organization_id::text=composition_run.organization_id
              AND composition_query.application_id::text=composition_run.application_id
              AND composition_query.workload_client_id::text=composition_run.workload_client_id
              AND composition_query.publication_id::text={$reference}->'target'->>'id'
              AND composition_query.knowledge_base_version_id::text={$reference}->'target'->>'active_version_id'
              AND invocation.knowledge_publication_id=composition_query.publication_id
              AND invocation.knowledge_base_version_id=composition_query.knowledge_base_version_id
              AND (invocation.request_id='knowledge-rerank:' || " . self::hash('composition_query.request_id') . "
                OR invocation.request_id='knowledge-query-embedding:' || " . self::hash("composition_query.environment_id::text || ':' ||
                    composition_query.publication_id::text || ':' || composition_query.request_id") . ")
        )";
        $tool = "usage_event.invocation_id IS NULL AND usage_event.source_kind='tool'
            AND usage_event.service_action='sand_ai.tool.run'
            AND EXISTS (
              SELECT 1 FROM sand_ai_tool_run composition_tool
              JOIN sand_ai_tool composition_tool_definition ON composition_tool_definition.id=composition_tool.tool_id
              JOIN sand_ai_tool_version composition_tool_version ON composition_tool_version.id=composition_tool.tool_version_id
                AND composition_tool_version.tool_id=composition_tool.tool_id
              WHERE composition_tool.id=usage_event.tool_run_id
                AND composition_tool.environment_id=usage_event.environment_id
                AND composition_tool.organization_id::text=composition_run.organization_id
                AND composition_tool.application_id::text=composition_run.application_id
                AND composition_tool.workload_client_id::text=composition_run.workload_client_id
                AND composition_tool.tool_id=usage_event.tool_id
                AND composition_tool.tool_version_id=usage_event.tool_version_id
                AND (
                  ({$kind}='tool' AND composition_tool.request_id=composition_node.request_key
                    AND composition_tool_definition.code={$code} AND composition_tool_version.version_no::text={$version}
                    AND composition_tool.tool_version_id::text={$reference}->'target'->>'tool_version_id'
                    AND usage_event.tool_connection_revision_id::text={$reference}->'target'->>'connection_revision_id')
                  OR ({$kind}='agent' AND EXISTS (
                    SELECT 1 FROM sand_ai_task_run composition_agent JOIN sand_ai_task_step composition_step
                      ON composition_step.task_id=composition_agent.id AND composition_step.id=composition_tool.parent_task_step_id
                    WHERE composition_agent.id=composition_tool.parent_task_run_id
                      AND composition_agent.task_type='agent_run' AND composition_agent.request_id={$agentKey}
                      AND composition_agent.input->'_agent_definition'->>'version_id'={$reference}->'target'->'binding'->>'version_id'
                      AND composition_agent.input->'_agent_definition'->>'snapshot_sha256'={$reference}->'target'->'binding'->>'snapshot_sha256'
                      AND composition_agent.environment_id=usage_event.environment_id
                      AND composition_agent.organization_id::text=composition_run.organization_id
                      AND composition_agent.application_id::text=composition_run.application_id
                      AND composition_agent.workload_client_id::text=composition_run.workload_client_id
                      AND composition_step.context->>'child_request_id'=composition_tool.request_id
                  ))
                )
            )";
        return "(SELECT CASE WHEN COUNT(DISTINCT composition_node.id)=1 THEN {$projection} ELSE NULL END
            FROM sand_ai_composition_node composition_node
            JOIN sand_ai_composition_run composition_run ON composition_run.id=composition_node.run_id
            JOIN sand_ai_composition_version composition_version ON composition_version.id=composition_run.version_id
            WHERE composition_run.environment_id=usage_event.environment_id
              AND composition_run.organization_id=usage_event.identity_snapshot->>'organization_ref'
              AND composition_run.application_id=usage_event.identity_snapshot->>'application_ref'
              AND composition_run.workload_client_id=usage_event.identity_snapshot->>'workload_client_ref'
              AND composition_run.organization_id ~ '^[0-9]+$'
              AND composition_run.application_id ~ '^[0-9]+$'
              AND composition_run.workload_client_id ~ '^[0-9]+$'
              AND COALESCE(NULLIF(ltrim(composition_run.organization_id,'0'),''),'0')=usage_event.organization_id::text
              AND COALESCE(NULLIF(ltrim(composition_run.application_id,'0'),''),'0')=usage_event.application_id::text
              AND COALESCE(NULLIF(ltrim(composition_run.workload_client_id,'0'),''),'0')=usage_event.workload_client_id::text
              AND composition_version.state='published'
              AND composition_version.snapshot_sha256=composition_run.snapshot_sha256
              AND {$reference}->>'environment_id'=composition_run.environment_id::text
              AND (
                (({$invocation}) AND (
                  ({$kind}='agent' AND {$agent})
                  OR ({$kind}='skill' AND {$skill})
                  OR ({$kind}='material' AND {$material})
                  OR ({$kind}='knowledge' AND {$knowledge})
                ))
                OR ({$tool})
              ))";
    }

    private static function hash(string $expression): string
    {
        return "encode(sha256(convert_to({$expression},'UTF8')),'hex')";
    }
}
