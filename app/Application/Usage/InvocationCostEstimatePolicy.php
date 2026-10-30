<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetChatWireProtocol;
use app\Application\Budget\OfficialModelCapacity;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestPayloadFingerprint;
use InvalidArgumentException;
use JsonException;

/** Projects original budget capacity, never a new quote or a cash charge. */
final class InvocationCostEstimatePolicy
{
    /**
     * @param array<string,mixed> $invocation
     * @param array<string,mixed>|null $dispatch
     * @param array<string,mixed>|null $event
     * @param list<array<string,mixed>> $reservations
     * @return array<string,mixed>
     */
    public function present(array $invocation, ?array $dispatch, ?array $event, array $reservations): array
    {
        if ($reservations === []) {
            return $this->empty('unavailable', 'no_frozen_quote');
        }
        if ($dispatch === null) {
            return $this->empty('unavailable', 'frozen_quote_unavailable');
        }
        try {
            foreach (['id', 'organization_id', 'application_id', 'environment_id', 'workload_client_id', 'model_id', 'deployment_id'] as $field) {
                if ($this->positiveId($invocation[$field] ?? null) === null) {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
            }
            if (!is_string($invocation['request_id'] ?? null) || $invocation['request_id'] === ''
                || !$this->digest($invocation['request_fingerprint'] ?? null)) {
                return $this->empty('unavailable', 'frozen_quote_unavailable');
            }
            foreach (['invocation_id' => 'id', 'environment_id' => 'environment_id', 'application_id' => 'application_id',
                'model_id' => 'model_id', 'deployment_id' => 'deployment_id'] as $dispatchField => $invocationField) {
                if ($this->positiveId($dispatch[$dispatchField] ?? null) !== $this->positiveId($invocation[$invocationField])) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
            }
            foreach (['call_started_at', 'call_start_pricing_state', 'call_start_price_snapshot'] as $field) {
                if (($invocation[$field] ?? null) === null) {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
            }
            $price = CallStartPricingSnapshot::fromPersistence(
                $invocation['call_started_at'] ?? null, $invocation['call_start_pricing_state'] ?? null,
                $invocation['call_start_price_revision_id'] ?? null, $this->snapshot($invocation['call_start_price_snapshot'] ?? null),
            );
            $resolved = $price->resolvedPrice();
            if ($resolved === null) {
                return $this->empty('unavailable', 'frozen_quote_unavailable');
            }
            foreach (['price_snapshot', 'route_snapshot'] as $field) {
                if (($dispatch[$field] ?? null) === null) {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
            }
            $dispatchPrice = $this->snapshot($dispatch['price_snapshot'] ?? null);
            foreach (['called_at', 'pricing_state', 'price_revision_id', 'price_snapshot'] as $field) {
                if (($dispatchPrice[$field] ?? null) === null) {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
            }
            $original = CallStartPricingSnapshot::fromPersistence(
                $dispatchPrice['called_at'] ?? null, $dispatchPrice['pricing_state'] ?? null,
                $dispatchPrice['price_revision_id'] ?? null, $dispatchPrice['price_snapshot'] ?? null,
            );
            if (!RequestPayloadFingerprint::same($original->dispatchSnapshot(), $price->dispatchSnapshot(), [])) {
                return $this->empty('held', 'frozen_quote_conflict');
            }
            $route = $this->snapshot($dispatch['route_snapshot'] ?? null);
            if (!array_key_exists('route_snapshot_version', $route)) {
                return $this->empty('unavailable', 'frozen_quote_unavailable');
            }
            $route = DispatchRouteSnapshot::assert($route, array_key_exists('max_output_tokens', $route));
            foreach (['model_id', 'deployment_id', 'provider_id', 'capability_code'] as $field) {
                if ((string) ($route[$field] ?? '') !== (string) ($dispatch[$field] ?? '')) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
            }
            if (!$this->digest($dispatch['request_body_digest'] ?? null)) {
                return $this->empty('unavailable', 'frozen_quote_unavailable');
            }
            if ($event !== null) {
                foreach (['invocation_id' => 'id', 'organization_id' => 'organization_id', 'application_id' => 'application_id',
                    'environment_id' => 'environment_id', 'workload_client_id' => 'workload_client_id',
                    'model_id' => 'model_id', 'deployment_id' => 'deployment_id'] as $eventField => $invocationField) {
                    if ($this->positiveId($event[$eventField] ?? null) !== $this->positiveId($invocation[$invocationField])) {
                        return $this->empty('held', 'frozen_quote_conflict');
                    }
                }
                if ($this->positiveId($event['provider_id'] ?? null) !== $this->positiveId($dispatch['provider_id'] ?? null)
                    || ($event['capability_code'] ?? null) !== ($dispatch['capability_code'] ?? null)
                    || ($event['source_kind'] ?? null) !== ($dispatch['source_kind'] ?? null)
                    || ($event['service_action'] ?? null) !== ($dispatch['service_action'] ?? null)) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                if (($event['pricing_state'] ?? null) === 'priced') {
                    if ($this->positiveId($event['price_revision_id'] ?? null) !== $resolved->revisionId
                        || ($event['currency'] ?? null) !== $resolved->currency) {
                        return $this->empty('held', 'frozen_quote_conflict');
                    }
                    if (($event['price_snapshot'] ?? null) === null) {
                        return $this->empty('unavailable', 'frozen_quote_unavailable');
                    }
                    $eventPrice = $this->snapshot($event['price_snapshot'] ?? null);
                    unset($eventPrice['amounts']);
                    if (!RequestPayloadFingerprint::same($eventPrice, $resolved->snapshot(), [])) {
                        return $this->empty('held', 'frozen_quote_conflict');
                    }
                } elseif (($event['pricing_state'] ?? null) !== 'unpriced'
                    || ($event['price_revision_id'] ?? null) !== null || ($event['currency'] ?? null) !== null) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
            }
            $amount = null;
            $proof = null;
            $seenPolicies = [];
            foreach ($reservations as $reservation) {
                $policyId = $this->positiveId($reservation['policy_id'] ?? null);
                $reservationId = $this->positiveId($reservation['id'] ?? null);
                if ($policyId === null || $reservationId === null || isset($seenPolicies[$policyId])) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                $seenPolicies[$policyId] = true;
                if ($this->positiveId($reservation['invocation_id'] ?? null) !== $this->positiveId($invocation['id'])
                    || ($reservation['request_id'] ?? null) !== $invocation['request_id']
                    || ($reservation['request_fingerprint'] ?? null) !== $invocation['request_fingerprint']
                    || $this->positiveId($reservation['price_revision_id'] ?? null) !== $resolved->revisionId
                    || ($reservation['currency'] ?? null) !== $resolved->currency
                    || ($reservation['speech_run_id'] ?? null) !== null
                    || ($reservation['external_audio_dispatch_id'] ?? null) !== null
                    || ($reservation['audio_task_run_id'] ?? null) !== null
                    || ($reservation['audio_execution_id'] ?? null) !== null) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                if (($reservation['reserve_event_id'] ?? null) === null) {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
                if ($this->positiveId($reservation['event_invocation_id'] ?? null) !== $this->positiveId($invocation['id'])
                    || $this->positiveId($reservation['event_policy_id'] ?? null) !== $policyId
                    || $this->positiveId($reservation['event_reservation_id'] ?? null) !== $reservationId
                    || ($reservation['event_currency'] ?? null) !== $resolved->currency) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                $quote = $this->snapshot($reservation['quote_snapshot'] ?? null);
                $bound = $quote['input_bound'] ?? null;
                if (!is_array($bound) || ($bound['kind'] ?? null) !== 'official_protocol_capacity') {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
                foreach (['source_sha256', 'definition_sha256', 'body_sha256', 'route_sha256'] as $field) {
                    if (!$this->digest($bound[$field] ?? null)) {
                        return $this->empty('unavailable', 'frozen_quote_unavailable');
                    }
                }
                $input = $quote['reserved_input_tokens'] ?? null;
                $output = $quote['output_token_upper_bound'] ?? null;
                if (!is_int($input) || $input < 1 || !is_int($output) || $output < 0
                    || !is_string($bound['capacity_version'] ?? null) || $bound['capacity_version'] === ''
                    || !is_string($bound['source_code'] ?? null) || $bound['source_code'] === '') {
                    return $this->empty('unavailable', 'frozen_quote_unavailable');
                }
                if (($quote['price_revision_id'] ?? null) !== $resolved->revisionId
                    || ($bound['input_capacity_tokens'] ?? null) !== $input
                    || !array_key_exists('counted_input_tokens', $bound) || $bound['counted_input_tokens'] !== null
                    || $bound['body_sha256'] !== $dispatch['request_body_digest']
                    || $bound['route_sha256'] !== $route['snapshot_sha256']
                    || (array_key_exists('max_output_tokens', $route) && $route['max_output_tokens'] !== $output)
                    || (($route['capability_code'] ?? null) === 'embedding' ? $output !== 0 : $output < 1)) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                // Resolve only the reviewed immutable capacity definition,
                // never provider configuration, a current price, or a quote.
                $source = OfficialModelCapacity::resolve([
                    'version' => $bound['capacity_version'], 'source_code' => $bound['source_code'],
                    'source_sha256' => $bound['source_sha256'], 'definition_sha256' => $bound['definition_sha256'],
                ], $route['deployment_remote_model'], $route['capability_code']);
                if ($source['protocol'] === BudgetChatWireProtocol::VERSION
                    && (($bound['protocol'] ?? null) !== BudgetChatWireProtocol::VERSION
                        || ($bound['wire_evidence_sha256'] ?? null) !== RequestPayloadFingerprint::fingerprint($source['wire_evidence'], []))) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                $expectedInput = $source['input_capacity_tokens'];
                if ($route['capability_code'] === 'embedding'
                    && ($bound['protocol'] ?? null) === OfficialModelCapacity::EMBEDDING_BATCH_PROTOCOL) {
                    $entries = $bound['entry_count'] ?? null;
                    if (!is_int($entries) || $entries < 1 || $entries > $source['max_batch_entries']
                        || ($bound['source_input_capacity_tokens'] ?? null) !== $expectedInput
                        || $expectedInput > intdiv(PHP_INT_MAX, $entries)) {
                        return $this->empty('held', 'frozen_quote_conflict');
                    }
                    $expectedInput *= $entries;
                }
                if ($input !== $expectedInput || $output > $source['max_output_tokens']) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                if (array_key_exists('amount_rounding', $bound)) {
                    if ($bound['amount_rounding'] !== TokenPriceCalculator::RESERVATION_ROUNDING) {
                        return $this->empty('held', 'frozen_quote_conflict');
                    }
                    $calculated = TokenPriceCalculator::calculateReservation($input, $output,
                        $resolved->inputRate['unit_price'], $resolved->inputRate['per_quantity'],
                        $resolved->outputRate['unit_price'], $resolved->outputRate['per_quantity']);
                } else {
                    $calculated = TokenPriceCalculator::calculate($input, $output,
                        $resolved->inputRate['unit_price'], $resolved->inputRate['per_quantity'],
                        $resolved->outputRate['unit_price'], $resolved->outputRate['per_quantity']);
                }
                // The persisted capacity reservation has a minimum positive hold.
                $expected = BudgetDecimal::isPositive($calculated['amount']) ? $calculated['amount'] : '0.000001';
                $reserved = BudgetDecimal::normalize($reservation['estimate_amount'], false);
                if ($reserved !== $expected || BudgetDecimal::normalize($reservation['reserve_event_amount'], false) !== $reserved) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                $memberProof = ['input' => $input, 'output' => $output, 'bound' => $bound];
                if (($amount !== null && $amount !== $reserved)
                    || ($proof !== null && !RequestPayloadFingerprint::same($proof, $memberProof, []))) {
                    return $this->empty('held', 'frozen_quote_conflict');
                }
                $amount = $reserved;
                $proof = $memberProof;
            }
            // Every scope reserves the same call; report one original upper bound.
            return ['estimated_amount' => $amount, 'estimated_currency' => $resolved->currency,
                'estimated_price_revision_id' => $resolved->revisionId,
                'estimate_basis' => 'budget_reservation_upper_bound', 'estimate_state' => 'available',
                'estimate_reason' => 'frozen_budget_reservation'];
        } catch (ApiProblem | InvalidArgumentException | JsonException) {
            return $this->empty('held', 'frozen_quote_conflict');
        }
    }

    /** @return array<string,mixed> */
    private function empty(string $state, string $reason): array
    {
        return ['estimated_amount' => null, 'estimated_currency' => null, 'estimated_price_revision_id' => null,
            'estimate_basis' => null, 'estimate_state' => $state, 'estimate_reason' => $reason];
    }

    /** @return array<string,mixed> */
    private function snapshot(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } elseif (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Frozen quote snapshot is unavailable');
        }
        return $value;
    }

    private function positiveId(mixed $value): ?int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        $text = (string) $value;
        if (strlen($text) > strlen($maximum) || (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
            return null;
        }
        return (int) $value;
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
