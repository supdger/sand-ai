<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Usage\UsageAttribution;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Budget\ChatBudgetGuard;
use app\Infrastructure\Persistence\Model\BudgetPolicy;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use think\facade\Db;

/** Applies all matching published budget windows to one frozen speech run. */
final class SpeechBudgetGuard
{
    /** Called inside the same transaction as the prepared-to-dispatching transition. */
    public function reserve(int $runId, SpeechCallStartPrice $price, string $ownerScope): void
    {
        $runRows = Db::connect('pgsql')->query(
            "SELECT id,environment_id,model_id,identity_snapshot,request_id,request_fingerprint,input_characters,state,budget_reservation_id
             FROM sand_ai_speech_run WHERE id=?", [$runId],
        );
        if (count($runRows) !== 1 || $runRows[0]['state'] !== 'prepared'
            || $runRows[0]['budget_reservation_id'] !== null) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech cannot reserve budget', true);
        }
        $run = $runRows[0];
        $identity = is_array($run['identity_snapshot']) ? $run['identity_snapshot']
            : json_decode((string) $run['identity_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $attribution = UsageAttribution::fromCurrentPersisted($identity, (int) $run['environment_id'],
            'capability', 'sand_ai.audio.speech');
        $policies = (new BudgetPolicyService())->applicable($attribution, (int) $run['model_id'],
            'audio_speech', $price->calledAt, true);
        if ($policies === []) {
            (new SpeechRunLedger())->markDispatched($runId, $ownerScope, $price);
            return;
        }
        $windows = [];
        foreach ($policies as $policy) {
            $window = BudgetWindow::where('policy_id', (int) $policy->id)->lock(true)->find();
            if ($window === null) { throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'Speech budget window is missing'); }
            $windows[(int) $policy->id] = $window;
        }
        $prepared = [];
        foreach ($policies as $policy) {
        if (!in_array((string) $policy->enforcement, ['hard', 'soft'], true)
            || (string) $policy->unpriced_policy !== 'block'
            || (string) $policy->unmeasured_policy !== 'hold') {
            throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'Speech budget policy is not executable');
        }
        if ($price->revisionId === null || $price->currency === null) {
            throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'Published speech price is required under an active budget');
        }
        if ((string) $policy->currency !== $price->currency) {
            throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'Speech price currency differs from budget');
        }
        $amount = $price->amount((int) $run['input_characters']);
        if ($amount === null) {
            throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'Speech price is unavailable');
        }
        // A rounded zero still needs a reservation identity and a matching settlement.
        $estimate = BudgetDecimal::isPositive($amount) ? $amount : '0.000001';
        $window = $windows[(int) $policy->id];
        $snapshot = (new ChatBudgetGuard())->holdExpiredReservations($window, $price->calledAt);
        $transition = (new BudgetReservationStateMachine())->reserve(
            $snapshot, $estimate, (string) $policy->currency,
            (string) $policy->enforcement === 'soft',
        );
        $prepared[] = ['policy' => $policy, 'window' => $window, 'transition' => $transition, 'estimate' => $estimate];
        }
        // No money changes until every applicable window has passed the preflight.
        $locked = Db::connect('pgsql')->query('SELECT id FROM sand_ai_speech_run WHERE id=? AND state=? AND budget_reservation_id IS NULL FOR UPDATE', [$runId, 'prepared']);
        if (count($locked) !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech cannot reserve budget', true);
        }
        // Persist the exact owner price before reservation guards validate it.
        // The provider call remains outside this enclosing transaction.
        (new SpeechRunLedger())->markDispatched($runId, $ownerScope, $price);
        $anchor = null;
        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        foreach ($prepared as $member) {
        $policy = $member['policy']; $window = $member['window'];
        $transition = $member['transition']; $estimate = $member['estimate'];
        $this->writeWindow((int) $window->id, $transition['window']);
        $rows = Db::connect('pgsql')->query(
            "INSERT INTO sand_ai_budget_reservation
             (policy_id,speech_run_id,request_id,request_fingerprint,estimate_amount,currency,
              price_revision_id,state,expires_at)
             VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?,'reserved',?) RETURNING id",
            [(int) $policy->id, $runId, (string) $run['request_id'], (string) $run['request_fingerprint'],
                $estimate, $price->currency, $price->revisionId,
                $expires],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'Speech budget reservation failed');
        }
        $reservationId = (int) $rows[0]['id'];
        $anchor ??= $reservationId;
        $this->event((int) $policy->id, $reservationId, $runId, 'reserve', $estimate,
            $price->currency, null, ['price_revision_id' => $price->revisionId]);
        }
        ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            'UPDATE sand_ai_speech_run SET budget_reservation_id=? WHERE id=? AND state=? AND budget_reservation_id IS NULL',
            [$anchor, $runId, 'dispatching'],
        ), 'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Speech run changed before budget reservation');

    }

    /** Called inside speech usage finalization's transaction. */
    public function settle(array $run, string $actualAmount, int $priceRevisionId): void
    {
        if ($run['budget_reservation_id'] === null) {
            return;
        }
        foreach ($this->lockedReservations($run) as $member) {
        $window = $member['window']; $reservation = $member['reservation'];
        if ((int) $reservation->price_revision_id !== $priceRevisionId
            || (int) $reservation->speech_run_id !== (int) $run['id']) {
            throw new ApiProblem('SAND_AI_BUDGET_PRICE_REVISION_MISMATCH', 'Speech settlement price changed');
        }
        if ((string) $reservation->state === 'settled') {
            if (BudgetDecimal::compare((string) $reservation->metered_amount, $actualAmount) !== 0) {
                throw new ApiProblem('SAND_AI_BUDGET_IDEMPOTENCY_CONFLICT', 'Speech settlement amount changed');
            }
            continue;
        }
        $transition = (new BudgetReservationStateMachine())->settle(
            $this->window($window), $this->reservation($reservation),
            $actualAmount, (string) $reservation->currency,
        );
        $this->writeWindow((int) $window->id, $transition['window']);
        ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            'UPDATE sand_ai_budget_reservation SET state=?,metered_amount=CAST(? AS numeric(20,6)),
             reason=?,expires_at=NULL,update_time=CURRENT_TIMESTAMP(0)
             WHERE id=? AND state=?',
            ['settled', $actualAmount, 'speech_usage_measured', (int) $reservation->id, (string) $reservation->state],
        ), 'SAND_AI_BUDGET_RESERVATION_CHANGED', 'Speech budget changed before settlement');
        $this->event((int) $reservation->policy_id, (int) $reservation->id, (int) $run['id'],
            'settle', $actualAmount, (string) $reservation->currency, 'speech_usage_measured',
            ['overrun' => $transition['overrun']]);
        }
    }

    /** Only a proven unsent run may release the complete reservation group. */
    public function release(array $run): void
    {
        if ($run['budget_reservation_id'] === null) {
            return;
        }
        // Validate the speech owner before the common group transition.
        $this->lockedReservations($run);
        (new ChatBudgetGuard())->release((int) $run['budget_reservation_id'], 'speech_not_dispatched');
    }

    /** Unknown provider outcome conservatively holds the amount until recovery. */
    public function hold(array $run): void
    {
        if ($run['budget_reservation_id'] === null) {
            return;
        }
        $this->lockedReservations($run);
        (new ChatBudgetGuard())->settle(
            (int) $run['budget_reservation_id'], null, 'dispatch_ambiguous', null, 'audio_speech',
        );
    }

    /** @param array<string,mixed> $run @return list<array{reservation:BudgetReservation,window:BudgetWindow}> */
    private function lockedReservations(array $run): array
    {
        $members = (new ChatBudgetGuard())->lockReservationGroup((int) $run['budget_reservation_id']);
        foreach ($members as $member) {
            if ((int) $member['reservation']->speech_run_id !== (int) $run['id']) {
                throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'Speech budget owner differs');
            }
        }
        return $members;
    }

    /** Acquire all budget windows before the speech run lock. */
    public function lockForRun(int $runId): void
    {
        $rows = Db::connect('pgsql')->query('SELECT budget_reservation_id FROM sand_ai_speech_run WHERE id=?', [$runId]);
        if (count($rows) === 1 && $rows[0]['budget_reservation_id'] !== null) {
            (new ChatBudgetGuard())->lockReservationGroup((int) $rows[0]['budget_reservation_id']);
        }
    }

    /** @return array<string,string> */
    private function window(BudgetWindow $window): array
    {
        return ['currency' => (string) $window->currency,
            'limit_amount' => (string) $window->limit_amount,
            'reserved_amount' => (string) $window->reserved_amount,
            'settled_amount' => (string) $window->settled_amount,
            'held_amount' => (string) $window->held_amount];
    }

    /** @return array<string,mixed> */
    private function reservation(BudgetReservation $reservation): array
    {
        return ['state' => (string) $reservation->state,
            'estimate_amount' => (string) $reservation->estimate_amount,
            'metered_amount' => $reservation->metered_amount === null ? null : (string) $reservation->metered_amount,
            'currency' => (string) $reservation->currency];
    }

    /** @param array<string,string> $window */
    private function writeWindow(int $id, array $window): void
    {
        ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
            'UPDATE sand_ai_budget_window SET reserved_amount=CAST(? AS numeric(20,6)),
             settled_amount=CAST(? AS numeric(20,6)),held_amount=CAST(? AS numeric(20,6)),
             lock_version=lock_version+1,update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
            [$window['reserved_amount'], $window['settled_amount'], $window['held_amount'], $id],
        ), 'SAND_AI_BUDGET_WINDOW_CHANGED', 'Speech budget window changed');
    }

    /** @param array<string,mixed> $snapshot */
    private function event(int $policyId, int $reservationId, int $runId, string $type,
        string $amount, string $currency, ?string $reason, array $snapshot): void
    {
        Db::connect('pgsql')->execute(
            'INSERT INTO sand_ai_budget_event
             (policy_id,reservation_id,speech_run_id,event_type,amount,currency,reason,idempotency_key,snapshot)
             VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?,?,CAST(? AS jsonb))',
            [$policyId, $reservationId, $runId, $type, $amount, $currency, $reason,
                'reservation:' . $reservationId . ':' . $type,
                json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
        );
    }
}
