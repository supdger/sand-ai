<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\UsageEvent;
use think\facade\Db;

/** Reads only the original reservation/quote facts after invocation authorization. */
final class InvocationCostEstimateProjection
{
    private const RESERVATION_FIELDS = 'r.id,r.policy_id,r.invocation_id,r.request_id,r.request_fingerprint,
        r.estimate_amount::text,r.currency,r.price_revision_id,
        r.speech_run_id,r.external_audio_dispatch_id,r.audio_task_run_id,r.audio_execution_id,
        e.id AS reserve_event_id,e.invocation_id AS event_invocation_id,
        e.policy_id AS event_policy_id,e.reservation_id AS event_reservation_id,
        e.amount::text AS reserve_event_amount,e.currency AS event_currency,e.snapshot::text AS quote_snapshot';

    /** @return array<string,mixed> */
    public function present(Invocation $invocation, ?UsageEvent $event = null, ?InvocationDispatch $dispatch = null): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT ' . self::RESERVATION_FIELDS . "
             FROM sand_ai_budget_reservation r
             LEFT JOIN sand_ai_budget_event e ON e.reservation_id=r.id AND e.event_type='reserve'
             WHERE r.invocation_id=? ORDER BY r.policy_id,r.id",
            [(int) $invocation->id],
        );
        if ($rows !== [] && $dispatch === null) {
            $dispatch = InvocationDispatch::where('environment_id', (int) $invocation->environment_id)
                ->where('invocation_id', (int) $invocation->id)->find();
        }
        return (new InvocationCostEstimatePolicy())->present(
            $invocation->getData(), $dispatch?->getData(), $event?->getData(), $rows,
        );
    }

    /** The caller selects these events through its authorized summary scope.
     *  @param list<array<string,mixed>> $events
     *  @return array<int,array<string,mixed>> Original quote projections keyed by event ID.
     */
    public function forEvents(array $events): array
    {
        if ($events === []) return [];
        $ids = [];
        foreach ($events as $event) {
            if ((int) ($event['invocation_id'] ?? 0) > 0) $ids[(int) $event['invocation_id']] = true;
        }
        $invocations = $dispatches = $reservations = [];
        if ($ids !== []) {
            $connection = Db::connect('pgsql');
            $ids = array_keys($ids);
            foreach ($connection->table('sand_ai_invocation')->whereIn('id', $ids)
                ->field('id,request_id,request_fingerprint,organization_id,application_id,environment_id,workload_client_id,model_id,deployment_id,call_started_at,call_start_pricing_state,call_start_price_revision_id,call_start_price_snapshot')
                ->select()->toArray() as $invocation) {
                $invocations[(int) $invocation['id']] = $invocation;
            }
            foreach ($connection->table('sand_ai_invocation_dispatch')->whereIn('invocation_id', $ids)
                ->field('invocation_id,environment_id,application_id,model_id,deployment_id,provider_id,capability_code,source_kind,service_action,price_snapshot,route_snapshot,request_body_digest')
                ->select()->toArray() as $dispatch) {
                $dispatches[(int) $dispatch['invocation_id']] = $dispatch;
            }
            $rows = $connection->query(
                'SELECT ' . self::RESERVATION_FIELDS . '
                 FROM sand_ai_budget_reservation r
                 LEFT JOIN sand_ai_budget_event e ON e.reservation_id=r.id AND e.event_type=?
                 WHERE r.invocation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY r.invocation_id,r.policy_id,r.id',
                ['reserve', ...$ids],
            );
            foreach ($rows as $row) $reservations[(int) $row['invocation_id']][] = $row;
        }
        $policy = new InvocationCostEstimatePolicy();
        $results = [];
        foreach ($events as $event) {
            $invocationId = (int) ($event['invocation_id'] ?? 0);
            $results[(int) $event['id']] = $policy->present(
                $invocations[$invocationId] ?? [], $dispatches[$invocationId] ?? null,
                $event, $reservations[$invocationId] ?? [],
            );
        }
        return $results;
    }
}
