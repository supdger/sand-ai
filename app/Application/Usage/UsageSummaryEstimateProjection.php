<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Aggregates original budget upper bounds over the summary's exact event scope. */
final class UsageSummaryEstimateProjection
{
    private const PAGE_SIZE = 500;

    /** @param array<string,array{rows:array,truncated:bool}> $aggregates
     *  @param array<string,mixed> $filters
     *  @param list<array<string,mixed>> $scope
     *  @param array<string,array{0:string,1:string}> $dimensions
     */
    public function present(array $aggregates, array $filters, array $scope, array $dimensions): array
    {
        $groups = [];
        foreach ($aggregates as $group => $aggregate) {
            foreach ($aggregate['rows'] as $row) {
                $groups[$group][$this->key($row['value'], $row['currency'])] = [
                    'known_events' => 0, 'unknown_events' => 0, 'held_events' => 0, 'amounts' => [],
                ];
            }
        }
        if ($groups === []) return $aggregates;
        $afterId = 0;
        $projection = new InvocationCostEstimateProjection();
        do {
            $query = (new UsageEventQuery())->filtered($filters, $scope)
                ->field('usage_event.id,usage_event.invocation_id,usage_event.organization_id,usage_event.application_id,usage_event.environment_id,usage_event.workload_client_id,usage_event.model_id,usage_event.deployment_id,usage_event.provider_id,usage_event.capability_code,usage_event.source_kind,usage_event.service_action,usage_event.pricing_state,usage_event.price_revision_id,usage_event.currency,usage_event.price_snapshot')
                ->where('usage_event.id', '>', $afterId)->order('usage_event.id')->limit(self::PAGE_SIZE);
            foreach (array_values($dimensions) as $index => [$expression]) {
                $query->fieldRaw($expression . ' AS estimate_dimension_' . $index);
            }
            $events = $query->select()->toArray();
            $quotes = $projection->forEvents($events);
            foreach ($events as $event) {
                $id = (int) $event['id'];
                if ($id <= $afterId) continue;
                $afterId = $id;
                $quote = $quotes[$id];
                $known = $quote['estimate_state'] === 'available';
                foreach (array_keys($dimensions) as $index => $group) {
                    $value = $event['estimate_dimension_' . $index];
                    $key = $this->key($value === null ? null : (string) $value, $event['currency']);
                    if (!isset($groups[$group][$key])) continue;
                    $total = &$groups[$group][$key];
                    if ($known) {
                        ++$total['known_events'];
                        $currency = $quote['estimated_currency'];
                        $total['amounts'][$currency] = $this->add(
                            $total['amounts'][$currency] ?? '0.000000', $quote['estimated_amount'],
                        );
                    } else {
                        ++$total['unknown_events'];
                        if ($quote['estimate_state'] === 'held') ++$total['held_events'];
                    }
                    unset($total);
                }
            }
        } while (count($events) === self::PAGE_SIZE);

        foreach ($aggregates as $group => &$aggregate) {
            foreach ($aggregate['rows'] as &$row) {
                $total = $groups[$group][$this->key($row['value'], $row['currency'])];
                if ($total['known_events'] + $total['unknown_events'] !== $row['total_events']) {
                    $total = ['known_events' => 0, 'unknown_events' => $row['total_events'], 'held_events' => 0, 'amounts' => []];
                }
                ksort($total['amounts']);
                $singleCurrency = count($total['amounts']) === 1;
                $complete = $total['unknown_events'] === 0 && $singleCurrency;
                $knownAmounts = [];
                foreach ($total['amounts'] as $currency => $amount) {
                    $knownAmounts[] = ['currency' => $currency, 'amount' => $amount];
                }
                $row['estimate'] = [
                    'basis' => 'budget_reservation_upper_bound',
                    'known_events' => $total['known_events'], 'unknown_events' => $total['unknown_events'],
                    'held_events' => $total['held_events'],
                    'amount' => $complete ? $knownAmounts[0]['amount'] : null,
                    'currency' => $complete ? $knownAmounts[0]['currency'] : null,
                    'known_amounts' => $knownAmounts,
                ];
            }
            unset($row);
        }
        unset($aggregate);
        return $aggregates;
    }

    private function key(mixed $value, mixed $currency): string
    {
        return json_encode([$value, $currency], JSON_THROW_ON_ERROR);
    }

    /** Individual quotes have six decimal places; the sum may exceed numeric(20,6). */
    private function add(string $left, string $right): string
    {
        $a = str_replace('.', '', $left);
        $b = str_replace('.', '', $right);
        $sum = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j) {
            $digit = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $sum = (string) ($digit % 10) . $sum;
            $carry = intdiv($digit, 10);
        }
        $sum = str_pad(ltrim($sum, '0') ?: '0', 7, '0', STR_PAD_LEFT);
        return substr($sum, 0, -6) . '.' . substr($sum, -6);
    }
}
