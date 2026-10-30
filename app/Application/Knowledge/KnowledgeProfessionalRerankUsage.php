<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\Invocation;
use think\facade\Db;

/** Immutable actual professional unit evidence, including unknown quantities. */
final class KnowledgeProfessionalRerankUsage
{
    /**
     * Runs inside the terminal transaction. Never infers usage from candidates.
     * @param array<string,mixed> $route
     * @return array{metered_amount:?string,currency:?string,price_revision_id:?int}
     */
    public function record(
        Invocation $invocation, UsageAttribution $attribution,
        KnowledgeProfessionalRerankPrice $price, array $route, ?int $quantity,
    ): array {
        if ($quantity !== null && $quantity < 0) {
            throw new ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional rerank actual quantity is invalid');
        }
        $amount = $price->amount($quantity);
        $priced = $amount !== null;
        $snapshot = $price->snapshot() + ['called_at' => $price->calledAt, 'quantity' => $quantity, 'metered_amount' => $amount];
        $event = [
            'organization_id' => $attribution->organizationId, 'application_id' => $attribution->applicationId,
            'environment_id' => $attribution->environmentId, 'workload_client_id' => $attribution->workloadClientId,
            'identity_snapshot' => $attribution->identitySnapshot,
            'provider_snapshot' => ['id' => $route['provider_id']],
            'model_snapshot' => ['id' => $route['model_id'], 'code' => $route['model_code']],
            'deployment_snapshot' => ['id' => $route['deployment_id'], 'remote_model' => $route['deployment_remote_model'], 'route' => $route],
            'invocation_id' => (int) $invocation->id, 'provider_id' => $route['provider_id'],
            'model_id' => $route['model_id'], 'deployment_id' => $route['deployment_id'],
            'service_action' => $attribution->serviceAction, 'source_kind' => $attribution->sourceKind,
            'capability_code' => 'rerank', 'metering_unit' => $price->meteringUnit, 'metering_quantity' => $quantity,
            'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'usage_state' => $quantity === null ? 'unmeasured' : 'measured',
            'price_revision_id' => $priced ? $price->revisionId : null, 'price_snapshot' => $snapshot,
            'metered_amount' => $amount, 'reconciled_amount' => null, 'currency' => $priced ? $price->currency : null,
            'pricing_state' => $priced ? 'priced' : 'unpriced', 'reconciliation_state' => 'unreconciled',
            'idempotency_key' => 'invocation:' . (int) $invocation->id,
        ];
        $existing = Db::connect('pgsql')->table('sand_ai_usage_event')->where('invocation_id', (int) $invocation->id)->find();
        if (is_array($existing)) {
            foreach ($event as $field => $value) {
                $actual = $existing[$field] ?? null;
                if (is_array($value)) {
                    $actual = is_string($actual) ? json_decode($actual, true, 32, JSON_THROW_ON_ERROR) : $actual;
                    if (!is_array($actual) || !RequestPayloadFingerprint::same($actual, $value, [])) {
                        throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Professional rerank usage snapshot changed');
                    }
                } elseif ($value === null ? $actual !== null
                    : (in_array($field, ['metering_quantity', 'metered_amount'], true)
                        ? (!is_string($actual) && !is_int($actual)) || BudgetDecimal::compare((string) $actual, (string) $value) !== 0
                        : (string) $actual !== (string) $value)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Professional rerank usage fact changed');
                }
            }
        } else {
            foreach (['identity_snapshot', 'provider_snapshot', 'model_snapshot', 'deployment_snapshot', 'price_snapshot'] as $field) {
                $event[$field] = json_encode($event[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $event['create_time'] = gmdate('Y-m-d H:i:s');
            Db::connect('pgsql')->table('sand_ai_usage_event')->insert($event);
        }
        return ['metered_amount' => $amount, 'currency' => $priced ? $price->currency : null,
            'price_revision_id' => $priced ? $price->revisionId : null];
    }
}
