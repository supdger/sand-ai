<?php

declare(strict_types=1);

namespace app\Application\Gateway;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;

/** Compares upper-bound prices only within one currency, then uses route priority. */
final class LowCostRoutePolicy
{
    /** @param list<array{estimated_amount:string,currency:string,priority:int,route_id:int,model_code:string,deployment_id:int,price_revision_id:int}> $candidates
     *  @return array{estimated_amount:string,currency:string,priority:int,route_id:int,model_code:string,deployment_id:int,price_revision_id:int}
     */
    public static function choose(array $candidates): array
    {
        if ($candidates === []) {
            throw new ApiProblem('SAND_AI_LOW_COST_ROUTE_UNAVAILABLE', 'No published priced chat route can satisfy this request');
        }
        $currency = $candidates[0]['currency'];
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['currency'] !== $currency) {
                throw new ApiProblem('SAND_AI_LOW_COST_CURRENCY_MISMATCH', 'Priced chat routes use different currencies');
            }
            $comparison = BudgetDecimal::compare($candidate['estimated_amount'], $best['estimated_amount']);
            if ($comparison < 0 || ($comparison === 0 && [$candidate['priority'], $candidate['route_id']]
                < [$best['priority'], $best['route_id']])) {
                $best = $candidate;
            }
        }
        return $best;
    }
}
