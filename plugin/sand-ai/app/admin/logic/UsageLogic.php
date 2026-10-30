<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Application\Usage\UsageEventQuery;
use app\Application\Usage\UsageSummaryAssociationSql;
use app\Application\Usage\ResourceUsageAssociationSql;

/** Administrator list sourced from immutable, attributed usage events. */
final class UsageLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input, mixed $adminInfo): array
    {
        $policy = new UsageEventListPolicy();
        $filters = $policy->normalize($input);
        $scope = (new UsageAdminScopeLogic())->resolve($adminInfo, $filters);
        $query = (new UsageEventQuery())->filtered($filters, $scope)->order('usage_event.create_time', 'desc')
            ->order('usage_event.id', 'desc')
            ->field('usage_event.id,usage_event.invocation_id,usage_event.tool_run_id,usage_event.organization_id,usage_event.application_id,usage_event.environment_id,usage_event.provider_id,usage_event.model_id,usage_event.skill_id,usage_event.skill_version_id,usage_event.service_action,usage_event.source_kind,usage_event.capability_code,usage_event.metering_unit,usage_event.metering_quantity,usage_event.input_tokens,usage_event.output_tokens,usage_event.total_tokens,usage_event.usage_state,usage_event.price_revision_id,usage_event.metered_amount,usage_event.currency,usage_event.pricing_state,usage_event.create_time')
            ->fieldRaw('CASE WHEN ' . UsageSummaryAssociationSql::workflow() . ' THEN workflow_run.id ELSE NULL END AS proved_workflow_run_id')
            ->fieldRaw('(' . \app\Application\Usage\CompositionUsageAssociationSql::runId() . ') AS proved_composition_run_id')
            ->fieldRaw('(' . \app\Application\Usage\CompositionUsageAssociationSql::nodeKey() . ') AS proved_composition_node_key')
            ->fieldRaw('(' . ResourceUsageAssociationSql::agentRunId() . ') AS proved_agent_run_id')
            ->fieldRaw('(' . UsageSummaryAssociationSql::knowledgeBase() . ') AS proved_knowledge_base_id');
        foreach (ResourceUsageAssociationSql::projections() as $field => $expression) {
            $query->fieldRaw('(' . $expression . ') AS proved_' . $field);
        }
        $result = $query->paginate(['page' => $filters['page'], 'list_rows' => $filters['limit']])->toArray();
        $result['data'] = array_map([$policy, 'present'], $result['data']);
        return $result;
    }
}
