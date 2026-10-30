<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\UsageEvent;

final class UsageGateway
{
    // All billing and refund facts below belong to the event SELECT's single
    // PostgreSQL statement snapshot, even during a concurrent import/refund.
    private const BILLING_PROJECTION = 'reconciliation.id AS billing_reconciliation_id,
        reconciliation.state AS billing_reconciliation_state,
        reconciliation.reason AS billing_reconciliation_reason,
        reconciliation.currency AS billing_reconciliation_currency,
        reconciliation.quantity_difference AS billing_reconciliation_quantity_difference,
        reconciliation.billed_amount AS billing_reconciliation_billed_amount,
        reconciliation.amount_difference AS billing_reconciliation_difference_amount,
        billing_line.amount AS billing_line_amount,
        billing_line.currency AS billing_line_currency,
        billing_import.provider_id AS billing_provider_id,
        (SELECT COUNT(*) FROM sand_ai_usage_adjustment adjustment
         WHERE adjustment.usage_event_id = usage_event.id
           AND adjustment.environment_id = usage_event.environment_id) AS billing_adjustment_count,
        (SELECT COALESCE(SUM(adjustment.refund_amount),0)::text FROM sand_ai_usage_adjustment adjustment
         WHERE adjustment.usage_event_id = usage_event.id
           AND adjustment.environment_id = usage_event.environment_id) AS billing_refund_amount';

    /** @return array<string, mixed> */
    public function invocationCost(int $environmentId, string $requestId, callable $authorizeRead): array
    {
        $invocation = Invocation::where('environment_id', $environmentId)->where('request_id', $requestId)->find();
        if ($invocation === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Invocation not found');
        }
        $authorizeRead($invocation);
        $event = UsageEvent::alias('usage_event')
            ->leftJoin('sand_ai_usage_reconciliation reconciliation', 'reconciliation.usage_event_id = usage_event.id')
            ->leftJoin('sand_ai_billing_line billing_line', 'billing_line.id = reconciliation.billing_line_id')
            ->leftJoin('sand_ai_billing_import billing_import', 'billing_import.id = billing_line.billing_import_id')
            ->leftJoin('sand_ai_skill skill', 'skill.id = usage_event.skill_id')
            ->leftJoin('sand_ai_skill_version skill_version', 'skill_version.id = usage_event.skill_version_id AND skill_version.skill_id = usage_event.skill_id')
            ->field('usage_event.*')
            ->fieldRaw(self::BILLING_PROJECTION)
            ->fieldRaw('skill.code AS skill_code, skill_version.version_no AS skill_version_no')
            ->where('usage_event.environment_id', $environmentId)
            ->where('usage_event.invocation_id', (int) $invocation->id)
            ->find();
        if ($event === null) {
            $dispatch = InvocationDispatch::where('environment_id', $environmentId)
                ->where('invocation_id', (int) $invocation->id)->find();
            if ($dispatch !== null) {
                return $this->withEstimate($invocation,
                    $this->dispatchCostResponse((string) $invocation->request_id, $dispatch), null, $dispatch);
            }
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Usage event not found');
        }
        $this->assertEventOwnership($invocation, $event);

        $response = $this->costResponse((string) $invocation->request_id, $event);
        $response['operator_review'] = (new \app\Application\Billing\BillingReviewService())->forEvent((int) $event->id);
        return $this->withEstimate($invocation, $response, $event);
    }

    /**
     * Internal bounded projection for a task's already selected dispatch page.
     * The mandatory callback authorizes every invocation before any cost is
     * returned. Operator billing reviews are not part of the meeting cost DTO.
     *
     * @param list<InvocationDispatch> $dispatches
     * @param \Closure(Invocation,InvocationDispatch):void $authorizeRead
     * @param \Closure(bool=):void $pulse
     * @return array<int,array<string,mixed>> Costs keyed by invocation ID.
     */
    public function invocationCostsForDispatches(int $environmentId, array $dispatches, \Closure $authorizeRead, \Closure $pulse): array
    {
        if ($dispatches === [] || count($dispatches) > 32) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Cost projection page is invalid');
        }
        $byInvocation = [];
        foreach ($dispatches as $dispatch) {
            $pulse();
            $id = (int) $dispatch->invocation_id;
            if ($id < 1 || (int) $dispatch->environment_id !== $environmentId || isset($byInvocation[$id])) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Cost projection dispatch identity changed');
            }
            $byInvocation[$id] = $dispatch;
        }
        $ids = array_keys($byInvocation);
        $pulse();
        $records = Invocation::where('environment_id', $environmentId)->whereIn('id', $ids)
            ->field('id,request_id,request_fingerprint,organization_id,application_id,environment_id,workload_client_id,model_id,deployment_id,call_started_at,call_start_pricing_state,call_start_price_revision_id,call_start_price_snapshot')
            ->limit(count($ids) + 1)->select();
        $pulse();
        $invocations = [];
        foreach ($records as $invocation) {
            $pulse();
            $id = (int) $invocation->id;
            if (!isset($byInvocation[$id]) || isset($invocations[$id])) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Cost projection invocation identity changed');
            }
            $authorizeRead($invocation, $byInvocation[$id]);
            $invocations[$id] = $invocation;
        }
        if (count($invocations) !== count($ids)) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Meeting node invocation is unavailable');
        }
        unset($records, $invocation);
        $pulse();
        $records = UsageEvent::alias('usage_event')
            ->leftJoin('sand_ai_usage_reconciliation reconciliation', 'reconciliation.usage_event_id = usage_event.id')
            ->leftJoin('sand_ai_billing_line billing_line', 'billing_line.id = reconciliation.billing_line_id')
            ->leftJoin('sand_ai_billing_import billing_import', 'billing_import.id = billing_line.billing_import_id')
            ->leftJoin('sand_ai_skill skill', 'skill.id = usage_event.skill_id')
            ->leftJoin('sand_ai_skill_version skill_version', 'skill_version.id = usage_event.skill_version_id AND skill_version.skill_id = usage_event.skill_id')
            ->field('usage_event.*')
            ->fieldRaw(self::BILLING_PROJECTION)
            ->fieldRaw('skill.code AS skill_code, skill_version.version_no AS skill_version_no')
            ->where('usage_event.environment_id', $environmentId)->whereIn('usage_event.invocation_id', $ids)
            ->limit(count($ids) + 1)->select();
        $pulse();
        $costs = [];
        foreach ($records as $event) {
            $pulse();
            $id = (int) $event->invocation_id;
            if (!isset($invocations[$id]) || isset($costs[$id])) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Cost projection usage identity changed');
            }
            $invocation = $invocations[$id];
            $this->assertEventOwnership($invocation, $event);
            $costs[$id] = $this->withEstimate($invocation,
                $this->costResponse((string) $invocation->request_id, $event), $event, $byInvocation[$id]);
        }
        foreach ($invocations as $invocationId => $invocation) {
            $pulse();
            if (!isset($costs[$invocationId])) {
                // Keep the existing unknown/pending/ambiguous semantics using
                // this exact dispatch, never another request or a price estimate.
                $costs[$invocationId] = $this->withEstimate($invocation,
                    $this->dispatchCostResponse((string) $invocation->request_id, $byInvocation[$invocationId]), null, $byInvocation[$invocationId]);
            }
        }
        return $costs;
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function withEstimate(Invocation $invocation, array $response, ?UsageEvent $event, ?InvocationDispatch $dispatch = null): array
    {
        $response['price'] = array_replace($response['price'],
            (new InvocationCostEstimateProjection())->present($invocation, $event, $dispatch));
        $response['provider_response_identity'] = (new \app\Application\Gateway\ProviderResponseIdentityStore())->present($invocation);
        return $response;
    }

    private function assertEventOwnership(Invocation $invocation, UsageEvent $event): void
    {
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            $owner = $invocation->getAttr($field);
            $attributed = $event->getAttr($field);
            if ($owner === null || $attributed === null
                || (int) $owner < 1 || (int) $attributed !== (int) $owner) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Usage event ownership differs from its invocation');
            }
        }
    }

    /** @return array<string,mixed> */
    private function dispatchCostResponse(string $requestId, InvocationDispatch $dispatch): array
    {
        $state = (string) $dispatch->state;
        [$usageState, $reconciliationState, $reason] = match ($state) {
            'not_dispatched' => ['not_dispatched', 'not_applicable', 'provider_not_dispatched'],
            'failed' => ['failed', 'held', 'provider_rejected_without_usage'],
            'ambiguous' => ['ambiguous', 'held', 'dispatch_ambiguous'],
            'prepared', 'dispatching' => ['pending', 'pending', 'dispatch_in_progress'],
            'succeeded' => ['missing_event', 'held', 'usage_event_missing'],
            default => throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Dispatch cost state is unavailable'),
        };
        $route = $this->snapshot($dispatch->route_snapshot);
        $price = $this->dispatchPrice($dispatch->price_snapshot);
        return [
            'operator_review' => null,
            'adjustments' => null,
            'request_id' => $requestId,
            'provider' => is_string($route['provider_code'] ?? null) ? $route['provider_code'] : null,
            'model' => null,
            'deployment_id' => (int) $dispatch->deployment_id,
            'capability' => (string) $dispatch->capability_code,
            'service_action' => $dispatch->service_action === null ? null : (string) $dispatch->service_action,
            'source_kind' => $this->dispatchSourceKind($dispatch),
            'usage' => ['state' => $usageState, 'metering_unit' => null, 'metering_quantity' => null],
            'price' => ['revision_id' => $price['revision_id'], 'estimated_amount' => null, 'metered_amount' => null, 'pricing_state' => $price['pricing_state'], 'currency' => $price['currency'], 'breakdown_state' => 'unavailable', 'breakdown' => null],
            'tokens' => ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null],
            'skill' => null,
            'reconciliation' => ['state' => $reconciliationState, 'reason' => $reason, 'billed_amount' => null, 'reconciled_amount' => null, 'difference_amount' => null],
        ];
    }

    /** @return array{revision_id:?int,pricing_state:string,currency:?string} */
    private function dispatchPrice(mixed $value): array
    {
        $snapshot = $this->snapshot($value);
        try {
            $priceData = $snapshot['price_snapshot'] ?? null;
            if (is_array($priceData) && in_array($priceData['metering_unit'] ?? null, ['rerank_search_units', 'rerank_tokens'], true)) {
                $professional = \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::fromPersistence(
                    $snapshot['called_at'] ?? null, $snapshot['pricing_state'] ?? null,
                    $snapshot['price_revision_id'] ?? null, $priceData,
                );
                return ['revision_id' => $professional->revisionId,
                    'pricing_state' => $professional->revisionId === null ? 'unpriced' : 'priced', 'currency' => $professional->currency];
            }
            $frozen = \app\Application\Usage\CallStartPricingSnapshot::fromPersistence(
                $snapshot['called_at'] ?? null, $snapshot['pricing_state'] ?? null, $snapshot['price_revision_id'] ?? null, $snapshot['price_snapshot'] ?? null,
            );
            $resolved = $frozen->resolvedPrice();
            return ['revision_id' => $frozen->priceRevisionId, 'pricing_state' => $frozen->pricingState, 'currency' => $resolved?->currency];
        } catch (ApiProblem) {
            // An invalid frozen price must not become an invented amount.
            return ['revision_id' => null, 'pricing_state' => 'unpriced', 'currency' => null];
        }
    }

    /** @return array<string, mixed> */
    private function costResponse(string $requestId, UsageEvent $event): array
    {
        $reconciliationState = $event->billing_reconciliation_state === null
            ? 'unreconciled'
            : (string) $event->billing_reconciliation_state;
        $billedAmount = $event->billing_reconciliation_billed_amount === null
            ? null
            : (string) $event->billing_reconciliation_billed_amount;
        $reconciledAmount = $reconciliationState === 'reconciled' ? $billedAmount : null;
        $providerSnapshot = $this->snapshot($event->provider_snapshot);
        $modelSnapshot = $this->snapshot($event->model_snapshot);
        $breakdown = (new InvocationCostBreakdown())->present($event);
        $measured = $breakdown['metering_unit'] === 'total_tokens';

        return [
            'request_id' => $requestId,
            'adjustments' => (new UsageRefundService())->summaryForCostEvent($event),
            'provider' => $providerSnapshot['code'] ?? null,
            'model' => $modelSnapshot['code'] ?? null,
            'deployment_id' => (int) $event->deployment_id,
            'capability' => (string) $event->capability_code,
            'service_action' => (string) $event->service_action,
            'source_kind' => (string) $event->source_kind,
            'usage' => [
                'state' => (string) $event->usage_state,
                'metering_unit' => $breakdown['metering_unit'],
                'metering_quantity' => $breakdown['metering_quantity'],
            ],
            'price' => [
                'revision_id' => $event->price_revision_id === null ? null : (int) $event->price_revision_id,
                'estimated_amount' => null,
                'metered_amount' => $event->metered_amount === null ? null : (string) $event->metered_amount,
                'pricing_state' => (string) $event->pricing_state,
                'currency' => $event->currency === null ? null : (string) $event->currency,
                'breakdown_state' => $breakdown['breakdown_state'],
                'breakdown' => $breakdown['breakdown'],
            ],
            'tokens' => [
                'input_tokens' => $measured ? (int) $event->input_tokens : null,
                'output_tokens' => $measured ? (int) $event->output_tokens : null,
                'total_tokens' => $measured ? (int) $event->total_tokens : null,
            ],
            'skill' => $this->skillAttribution($event),
            'reconciliation' => [
                'state' => $reconciliationState,
                'reason' => $event->billing_reconciliation_reason === null ? null : (string) $event->billing_reconciliation_reason,
                'billed_amount' => $billedAmount,
                'reconciled_amount' => $reconciledAmount,
                'difference_amount' => $event->billing_reconciliation_difference_amount === null
                    ? null
                    : (string) $event->billing_reconciliation_difference_amount,
            ],
        ];
    }

    /** @return array{id:int,code:string,version_id:int,version:int,run_id:int}|null */
    private function skillAttribution(UsageEvent $event): ?array
    {
        if ($event->skill_id === null && $event->skill_version_id === null && $event->skill_run_id === null) {
            return null;
        }
        if ($event->skill_id === null || $event->skill_version_id === null || $event->skill_run_id === null
            || $event->skill_code === null || $event->skill_version_no === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill attribution not found');
        }

        return [
            'id' => (int) $event->skill_id,
            'code' => (string) $event->skill_code,
            'version_id' => (int) $event->skill_version_id,
            'version' => (int) $event->skill_version_no,
            'run_id' => (int) $event->skill_run_id,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(mixed $value): array
    {
        $value = is_object($value) ? (array) $value : $value;

        return is_array($value) ? $value : [];
    }

    private function dispatchSourceKind(InvocationDispatch $dispatch): ?string
    {
        $sourceKind = $dispatch->source_kind;
        if (is_string($sourceKind) && $sourceKind !== '') {
            return $sourceKind;
        }

        // Old ledger rows cannot be projected as a new safe attribution.
        return null;
    }
}
