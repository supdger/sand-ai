<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use think\db\Query;
use think\facade\Db;

/** Admin-only aggregates sourced exclusively from immutable usage events. */
final class UsageSummaryGateway
{
    private const GROUP_LIMIT = 100;

    /** @param array<string,int|string|null> $filters @param list<array<string,mixed>> $scope */
    public function compositionBreakdown(array $filters, array $scope): array
    {
        return $this->readSnapshot(fn (): array => $this->aggregates($filters, $scope, [
            'overall' => ["'all'", 'composition_cost'],
            'nodes' => [CompositionUsageAssociationSql::nodeKey(), 'composition_node'],
        ]));
    }

    /** @param array<string, int|string|null> $filters @return array<string, mixed> */
    public function summary(array $filters, array $scope): array
    {
        return $this->readSnapshot(fn (): array => $this->summaryRows($filters, $scope));
    }

    /** The authorization scope is already resolved by the calling entrypoint. */
    private function summaryRows(array $filters, array $scope): array
    {
        // Both monetary facts and quote coverage use the same persisted dimensions.
        $aggregates = $this->aggregates($filters, $scope, [
            'by_service' => ["'sand_ai'", 'service'],
            'overall' => ["'all'", 'overall'],
            'by_application' => ["COALESCE(usage_event.application_id::text, 'unattributed')", 'application'],
            'by_service_action' => ['usage_event.service_action', 'service_action'],
            'by_capability' => ['usage_event.capability_code', 'capability'],
            'by_skill' => ["COALESCE(usage_event.skill_id::text, 'unattributed')", 'skill'],
            'by_skill_version' => ["COALESCE(usage_event.skill_version_id::text, 'unattributed')", 'skill_version'],
            'by_skill_run' => [ResourceUsageAssociationSql::skillRunId(), 'skill_run'],
            'by_provider' => ["COALESCE(usage_event.provider_id::text, 'unattributed')", 'provider'],
            'by_model' => ["COALESCE(usage_event.model_id::text, 'unattributed')", 'model'],
            'by_organization' => ["usage_event.organization_id::text", 'organization'],
            'by_environment' => ["usage_event.environment_id::text", 'environment'],
            'by_source_kind' => ['usage_event.source_kind', 'source_kind'],
            'by_workflow_run' => ['CASE WHEN ' . UsageSummaryAssociationSql::workflow() . ' THEN workflow_run.id::text ELSE NULL END', 'workflow_run'],
            'by_composition_run' => [CompositionUsageAssociationSql::runId(), 'composition_run'],
            'by_composition_node' => [CompositionUsageAssociationSql::nodeKey(), 'composition_node'],
            'by_agent_run' => [ResourceUsageAssociationSql::agentRunId(), 'agent_run'],
            'by_agent_definition' => [ResourceUsageAssociationSql::agentDefinitionId(), 'agent_definition'],
            'by_agent_version' => [ResourceUsageAssociationSql::agentVersionId(), 'agent_version'],
            'by_channel_binding' => [ResourceUsageAssociationSql::channelBindingId(), 'channel_binding'],
            'by_delivery_platform' => [ResourceUsageAssociationSql::deliveryPlatform(), 'delivery_platform'],
            'by_knowledge_base' => [UsageSummaryAssociationSql::knowledgeBase(), 'knowledge_base'],
            'by_knowledge_base_version' => [ResourceUsageAssociationSql::knowledgeVersionId(), 'knowledge_base_version'],
            'by_knowledge_query' => [ResourceUsageAssociationSql::knowledgeQueryId(), 'knowledge_query'],
            'by_knowledge_index' => [ResourceUsageAssociationSql::knowledgeIndexId(), 'knowledge_index'],
        ]);
        return [
            'filters' => $filters,
            'scope' => ['service_code' => 'sand_ai', 'coverage' => 'authorized_environment_subtotal', 'environment_ids' => array_values(array_unique(array_column($scope, 'id')))],
            ...$aggregates,
            'ambiguous' => $this->ambiguous($filters, $scope),
        ];
    }

    /** @param array<string,array{0:string,1:string}> $dimensions */
    private function aggregates(array $filters, array $scope, array $dimensions): array
    {
        $groups = [];
        foreach ($dimensions as $group => [$expression, $dimension]) {
            $groups[$group] = $this->aggregate($filters, $scope, $expression, $dimension);
        }
        return (new UsageSummaryEstimateProjection())->present($groups, $filters, $scope, $dimensions);
    }

    /** Keep mutable reconciliation filters and original quote proof on one cohort. */
    private function readSnapshot(\Closure $read): array
    {
        $connection = Db::connect('pgsql');
        if ($connection->getPdo()->inTransaction()) {
            $isolation = $connection->query('SHOW transaction_isolation')[0]['transaction_isolation'] ?? null;
            if (!in_array($isolation, ['repeatable read', 'serializable'], true)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '费用读取尚未固定快照，请只读刷新原范围');
            }
            return $read();
        }
        return $connection->transaction(function () use ($connection, $read): array {
            $connection->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return $read();
        });
    }

    /** @param array<string, int|string|null> $filters @param list<array<string,mixed>> $scope @return array<string,mixed> */
    private function ambiguous(array $filters, array $scope): array
    {
        // These dimensions require an immutable usage event's association
        // proof. A dispatch without that event cannot supply a counted cohort.
        foreach (array_merge(['composition_run_id', 'workflow_run_id', 'agent_run_id', 'knowledge_base_id'], array_keys(ResourceUsageAssociationSql::projections())) as $field) {
            if (($filters[$field] ?? null) !== null) {
                return ['count' => null, 'held_amount' => null, 'tokens' => null, 'actual_amount' => null, 'scope_state' => 'association_unavailable'];
            }
        }
        $query = Db::connect('pgsql')->table('sand_ai_invocation_dispatch')->alias('dispatch')
            ->join('sand_ai_invocation invocation', 'invocation.id = dispatch.invocation_id')
            ->where('invocation.status', 1)->whereNull('invocation.delete_time')
            ->whereRaw('dispatch.environment_id=invocation.environment_id AND dispatch.application_id=invocation.application_id')
            ->whereRaw('dispatch.service_action ~ ?', ['^sand_ai[.][a-z][a-z0-9_.-]*$'])
            ->where('dispatch.state', 'ambiguous')
            ->where('dispatch.create_time', '>=', (string) $filters['from_time'])
            ->where('dispatch.create_time', '<', (string) $filters['to_time'])
            ->whereRaw(AmbiguousUsageProjectionPolicy::noUsageEventPredicate());
        UsageEventQuery::scope($query, $scope, 'invocation');
        if ($filters['organization_id'] !== null) $query->where('invocation.organization_id', $filters['organization_id']);
        if ($filters['environment_id'] !== null) $query->where('dispatch.environment_id', $filters['environment_id']);
        foreach (['application_id', 'provider_id', 'model_id', 'skill_id', 'skill_version_id', 'service_action', 'source_kind', 'capability_code'] as $field) {
            if ($filters[$field] !== null) $query->where('dispatch.' . $field, $filters[$field]);
        }
        if ($filters['usage_state'] !== null && $filters['usage_state'] !== 'unmeasured') return ['count' => 0, 'held_amount' => null, 'tokens' => null, 'actual_amount' => null, 'scope_state' => 'verified'];
        if ($filters['pricing_state'] !== null) {
            $query->whereRaw("dispatch.price_snapshot->>'pricing_state' = ?", [$filters['pricing_state']]);
        }
        if ($filters['reconciliation_state'] !== null && $filters['reconciliation_state'] !== 'unreconciled') return ['count' => 0, 'held_amount' => null, 'tokens' => null, 'actual_amount' => null, 'scope_state' => 'verified'];
        return ['count' => (int) $query->count(), 'held_amount' => null, 'tokens' => null, 'actual_amount' => null, 'scope_state' => 'verified'];
    }

    /** @param array<string, int|string|null> $filters @return array{rows: list<array<string, mixed>>, truncated: bool} */
    private function aggregate(array $filters, array $scope, string $expression, string $dimension): array
    {
        // Group by the selected alias rather than feeding a JSON expression
        // back through ThinkORM's key parser. That parser treats `->>` as a
        // field accessor and corrupts PostgreSQL JSON text operators. The
        // overall view remains safely separated by currency.
        $groupBy = $dimension === 'overall'
            ? 'usage_event.currency'
            : 'dimension_value, usage_event.currency';
        $businessFailed = '(' . UsageSummaryAssociationSql::workflow() . ' OR ' . UsageSummaryAssociationSql::agent() . ' OR ' . UsageSummaryAssociationSql::aiTask() . ") AND task.state='failed'";
        $businessFailed = '((' . $businessFailed . ') OR (' . CompositionUsageAssociationSql::failed() . ') OR (' . ResourceUsageAssociationSql::failedAgent() . ') OR (' . ResourceUsageAssociationSql::failedSkill() . '))';
        $rows = $this->filtered($filters, $scope)
            ->fieldRaw("{$expression} AS dimension_value, usage_event.currency, COUNT(*) AS total_events, SUM(CASE WHEN usage_event.usage_state = 'measured' THEN 1 ELSE 0 END) AS measured_events, SUM(CASE WHEN usage_event.pricing_state = 'priced' THEN 1 ELSE 0 END) AS priced_events, SUM(CASE WHEN usage_event.usage_state = 'unmeasured' THEN 1 ELSE 0 END) AS unmeasured_events, SUM(CASE WHEN usage_event.pricing_state = 'unpriced' THEN 1 ELSE 0 END) AS unpriced_events, SUM(CASE WHEN invocation.state = 'failed' THEN 1 ELSE 0 END) AS failed_events, SUM(CASE WHEN invocation.state = 'failed' AND (usage_event.usage_state <> 'measured' OR usage_event.pricing_state <> 'priced') THEN 1 ELSE 0 END) AS failed_unknown_amount_events, SUM(CASE WHEN invocation.state = 'failed' AND usage_event.usage_state = 'measured' AND usage_event.pricing_state = 'priced' THEN usage_event.metered_amount ELSE NULL END) AS failed_metered_amount, SUM(CASE WHEN {$businessFailed} THEN 1 ELSE 0 END) AS business_failed_events, SUM(CASE WHEN {$businessFailed} AND (usage_event.usage_state <> 'measured' OR usage_event.pricing_state <> 'priced' OR usage_event.metered_amount IS NULL) THEN 1 ELSE 0 END) AS business_failed_unknown_amount_events, SUM(CASE WHEN {$businessFailed} AND usage_event.usage_state = 'measured' AND usage_event.pricing_state = 'priced' THEN usage_event.metered_amount ELSE NULL END) AS business_failed_metered_amount, SUM(CASE WHEN usage_event.usage_state = 'measured' AND usage_event.pricing_state = 'priced' THEN usage_event.metered_amount ELSE NULL END) AS metered_amount, SUM(CASE WHEN reconciliation.state = 'reconciled' THEN 1 ELSE 0 END) AS reconciled_events, SUM(CASE WHEN reconciliation.state = 'discrepancy' THEN 1 ELSE 0 END) AS discrepancy_events, SUM(CASE WHEN reconciliation.id IS NULL THEN 1 ELSE 0 END) AS unreconciled_events, SUM(CASE WHEN reconciliation.state = 'reconciled' THEN reconciliation.billed_amount ELSE NULL END) AS reconciled_amount, SUM(CASE WHEN reconciliation.state IN ('reconciled', 'discrepancy') THEN reconciliation.amount_difference ELSE NULL END) AS difference_amount, SUM(CASE WHEN usage_event.usage_state<>'measured' OR usage_event.pricing_state<>'priced' OR usage_event.metered_amount IS NULL THEN 1 ELSE 0 END) AS unknown_cost_events, SUM(CASE WHEN reconciliation.state='reconciled' AND reconciliation.billed_amount IS NOT NULL AND reconciliation.amount_difference IS NOT NULL THEN 0 ELSE 1 END) AS unknown_bill_events")
            ->group($groupBy)
            ->orderRaw('usage_event.currency ASC NULLS LAST')
            ->orderRaw('metered_amount DESC NULLS LAST')
            ->orderRaw('dimension_value ASC')
            ->limit(self::GROUP_LIMIT + 1)
            ->select()
            ->toArray();
        $truncated = count($rows) > self::GROUP_LIMIT;
        if ($truncated) {
            array_pop($rows);
        }

        $presented = (new UsageSummaryReducer())->rows($rows, $dimension);
        if (in_array($dimension, ['composition_cost', 'composition_run', 'composition_node', 'skill_run', 'agent_run', 'agent_definition', 'agent_version', 'channel_binding', 'delivery_platform', 'knowledge_base', 'knowledge_base_version', 'knowledge_query', 'knowledge_index'], true)) {
            // Missing prices and partial bills cannot become a full run total.
            // Reuse the existing decimal aggregates; only withhold incomplete amounts.
            $proof = [];
            foreach ($rows as $row) $proof[json_encode([$row['dimension_value'], $row['currency']], JSON_THROW_ON_ERROR)] = $row;
            foreach ($presented as &$row) {
                $original = $proof[json_encode([$row['value'], $row['currency']], JSON_THROW_ON_ERROR)];
                if ((int) $original['unknown_cost_events'] > 0) $row['metered_amount'] = null;
                if ((int) $original['unknown_bill_events'] > 0) {
                    $row['reconciled_amount'] = null;
                    $row['difference_amount'] = null;
                }
            }
            unset($row);
        }
        return ['rows' => $presented, 'truncated' => $truncated];
    }

    /** @param array<string, int|string|null> $filters @param list<array<string,mixed>> $scope */
    private function filtered(array $filters, array $scope): Query
    {
        return (new UsageEventQuery())->filtered($filters, $scope);
    }
}
