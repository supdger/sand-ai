<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Usage\PriceResolver;
use app\Application\Usage\PricingValuePolicy;
use app\Application\Usage\ResolvedTokenPrice;
use app\Application\Usage\UsageCostComparisonPolicy;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\InvocationReadFacts;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\PriceRate;
use app\Infrastructure\Persistence\Model\PriceRevision;
use app\Infrastructure\Persistence\Model\UsageEvent;

/** The administrator permission gate and both persisted read proofs remain mandatory. */
final class UsageComparisonLogic
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function read(array $input, mixed $adminInfo): array
    {
        if (array_diff(array_keys($input), ['environment_id', 'current_invocation_id', 'baseline_invocation_id', 'current_price_revision_id']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Comparison query contains unknown fields');
        }
        $values = new PricingValuePolicy();
        $environmentId = $values->positiveId($input['environment_id'] ?? null, 'environment_id');
        $currentId = $values->positiveId($input['current_invocation_id'] ?? null, 'current_invocation_id');
        $baselineId = $values->positiveId($input['baseline_invocation_id'] ?? null, 'baseline_invocation_id');
        $priceId = array_key_exists('current_price_revision_id', $input)
            ? $values->positiveId($input['current_price_revision_id'], 'current_price_revision_id') : null;

        $current = $this->selected($environmentId, $currentId, $adminInfo);
        $baseline = $this->selected($environmentId, $baselineId, $adminInfo);
        $policy = new UsageCostComparisonPolicy();
        [$published, $priceReason] = $this->publishedPrice($current, $priceId);
        return [
            'current' => ['id' => $currentId, 'cost' => $current['cost']],
            'baseline' => ['id' => $baselineId, 'cost' => $baseline['cost']],
            'comparison' => $policy->comparison($currentId, $baselineId, $current['cost'], $baseline['cost'], $current['facts'], $baseline['facts']),
            'scenario' => $policy->scenario($current['cost'], $published, $priceReason),
        ];
    }

    /** @return array{cost:array<string,mixed>,facts:array<string,mixed>,provider_id:?int,model_id:?int,capability:string} */
    private function selected(int $environmentId, int $invocationId, mixed $adminInfo): array
    {
        // Reuse the existing ID bridge, mandatory read authorization and frozen
        // price decomposition instead of constructing another cost projection.
        $cost = (new UsageCostLogic())->read(['environment_id' => $environmentId, 'invocation_id' => $invocationId], $adminInfo);
        $invocation = Invocation::where('environment_id', $environmentId)->where('id', $invocationId)->find();
        $dispatch = InvocationDispatch::where('environment_id', $environmentId)->where('invocation_id', $invocationId)->find();
        if ($invocation === null || $dispatch === null) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison invocation facts are unavailable');
        }
        try {
            $facts = (new InvocationReadFacts())->resolve('sand_ai', 'sand_ai.usage.read', (string) $invocationId);
        } catch (IdentityContextException $problem) {
            throw new ApiProblem($problem->errorCode, 'Comparison invocation ownership is unavailable');
        }
        foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
            if ((int) $invocation->getAttr($field) !== $facts[$field]) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison invocation ownership differs');
            }
        }
        if ($facts['environment_id'] !== $environmentId || $facts['resource_ref'] !== (string) $invocationId
            || (string) $invocation->request_id !== ($cost['request_id'] ?? null)
            || (int) $dispatch->model_id !== (int) $invocation->model_id
            || (string) $dispatch->capability_code !== ($cost['capability'] ?? null)
            || (string) $dispatch->service_action !== ($cost['service_action'] ?? null)
            || (string) $dispatch->source_kind !== ($cost['source_kind'] ?? null)
            || (int) $dispatch->deployment_id !== ($cost['deployment_id'] ?? null)) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison execution source differs');
        }
        $events = UsageEvent::where('environment_id', $environmentId)->where('invocation_id', $invocationId)->limit(2)->select();
        if (count($events) > 1) {
            throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison usage event is ambiguous');
        }
        $providerId = null;
        $modelId = null;
        if (count($events) === 1) {
            $event = $events[0];
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ($event->getAttr($field) === null || (int) $event->getAttr($field) !== $facts[$field]) {
                    throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison usage ownership differs');
                }
            }
            if ((int) $event->model_id !== (int) $dispatch->model_id
                || (int) $event->provider_id !== (int) $dispatch->provider_id
                || (int) $event->deployment_id !== (int) $dispatch->deployment_id
                || (string) $event->capability_code !== (string) $dispatch->capability_code
                || (string) $event->service_action !== (string) $dispatch->service_action
                || (string) $event->source_kind !== (string) $dispatch->source_kind
                || ($event->price_revision_id === null ? null : (int) $event->price_revision_id) !== ($cost['price']['revision_id'] ?? null)
                || ($event->metered_amount === null ? null : (string) $event->metered_amount) !== ($cost['price']['metered_amount'] ?? null)
                || ($event->currency === null ? null : (string) $event->currency) !== ($cost['price']['currency'] ?? null)) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Comparison usage source differs');
            }
            $providerId = $event->provider_id === null ? null : (int) $event->provider_id;
            $modelId = $event->model_id === null ? null : (int) $event->model_id;
        }
        $facts['request_fingerprint'] = $invocation->request_fingerprint;
        $facts['request_body_digest'] = $dispatch->request_body_digest;
        $facts['route_snapshot_sha256'] = $this->routeProof($dispatch);
        $facts['execution_scope'] = (string) $dispatch->execution_scope;
        return ['cost' => $cost, 'facts' => $facts, 'provider_id' => $providerId, 'model_id' => $modelId, 'capability' => (string) $dispatch->capability_code];
    }

    private function routeProof(InvocationDispatch $dispatch): ?string
    {
        $snapshot = $dispatch->route_snapshot;
        if (!is_array($snapshot)) return null;
        try {
            $snapshot = DispatchRouteSnapshot::assert($snapshot, (string) $dispatch->execution_scope === 'skill');
        } catch (ApiProblem) {
            // Old or incomplete evidence remains visible as non-comparable.
            return null;
        }
        foreach (['provider_id', 'model_id', 'deployment_id'] as $field) {
            if ($snapshot[$field] !== (int) $dispatch->getAttr($field)) return null;
        }
        if ($snapshot['capability_code'] !== (string) $dispatch->capability_code) return null;
        return $snapshot['snapshot_sha256'];
    }

    /** @param array<string,mixed> $selected @return array{0:?ResolvedTokenPrice,1:?string} */
    private function publishedPrice(array $selected, ?int $revisionId): array
    {
        if (($selected['provider_id'] ?? 0) < 1 || ($selected['model_id'] ?? 0) < 1) {
            return [null, 'published_price_unavailable'];
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($revisionId === null) {
            // A scene must not silently choose one of conflicting active prices.
            $revisions = PriceRevision::where('provider_id', $selected['provider_id'])
                ->where('model_id', $selected['model_id'])->where('capability_code', $selected['capability'])
                ->where('state', 'published')->where('effective_from', '<=', $now)
                ->where(static function ($query) use ($now): void {
                    $query->whereNull('effective_until')->whereOr('effective_until', '>', $now);
                })->limit(2)->select();
            if (count($revisions) !== 1) return [null, 'published_price_unavailable'];
            $revisionId = (int) $revisions[0]->id;
        }
        $price = (new PriceResolver())->resolveRevision($revisionId, $selected['provider_id'], $selected['model_id'], $selected['capability'], $now);
        if ($price === null) return [null, 'published_price_unavailable'];
        $rates = PriceRate::where('price_revision_id', $price->revisionId)->limit(3)->select()->toArray();
        $meters = array_column($rates, 'meter_code');
        sort($meters);
        if (count($rates) !== 2 || $meters !== ['input_tokens', 'output_tokens']) return [null, 'published_price_unverified'];
        try {
            // Validate canonical rates/currency before any estimation. The
            // historical event and its original snapshot remain untouched.
            (new PricingValuePolicy())->persistedSnapshot('priced', $price->revisionId, ['snapshot_version' => 1] + $price->snapshot());
        } catch (ApiProblem) {
            return [null, 'published_price_unverified'];
        }
        return [$price, null];
    }
}
