<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Api\Support\ApiProblem;
use app\Application\Usage\UsageSummaryPolicy;

/** Bounds the administrator's immutable usage-event list and its public fields. */
final class UsageEventListPolicy
{
    /** @param array<string,mixed> $input @return array<string,int|string|null> */
    public function normalize(array $input): array
    {
        $page = $this->positiveId($input['page'] ?? 1, 'page');
        $limit = $this->positiveId($input['limit'] ?? 10, 'limit');
        if ($limit > 100) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage list limit exceeds 100');
        }

        unset($input['page'], $input['limit']);
        // Drilldown and aggregation use identical closed filters and UTC range.
        return ['page' => $page, 'limit' => $limit] + (new UsageSummaryPolicy())->normalize($input);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function present(array $row): array
    {
        $hasTokens = ($row['usage_state'] ?? null) === 'measured'
            && ($row['metering_unit'] ?? null) === null;
        return [
            'id' => (int) $row['id'],
            'invocation_id' => $this->nullableInt($row['invocation_id'] ?? null),
            'tool_run_id' => $this->nullableInt($row['tool_run_id'] ?? null),
            'organization_id' => $this->nullableInt($row['organization_id'] ?? null),
            'application_id' => $this->nullableInt($row['application_id'] ?? null),
            'environment_id' => (int) $row['environment_id'],
            'provider_id' => $this->nullableInt($row['provider_id'] ?? null),
            'model_id' => $this->nullableInt($row['model_id'] ?? null),
            'service_code' => 'sand_ai',
            'skill_id' => $this->nullableInt($row['skill_id'] ?? null),
            'skill_version_id' => $this->nullableInt($row['skill_version_id'] ?? null),
            'skill_run_id' => $this->nullableInt($row['proved_skill_run_id'] ?? null),
            'composition_run_id' => $this->nullableInt($row['proved_composition_run_id'] ?? null),
            'composition_node_code' => ($row['proved_composition_node_key'] ?? null) === null ? null : explode(':', (string) $row['proved_composition_node_key'], 2)[1],
            'workflow_run_id' => $this->nullableInt($row['proved_workflow_run_id'] ?? null),
            'agent_run_id' => $this->nullableInt($row['proved_agent_run_id'] ?? null),
            'agent_definition_id' => $this->nullableInt($row['proved_agent_definition_id'] ?? null),
            'agent_version_id' => $this->nullableInt($row['proved_agent_version_id'] ?? null),
            'channel_binding_id' => $this->nullableInt($row['proved_channel_binding_id'] ?? null),
            'delivery_platform' => ($row['proved_delivery_platform'] ?? null) === null ? null : (string) $row['proved_delivery_platform'],
            'knowledge_base_id' => $this->nullableInt($row['proved_knowledge_base_id'] ?? null),
            'knowledge_base_version_id' => $this->nullableInt($row['proved_knowledge_base_version_id'] ?? null),
            'knowledge_query_id' => $this->nullableInt($row['proved_knowledge_query_id'] ?? null),
            'knowledge_index_id' => $this->nullableInt($row['proved_knowledge_index_id'] ?? null),
            'service_action' => (string) $row['service_action'],
            'source_kind' => (string) $row['source_kind'],
            'capability_code' => (string) $row['capability_code'],
            'metering_unit' => $row['metering_unit'] === null ? null : (string) $row['metering_unit'],
            'metering_quantity' => $row['metering_quantity'] === null ? null : (string) $row['metering_quantity'],
            'input_tokens' => $hasTokens ? (int) $row['input_tokens'] : null,
            'output_tokens' => $hasTokens ? (int) $row['output_tokens'] : null,
            'total_tokens' => $hasTokens ? (int) $row['total_tokens'] : null,
            'usage_state' => (string) $row['usage_state'],
            'price_revision_id' => $this->nullableInt($row['price_revision_id'] ?? null),
            'metered_amount' => $row['metered_amount'] === null ? null : (string) $row['metered_amount'],
            'currency' => $row['currency'] === null ? null : (string) $row['currency'],
            'pricing_state' => (string) $row['pricing_state'],
            'create_time' => (string) $row['create_time'],
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$field} must be a positive integer");
        }
        return $result;
    }

}
