<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Usage\AudioCallStartPricingSnapshot;
use app\Application\Usage\AudioSeconds;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use think\facade\Db;

/** ASR reservations belong to a concrete external dispatch or local execution attempt. */
final class AudioBudgetGuard
{
    public function anchor(?int $dispatchId, int $taskId, ?string $executionId): ?int
    {
        $query = $dispatchId === null
            ? BudgetReservation::where('audio_task_run_id', $taskId)->where('audio_execution_id', $executionId)
            : BudgetReservation::where('external_audio_dispatch_id', $dispatchId);
        $row = $query->order('policy_id')->order('id')->find();
        return $row === null ? null : (int) $row->id;
    }

    public function lock(?int $dispatchId, int $taskId, ?string $executionId): ?int
    {
        $anchor = $this->anchor($dispatchId, $taskId, $executionId);
        if ($anchor !== null) {
            (new ChatBudgetGuard())->lockReservationGroup($anchor);
        }
        return $anchor;
    }

    /** Caller owns one PostgreSQL transaction and takes task/dispatch locks only after this call. */
    public function reserve(?int $dispatchId, int $taskId, ?string $executionId,
        UsageAttribution $attribution, int $modelId, AudioCallStartPricingSnapshot $pricing,
        ?AudioSeconds $seconds, string $fingerprint): ?int
    {
        if ($taskId < 1 || ($dispatchId === null && ($executionId === null || $executionId === ''))
            || ($dispatchId !== null && ($dispatchId < 1 || $executionId !== null))) {
            throw new ApiProblem('SAND_AI_BUDGET_OWNER_INVALID', 'Audio budget requires one execution owner');
        }
        $requestId = $dispatchId === null ? 'audio-local:' . $taskId . ':' . $executionId : 'audio-external:' . $dispatchId;
        Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?, 132))', [$requestId]);
        if ($dispatchId === null) {
            $pending = BudgetReservation::where('audio_task_run_id',$taskId)->where('audio_execution_id','<>',$executionId)
                ->whereIn('state',['reserved','held'])->find();
            if ($pending !== null) {
                throw new ApiProblem('SAND_AI_DISPATCH_AMBIGUOUS','Previous audio attempt requires usage recovery before retry',true);
            }
        }
        $anchor = $this->lock($dispatchId, $taskId, $executionId);
        if ($anchor !== null) {
            foreach ((new ChatBudgetGuard())->lockReservationGroup($anchor) as $member) {
                $reservation = $member['reservation'];
                if (!hash_equals((string) $reservation->request_fingerprint, $fingerprint)
                    || (int) $reservation->price_revision_id !== $pricing->price?->revisionId
                    || (string) $reservation->currency !== $pricing->price?->currency) {
                    throw new ApiProblem('SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT', 'Audio budget frozen request changed');
                }
                if ((string) $reservation->state !== 'reserved') {
                    throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_EXPIRED', 'Audio execution cannot resend');
                }
            }
            return $anchor;
        }
        $policies = (new BudgetPolicyService())->applicable($attribution, $modelId,
            'audio_transcription', gmdate('Y-m-d H:i:s'), true);
        if ($policies === []) { return null; }
        if ($pricing->price === null) {
            throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'Published ASR price is required under an active budget');
        }
        if ($seconds === null) {
            throw new ApiProblem('SAND_AI_BUDGET_ESTIMATE_UNAVAILABLE', 'Verified private audio duration is required');
        }
        $amount = $pricing->usagePricing($seconds)['metered_amount'];
        $estimate = BudgetDecimal::isPositive($amount) ? $amount : '0.000001';
        $windows = [];
        foreach ($policies as $policy) {
            if (!in_array((string)$policy->enforcement,['hard','soft'],true)
                || (string)$policy->unpriced_policy !== 'block' || (string)$policy->unmeasured_policy !== 'hold') {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE','ASR budget policy is not executable');
            }
            if ((string) $policy->currency !== $pricing->price->currency) {
                throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'ASR price currency differs from budget');
            }
            $window = BudgetWindow::where('policy_id', (int) $policy->id)->lock(true)->find();
            if ($window === null) { throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'ASR budget window is missing'); }
            $windows[] = ['policy' => $policy, 'window' => $window];
        }
        foreach ($windows as &$member) {
            $policy = $member['policy'];
            $snapshot = (new ChatBudgetGuard())->holdExpiredReservations($member['window'], gmdate('Y-m-d H:i:s'));
            $member['transition'] = (new BudgetReservationStateMachine())->reserve($snapshot, $estimate,
                $pricing->price->currency, (string) $policy->enforcement === 'soft');
        }
        unset($member);
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        foreach ($windows as $member) {
            $policy = $member['policy']; $window = $member['transition']['window'];
            ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                'UPDATE sand_ai_budget_window SET reserved_amount=CAST(? AS numeric(20,6)),settled_amount=CAST(? AS numeric(20,6)),held_amount=CAST(? AS numeric(20,6)),lock_version=lock_version+1,update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                [$window['reserved_amount'], $window['settled_amount'], $window['held_amount'], (int) $member['window']->id]),
                'SAND_AI_BUDGET_WINDOW_CHANGED', 'ASR budget window changed');
            $rows = Db::connect('pgsql')->query(
                "INSERT INTO sand_ai_budget_reservation (policy_id,external_audio_dispatch_id,audio_task_run_id,audio_execution_id,request_id,request_fingerprint,estimate_amount,currency,price_revision_id,state,expires_at) VALUES (?,?,?,?,?,?,CAST(? AS numeric(20,6)),?,?,'reserved',?) RETURNING id",
                [(int) $policy->id,$dispatchId,$dispatchId === null ? $taskId : null,$executionId,$requestId,$fingerprint,$estimate,$pricing->price->currency,$pricing->price->revisionId,$expires]);
            if (count($rows) !== 1) { throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'ASR budget reservation failed'); }
            $id = (int) $rows[0]['id']; $anchor ??= $id;
            Db::connect('pgsql')->execute('INSERT INTO sand_ai_budget_event (policy_id,reservation_id,external_audio_dispatch_id,audio_task_run_id,audio_execution_id,event_type,amount,currency,idempotency_key,snapshot) VALUES (?,?,?,?,?,\'reserve\',CAST(? AS numeric(20,6)),?,?,CAST(? AS jsonb))',
                [(int) $policy->id,$id,$dispatchId,$dispatchId === null ? $taskId : null,$executionId,$estimate,$pricing->price->currency,'reservation:' . $id . ':reserve',json_encode(['price_revision_id'=>$pricing->price->revisionId,'audio_seconds'=>$seconds->value],JSON_THROW_ON_ERROR)]);
        }
        return $anchor;
    }

    public function finish(?int $anchor, ?string $amount, ?int $priceId): void
    {
        if ($anchor !== null) {
            (new ChatBudgetGuard())->settle($anchor, $amount, $amount === null ? 'audio_usage_unknown' : 'audio_usage_measured', $priceId, 'audio_transcription');
        }
    }
}
