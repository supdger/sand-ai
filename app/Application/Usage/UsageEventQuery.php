<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use think\db\Query;
use think\facade\Db;

/** One persisted association/filter projection shared by summaries and event drilldowns. */
final class UsageEventQuery
{
    /** @param array<string,mixed> $filters @param list<array<string,mixed>> $scope */
    public function filtered(array $filters, array $scope): Query
    {
        $query = Db::connect('pgsql')->table('sand_ai_usage_event')->alias('usage_event')
            ->leftJoin('sand_ai_invocation invocation', 'invocation.id = usage_event.invocation_id')
            ->leftJoin('sand_ai_invocation_dispatch dispatch', 'dispatch.invocation_id = invocation.id')
            ->leftJoin('sand_ai_task_run task', "task.id = CASE WHEN dispatch.execution_scope='agent' AND usage_event.source_kind='agent' THEN dispatch.parent_task_run_id ELSE invocation.parent_task_run_id END")
            ->leftJoin('sand_ai_task_step task_step', "task_step.id = CASE WHEN dispatch.execution_scope='agent' AND usage_event.source_kind='agent' THEN dispatch.parent_task_step_id ELSE invocation.parent_task_step_id END")
            ->leftJoin('sand_ai_workflow_run workflow_run', 'workflow_run.id = invocation.parent_workflow_run_id')
            ->leftJoin('sand_ai_usage_reconciliation reconciliation', 'reconciliation.usage_event_id = usage_event.id')
            ->whereRaw('usage_event.service_action ~ ?', ['^sand_ai[.][a-z][a-z0-9_.-]*$'])
            ->where('usage_event.source_kind', '<>', 'admin_verify');
        // Only the internal, owned composition-cost path supplies two null
        // bounds. Public filters always receive dates from UsageSummaryPolicy.
        if ($filters['from_time'] === null && $filters['to_time'] === null) {
            if (!is_int($filters['composition_run_id'] ?? null) || $filters['composition_run_id'] < 1) {
                throw new ApiProblem('SAND_AI_USAGE_SCOPE_UNAVAILABLE', 'Lifetime usage requires one original composition run');
            }
        } else {
            $query->where('usage_event.create_time', '>=', $filters['from_time'])
                ->where('usage_event.create_time', '<', $filters['to_time']);
        }
        self::scope($query, $scope, 'usage_event');
        foreach (['organization_id', 'environment_id', 'application_id', 'provider_id', 'model_id', 'skill_id', 'skill_version_id', 'service_action', 'source_kind', 'capability_code', 'pricing_state', 'usage_state'] as $field) {
            if (($filters[$field] ?? null) !== null) $query->where('usage_event.' . $field, $filters[$field]);
        }
        if (($filters['workflow_run_id'] ?? null) !== null) {
            $query->whereRaw(UsageSummaryAssociationSql::workflow())->where('workflow_run.id', $filters['workflow_run_id']);
        }
        if (($filters['composition_run_id'] ?? null) !== null) {
            $query->whereRaw('(' . CompositionUsageAssociationSql::runId() . ') = ?', [(string) $filters['composition_run_id']]);
        }
        if (($filters['composition_node_code'] ?? null) !== null) {
            $query->whereRaw('(' . CompositionUsageAssociationSql::nodeKey() . ') = ?', [(string) $filters['composition_run_id'] . ':' . $filters['composition_node_code']]);
        }
        if (($filters['agent_run_id'] ?? null) !== null) {
            $query->whereRaw('(' . ResourceUsageAssociationSql::agentRunId() . ') = ?', [(string) $filters['agent_run_id']]);
        }
        if (($filters['knowledge_base_id'] ?? null) !== null) {
            $query->whereRaw('(' . UsageSummaryAssociationSql::knowledgeBase() . ') = ?', [(string) $filters['knowledge_base_id']]);
        }
        foreach (ResourceUsageAssociationSql::projections() as $field => $expression) {
            if (($filters[$field] ?? null) !== null) {
                $query->whereRaw('(' . $expression . ') = ?', [(string) $filters[$field]]);
            }
        }
        if (($filters['reconciliation_state'] ?? null) !== null) {
            if ($filters['reconciliation_state'] === 'unreconciled') $query->whereNull('reconciliation.id');
            else $query->where('reconciliation.state', $filters['reconciliation_state']);
        }
        return $query;
    }

    /** @param list<array<string,mixed>> $scope */
    public static function scope(Query $query, array $scope, string $alias): void
    {
        if (!in_array($alias, ['usage_event', 'invocation'], true) || count($scope) > 1000) {
            throw new ApiProblem('SAND_AI_USAGE_SCOPE_UNAVAILABLE', 'The usage scope projection is invalid');
        }
        if ($scope === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $clauses = [];
        $bindings = [];
        foreach ($scope as $row) {
            foreach (['id', 'organization_id', 'application_id'] as $field) {
                if (!is_int($row[$field] ?? null) || $row[$field] < 1) {
                    throw new ApiProblem('SAND_AI_USAGE_SCOPE_UNAVAILABLE', 'The usage scope contains incomplete official ownership facts');
                }
            }
            $clauses[] = "({$alias}.environment_id=? AND {$alias}.organization_id=? AND {$alias}.application_id=?)";
            array_push($bindings, $row['id'], $row['organization_id'], $row['application_id']);
        }
        $query->whereRaw('(' . implode(' OR ', $clauses) . ')', $bindings);
    }
}
