<?php

declare(strict_types=1);

namespace app\Application\File;

use app\Api\Support\ApiProblem;
use app\Application\Budget\BudgetDecimal;
use app\Application\Budget\BudgetPolicyService;
use app\Application\Budget\BudgetReservationStateMachine;
use app\Application\Budget\ChatBudgetGuard;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\BudgetReservation;
use app\Infrastructure\Persistence\Model\BudgetWindow;
use think\facade\Db;

/** OCR owns reservations in the existing shared budget ledger. */
final class OcrBudgetGuard
{
    /** Caller transaction takes budget scope/window locks before task/dispatch owner locks. */
    public function prepare(UsageAttribution $attribution, int $modelId, OcrCallStartPrice $price, string $at): array
    {
        $policies = (new BudgetPolicyService())->applicable($attribution, $modelId, 'image_ocr', $at, true);
        $prepared = [];
        foreach ($policies as $policy) {
            if (!in_array((string) $policy->enforcement, ['hard', 'soft'], true)
                || (string) $policy->unpriced_policy !== 'block' || (string) $policy->unmeasured_policy !== 'hold') {
                throw new ApiProblem('SAND_AI_BUDGET_POLICY_NOT_EXECUTABLE', 'OCR budget policy is not executable');
            }
            if ($price->revisionId === null || $price->reservationAmount === null || $price->currency === null) {
                throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'A published OCR request price is required under the active budget');
            }
            if ((string) $policy->currency !== $price->currency) throw new ApiProblem('SAND_AI_BUDGET_CURRENCY_MISMATCH', 'OCR price currency differs from budget');
            $window = BudgetWindow::where('policy_id', (int) $policy->id)->lock(true)->find();
            if ($window === null) throw new ApiProblem('SAND_AI_BUDGET_WINDOW_MISSING', 'OCR budget window is missing');
            $estimate = BudgetDecimal::isPositive($price->reservationAmount) ? $price->reservationAmount : '0.000001';
            $snapshot = (new ChatBudgetGuard())->holdExpiredReservations($window, $at);
            $transition = (new BudgetReservationStateMachine())->reserve($snapshot, $estimate, $price->currency, (string) $policy->enforcement === 'soft');
            $prepared[] = ['policy_id' => (int) $policy->id, 'window_id' => (int) $window->id, 'estimate' => $estimate, 'window' => $transition['window']];
        }
        return $prepared;
    }

    /** Same transaction as the immutable dispatch and usage insertion; failure rolls back all amounts. */
    public function reserve(int $dispatchId, string $fingerprint, OcrCallStartPrice $price, array $prepared): void
    {
        foreach ($prepared as $member) {
            $window = $member['window'];
            ChatBudgetGuard::requireExactlyOneAffectedRow(Db::connect('pgsql')->execute(
                'UPDATE sand_ai_budget_window SET reserved_amount=CAST(? AS numeric(20,6)),settled_amount=CAST(? AS numeric(20,6)),held_amount=CAST(? AS numeric(20,6)),lock_version=lock_version+1,update_time=CURRENT_TIMESTAMP(0) WHERE id=?',
                [$window['reserved_amount'], $window['settled_amount'], $window['held_amount'], $member['window_id']]),
                'SAND_AI_BUDGET_WINDOW_CHANGED', 'OCR budget window changed');
            $rows = Db::connect('pgsql')->query(
                "INSERT INTO sand_ai_budget_reservation (policy_id,ocr_dispatch_id,request_id,request_fingerprint,estimate_amount,currency,price_revision_id,state,expires_at) VALUES (?,?,?,?,CAST(? AS numeric(20,6)),?,?,'reserved',?) RETURNING id",
                [$member['policy_id'], $dispatchId, 'ocr-dispatch:' . $dispatchId, $fingerprint, $member['estimate'], $price->currency, $price->revisionId, gmdate('Y-m-d H:i:s', time() + 3600)]);
            if (count($rows) !== 1) throw new ApiProblem('SAND_AI_BUDGET_RESERVATION_MISSING', 'OCR budget reservation failed');
            $id = (int) $rows[0]['id'];
            Db::connect('pgsql')->execute(
                "INSERT INTO sand_ai_budget_event (policy_id,reservation_id,ocr_dispatch_id,event_type,amount,currency,idempotency_key,snapshot) VALUES (?,?,?,'reserve',CAST(? AS numeric(20,6)),?,?,CAST(? AS jsonb))",
                [$member['policy_id'], $id, $dispatchId, $member['estimate'], $price->currency, 'reservation:' . $id . ':reserve', json_encode($price->snapshot, JSON_THROW_ON_ERROR)]);
        }
    }

    /** Take shared windows before the outcome's dispatch lock; unknown results never release. */
    public function lock(int $dispatchId): ?int
    {
        $row = BudgetReservation::where('ocr_dispatch_id', $dispatchId)->order('policy_id')->order('id')->find();
        if ($row === null) return null;
        (new ChatBudgetGuard())->lockReservationGroup((int) $row->id);
        return (int) $row->id;
    }

    public function finish(?int $anchor, string $state, array $pricing): void
    {
        if ($anchor === null) return;
        (new ChatBudgetGuard())->settle($anchor, $state === 'response_known' ? $pricing['amount'] : null,
            $state === 'response_known' ? 'ocr_response_known' : 'ocr_dispatch_ambiguous',
            $state === 'response_known' ? $pricing['price_revision_id'] : null, 'image_ocr');
    }
}
