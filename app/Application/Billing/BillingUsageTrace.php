<?php

declare(strict_types=1);

namespace app\Application\Billing;

use app\Application\Usage\UsageEventQuery;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\InvocationReadFacts;
use think\facade\Db;

/** An optional trace locator, never a replacement for the target cost permission. */
final class BillingUsageTrace
{
    /** @param list<array<string,mixed>> $scope Official administrator environment directory facts. */
    public function __construct(private readonly array $scope)
    {
    }

    /** @return array{usage_event_id:int,environment_id:int,invocation_id:int}|null */
    public function project(int $billingLineId, ?int $usageEventId, ?int $expectedEnvironmentId = null): ?array
    {
        if ($billingLineId < 1 || $usageEventId === null || $usageEventId < 1 || $this->scope === []) return null;
        $query = Db::connect('pgsql')->table('sand_ai_usage_event')->alias('usage_event')
            ->join('sand_ai_invocation invocation', 'invocation.id=usage_event.invocation_id')
            ->join('sand_ai_invocation_dispatch dispatch', 'dispatch.invocation_id=invocation.id')
            ->join('sand_ai_billing_line billing_line', 'billing_line.id=' . $billingLineId)
            ->join('sand_ai_billing_import billing_import', 'billing_import.id=billing_line.billing_import_id')
            ->where('usage_event.id', $usageEventId)
            ->whereRaw("(EXISTS (SELECT 1 FROM sand_ai_usage_reconciliation linked WHERE linked.billing_line_id=billing_line.id AND linked.usage_event_id=usage_event.id)
                OR EXISTS (SELECT 1 FROM sand_ai_billing_review reviewed WHERE reviewed.billing_line_id=billing_line.id
                    AND reviewed.usage_event_id=usage_event.id AND reviewed.environment_id=usage_event.environment_id AND reviewed.decision='compare'))");
        // The server computes this scope; a caller cannot provide an env list.
        UsageEventQuery::scope($query, $this->scope, 'usage_event');
        if ($expectedEnvironmentId !== null) $query->where('usage_event.environment_id', $expectedEnvironmentId);
        $row = $query->field([
            'usage_event.id' => 'event_id', 'usage_event.invocation_id' => 'event_invocation_id',
            'usage_event.organization_id' => 'event_organization_id', 'usage_event.application_id' => 'event_application_id',
            'usage_event.environment_id' => 'event_environment_id', 'usage_event.workload_client_id' => 'event_workload_client_id',
            'usage_event.provider_id' => 'event_provider_id', 'usage_event.model_id' => 'event_model_id',
            'usage_event.deployment_id' => 'event_deployment_id', 'usage_event.capability_code' => 'event_capability_code',
            'usage_event.service_action' => 'event_service_action', 'usage_event.source_kind' => 'event_source_kind',
            'usage_event.currency' => 'event_currency', 'usage_event.pricing_state' => 'event_pricing_state',
            'usage_event.create_time' => 'event_create_time',
            'invocation.id' => 'invocation_id', 'invocation.status' => 'invocation_status',
            'invocation.delete_time' => 'invocation_delete_time', 'invocation.data_class' => 'invocation_data_class',
            'invocation.organization_id' => 'invocation_organization_id', 'invocation.application_id' => 'invocation_application_id',
            'invocation.environment_id' => 'invocation_environment_id', 'invocation.workload_client_id' => 'invocation_workload_client_id',
            'invocation.model_id' => 'invocation_model_id', 'invocation.deployment_id' => 'invocation_deployment_id',
            'dispatch.invocation_id' => 'dispatch_invocation_id', 'dispatch.environment_id' => 'dispatch_environment_id',
            'dispatch.application_id' => 'dispatch_application_id', 'dispatch.execution_scope' => 'dispatch_execution_scope',
            'dispatch.source_kind' => 'dispatch_source_kind', 'dispatch.service_action' => 'dispatch_service_action',
            'dispatch.provider_id' => 'dispatch_provider_id', 'dispatch.model_id' => 'dispatch_model_id',
            'dispatch.deployment_id' => 'dispatch_deployment_id', 'dispatch.capability_code' => 'dispatch_capability_code',
            'billing_import.provider_id' => 'line_provider_id', 'billing_line.model_code' => 'line_model_code',
            'billing_line.capability_code' => 'line_capability_code', 'billing_line.meter_code' => 'line_meter_code',
            'billing_line.currency' => 'line_currency', 'billing_line.occurred_start' => 'line_occurred_start',
            'billing_line.occurred_end' => 'line_occurred_end',
        ])->fieldRaw("usage_event.model_snapshot->>'code' AS event_model_code")->find();
        if (!is_array($row)) return null;
        $invocation = [];
        foreach (['id', 'status', 'delete_time', 'organization_id', 'application_id', 'environment_id', 'workload_client_id', 'data_class'] as $field) {
            $invocation[$field] = $row['invocation_' . $field] ?? null;
        }
        $dispatch = [];
        foreach (['invocation_id', 'environment_id', 'application_id', 'execution_scope', 'source_kind', 'service_action'] as $field) {
            $dispatch[$field] = $row['dispatch_' . $field] ?? null;
        }
        $invocationId = (int) ($row['invocation_id'] ?? 0);
        try {
            $facts = InvocationReadFacts::fromPersisted($invocation, $dispatch, $invocationId);
        } catch (IdentityContextException) {
            return null;
        }
        if ((int) $row['event_id'] !== $usageEventId || (int) $row['event_invocation_id'] !== $invocationId) return null;
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if ($row['event_' . $field] === null || (int) $row['event_' . $field] !== $facts[$field]) return null;
        }
        foreach (['provider_id', 'model_id', 'deployment_id'] as $field) {
            if ((int) $row['event_' . $field] < 1
                || (int) $row['event_' . $field] !== (int) $row['dispatch_' . $field]) return null;
        }
        foreach (['model_id', 'deployment_id'] as $field) {
            if ((int) $row['event_' . $field] !== (int) $row['invocation_' . $field]) return null;
        }
        foreach (['capability_code', 'source_kind', 'service_action'] as $field) {
            if (!is_string($row['event_' . $field]) || $row['event_' . $field] !== $row['dispatch_' . $field]) return null;
        }
        if ($row['line_meter_code'] !== 'total_tokens'
            || (int) $row['line_provider_id'] !== (int) $row['event_provider_id']
            || $row['line_capability_code'] !== $row['event_capability_code']
            || $row['line_model_code'] !== $row['event_model_code']
            || ($row['event_pricing_state'] === 'priced' && $row['line_currency'] !== $row['event_currency'])
            || strcmp((string) $row['event_create_time'], (string) $row['line_occurred_start']) < 0
            || strcmp((string) $row['event_create_time'], (string) $row['line_occurred_end']) >= 0) return null;
        return ['usage_event_id' => $usageEventId, 'environment_id' => $facts['environment_id'], 'invocation_id' => $invocationId];
    }
}
