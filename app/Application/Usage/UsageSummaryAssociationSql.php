<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Read-only, event-level association predicates. All joins used here are unique. */
final class UsageSummaryAssociationSql
{
    public static function invocation(): string
    {
        return "invocation.status=1 AND invocation.delete_time IS NULL
            AND dispatch.invocation_id=invocation.id
            AND usage_event.environment_id=invocation.environment_id AND dispatch.environment_id=invocation.environment_id
            AND usage_event.organization_id=invocation.organization_id AND usage_event.application_id=invocation.application_id
            AND usage_event.workload_client_id IS NOT DISTINCT FROM invocation.workload_client_id AND dispatch.application_id=invocation.application_id
            AND usage_event.provider_id=dispatch.provider_id AND usage_event.model_id=invocation.model_id
            AND dispatch.model_id=invocation.model_id AND dispatch.deployment_id=invocation.deployment_id
            AND usage_event.source_kind=dispatch.source_kind AND usage_event.service_action=dispatch.service_action";
    }

    public static function task(bool $agent = false): string
    {
        $parents = $agent
            ? "(invocation.parent_task_run_id IS NULL OR invocation.parent_task_run_id=dispatch.parent_task_run_id)
                AND (invocation.parent_task_step_id IS NULL OR invocation.parent_task_step_id=dispatch.parent_task_step_id)"
            : "task.id=invocation.parent_task_run_id AND invocation.parent_task_step_id=dispatch.parent_task_step_id";
        return self::invocation() . " AND task.id=dispatch.parent_task_run_id AND {$parents}
            AND invocation.parent_workflow_run_id IS NOT DISTINCT FROM dispatch.parent_workflow_run_id
            AND invocation.parent_workflow_run_file_id IS NOT DISTINCT FROM dispatch.parent_workflow_run_file_id
            AND task_step.id=dispatch.parent_task_step_id AND task_step.task_id=task.id
            AND task.environment_id=usage_event.environment_id AND task.status=1 AND task.delete_time IS NULL
            AND (task.organization_id IS NULL OR task.organization_id=usage_event.organization_id)
            AND (task.application_id IS NULL OR task.application_id=usage_event.application_id)
            AND (task.workload_client_id IS NULL OR task.workload_client_id=usage_event.workload_client_id)";
    }

    public static function workflow(): string
    {
        return self::task() . " AND dispatch.execution_scope='structured_workflow'
            AND usage_event.source_kind='structured_workflow' AND usage_event.service_action='sand_ai.workflow.run'
            AND task.task_type='structured_workflow' AND task.resource_type='workflow_run'
            AND workflow_run.id=invocation.parent_workflow_run_id AND workflow_run.id=dispatch.parent_workflow_run_id
            AND workflow_run.task_id=task.id AND task.resource_id=workflow_run.id
            AND workflow_run.status=1 AND workflow_run.delete_time IS NULL
            AND workflow_run.environment_id=usage_event.environment_id
            AND workflow_run.organization_id=usage_event.organization_id AND workflow_run.application_id=usage_event.application_id
            AND workflow_run.workload_client_id=usage_event.workload_client_id
            AND jsonb_exists(workflow_run.definition_snapshot, 'data_class')
            AND workflow_run.definition_snapshot->'data_class' IS NOT DISTINCT FROM COALESCE(to_jsonb(invocation.data_class), 'null'::jsonb)
            AND " . self::attribution('workflow_run.attribution_snapshot') . "
            AND workflow_run.idempotency_application_ref=encode(sha256(convert_to('sand-ai/application-scope/v1:' ||
                (workflow_run.attribution_snapshot->'identity_snapshot'->>'application_ref'), 'UTF8')), 'hex')";
    }

    public static function agent(): string
    {
        return self::task(true) . " AND dispatch.execution_scope='agent'
            AND usage_event.source_kind='agent' AND usage_event.service_action='sand_ai.agent.run'
            AND task.task_type='agent_run' AND task.resource_type='agent_run' AND task.resource_id=task.id
            AND task.organization_id=usage_event.organization_id AND task.application_id=usage_event.application_id
            AND task.workload_client_id=usage_event.workload_client_id AND task.data_class IS NULL AND invocation.data_class IS NULL
            AND task_step.step_code=dispatch.workflow_node_code
            AND ((dispatch.workflow_node_code ~ '^agent_round_[1-3]$'
                    AND invocation.request_id='agent_' || task.id::text || '_' || substring(dispatch.workflow_node_code from 7))
                OR (dispatch.workflow_node_code='agent_summary_1'
                    AND invocation.request_id ~ ('^agent_' || task.id::text || '_summary_[a-f0-9]{32}$')))
            AND jsonb_exists(task.input, '_resource_data_class') AND task.input->'_resource_data_class'='null'::jsonb
            AND " . self::attribution("task.input->'_usage_attribution'") . "
            AND task.input->>'_trusted_identity_scope'=" . self::trustedScope("task.input->'_usage_attribution'");
    }

    public static function aiTask(): string
    {
        return self::task() . " AND dispatch.execution_scope='ai_task'
            AND usage_event.source_kind='ai_task' AND usage_event.service_action='sand_ai.chat.complete'
            AND task.task_type='ai_completion' AND task.resource_type='ai_task' AND task.resource_id=task.id
            AND task.organization_id=usage_event.organization_id AND task.application_id=usage_event.application_id
            AND task.workload_client_id=usage_event.workload_client_id
            AND jsonb_exists(task.input, '_resource_data_class')
            AND task.input->'_resource_data_class' IS NOT DISTINCT FROM COALESCE(to_jsonb(task.data_class), 'null'::jsonb)
            AND task.data_class IS NOT DISTINCT FROM invocation.data_class
            AND " . self::attribution("task.input->'_usage_attribution'") . "
            AND task.input->>'_trusted_identity_scope'=" . self::trustedScope("task.input->'_usage_attribution'");
    }

    /** Reuses the original queued identity proof for scalar task-owned resource projections. */
    public static function agentOwner(string $alias): string
    {
        if ($alias !== 'resource_agent') {
            throw new \InvalidArgumentException('Unsupported usage association alias');
        }
        return "{$alias}.task_type='agent_run' AND {$alias}.resource_type='agent_run'
            AND {$alias}.resource_id={$alias}.id AND {$alias}.status=1 AND {$alias}.delete_time IS NULL
            AND {$alias}.environment_id=usage_event.environment_id
            AND {$alias}.organization_id=usage_event.organization_id
            AND {$alias}.application_id=usage_event.application_id
            AND {$alias}.workload_client_id=usage_event.workload_client_id
            AND {$alias}.data_class IS NULL
            AND jsonb_exists({$alias}.input, '_resource_data_class')
            AND {$alias}.input->'_resource_data_class'='null'::jsonb
            AND " . self::attribution("{$alias}.input->'_usage_attribution'") . "
            AND {$alias}.input->>'_trusted_identity_scope'=" . self::trustedScope("{$alias}.input->'_usage_attribution'");
    }

    /** Frozen numeric projections must match the original identity references, never inferred names. */
    private static function attribution(string $json): string
    {
        $a = "({$json})";
        $parts = ["{$a}->'format_version'='1'::jsonb", "{$a}->'environment_id'=to_jsonb(usage_event.environment_id)"];
        foreach (['organization', 'application', 'workload_client'] as $stem) {
            $parts[] = "{$a}->'{$stem}_id'=to_jsonb(usage_event.{$stem}_id)";
            $parts[] = "{$a}->'identity_snapshot'->>'{$stem}_ref'=usage_event.identity_snapshot->>'{$stem}_ref'";
            $parts[] = "{$a}->'identity_snapshot'->>'{$stem}_ref' ~ '^[0-9]+$'";
            $parts[] = "COALESCE(NULLIF(ltrim({$a}->'identity_snapshot'->>'{$stem}_ref', '0'), ''), '0')=usage_event.{$stem}_id::text";
        }
        return implode(' AND ', $parts);
    }

    /** Matches RequestIdempotencyKey's canonical JSON; refs are verified decimal strings above. */
    private static function trustedScope(string $json): string
    {
        $a = "({$json})";
        $text = "'{\"version\":2,\"identity\":{\"format_version\":1,\"environment_id\":' || usage_event.environment_id::text";
        foreach (['organization', 'application', 'workload_client'] as $stem) {
            $text .= " || ',\"{$stem}_id\":' || usage_event.{$stem}_id::text || ',\"{$stem}_ref\":\"' || ({$a}->'identity_snapshot'->>'{$stem}_ref') || '\"'";
        }
        return "encode(sha256(convert_to({$text} || '}}', 'UTF8')), 'hex')";
    }

    /** A scalar correlated query cannot multiply immutable usage rows. Conflicting FK chains remain unassociated. */
    public static function knowledgeBase(): string
    {
        return ResourceUsageAssociationSql::knowledgeBaseId();
    }
}
