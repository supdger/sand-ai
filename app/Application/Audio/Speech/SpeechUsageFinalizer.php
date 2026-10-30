<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/** Atomically seals one speech run with its immutable character usage event. */
final class SpeechUsageFinalizer
{
    /** @return array<string,mixed> */
    public function finalize(int $runId, UsageAttribution $attribution): array
    {
        if ($attribution->serviceAction !== 'sand_ai.audio.speech'
            || $attribution->sourceKind !== 'capability'
            || $attribution->capabilityCode !== 'audio_speech') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Speech usage attribution is invalid');
        }
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        return Db::connect('pgsql')->transaction(function () use ($runId, $attribution, $scope): array {
            (new SpeechBudgetGuard())->lockForRun($runId);
            $rows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_speech_run WHERE id=? AND environment_id=? AND owner_scope=? FOR UPDATE',
                [$runId, $attribution->environmentId, $scope],
            );
            if (count($rows) !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
            }
            $run = $rows[0];
            if ($run['state'] === 'succeeded') {
                $events = Db::connect('pgsql')->query(
                    'SELECT id FROM sand_ai_usage_event WHERE speech_run_id=?', [$runId],
                );
                if (count($events) !== 1) {
                    throw new ApiProblem('SAND_AI_AUDIO_SPEECH_USAGE_MISSING', 'Completed speech has no usage event');
                }
                return $this->summary($run);
            }
            if ($run['state'] !== 'awaiting_usage' || $run['audio_sha256'] === null
                || $run['audio_bytes'] === null) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech is not ready for usage', true);
            }
            $identity = is_array($run['identity_snapshot']) ? $run['identity_snapshot']
                : json_decode((string) $run['identity_snapshot'], true);
            if (!is_array($identity) || $this->canonical($identity) !== $this->canonical($attribution->toPersisted())) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Speech identity changed before metering');
            }
            $price = SpeechCallStartPrice::fromRun($run);
            $quantity = (int) $run['input_characters'];
            if ($quantity < 1 || $quantity > 4096) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_USAGE_INVALID', 'Speech character count is invalid');
            }
            $amount = $price->amount($quantity);
            $existing = Db::connect('pgsql')->query(
                'SELECT id FROM sand_ai_usage_event WHERE speech_run_id=?', [$runId],
            );
            if ($existing !== []) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Speech usage was sealed before completion');
            }
            $provider = ['id' => (int) $run['provider_id']];
            $model = ['id' => (int) $run['model_id']];
            $deployment = ['id' => (int) $run['deployment_id'], 'profile_id' => (int) $run['profile_id'],
                'profile_revision' => (int) $run['profile_revision'], 'route_id' => (int) $run['route_id']];
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_usage_event
                 (speech_run_id,organization_id,application_id,environment_id,workload_client_id,
                  identity_snapshot,provider_snapshot,model_snapshot,deployment_snapshot,
                  provider_id,model_id,deployment_id,service_action,source_kind,capability_code,
                  metering_unit,metering_quantity,input_tokens,output_tokens,total_tokens,usage_state,
                  price_revision_id,price_snapshot,metered_amount,reconciled_amount,currency,
                  pricing_state,reconciliation_state,idempotency_key,create_time)
                 VALUES (?,?,?,?,?,CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),CAST(? AS jsonb),
                         ?,?,?,?,?,?,?,CAST(? AS numeric(20,6)),?,?,?,?,?,CAST(? AS jsonb),
                         CAST(? AS numeric(20,6)),NULL,?,?,?,?,?)',
                [$runId, $attribution->organizationId, $attribution->applicationId,
                    $attribution->environmentId, $attribution->workloadClientId,
                    json_encode($attribution->identitySnapshot, JSON_THROW_ON_ERROR),
                    json_encode($provider, JSON_THROW_ON_ERROR), json_encode($model, JSON_THROW_ON_ERROR),
                    json_encode($deployment, JSON_THROW_ON_ERROR),
                    (int) $run['provider_id'], (int) $run['model_id'], (int) $run['deployment_id'],
                    'sand_ai.audio.speech', 'capability', 'audio_speech', 'input_characters', (string) $quantity,
                    0, 0, 0, 'measured', $price->revisionId,
                    json_encode($price->snapshot(), JSON_THROW_ON_ERROR), $amount, $price->currency,
                    $price->revisionId === null ? 'unpriced' : 'priced', 'unreconciled',
                    'speech-run:' . $runId, $price->calledAt],
            );
            if ($run['budget_reservation_id'] !== null) {
                if ($amount === null || $price->revisionId === null) {
                    throw new ApiProblem('SAND_AI_BUDGET_UNPRICED', 'Budgeted speech usage has no price');
                }
                (new SpeechBudgetGuard())->settle($run, $amount, $price->revisionId);
            }
            $changed = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_speech_run SET state='succeeded',complete_time=CURRENT_TIMESTAMP(0),
                 update_time=CURRENT_TIMESTAMP(0) WHERE id=? AND state='awaiting_usage'",
                [$runId],
            );
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_USAGE_INVALID', 'Speech usage could not be sealed');
            }
            $run['state'] = 'succeeded';
            return $this->summary($run);
        });
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as &$item) {
                $item = json_decode($this->canonical($item), true, 512, JSON_THROW_ON_ERROR);
            }
            unset($item);
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function summary(array $run): array
    {
        return ['public_id' => (string) $run['public_id'], 'state' => (string) $run['state'],
            'input_characters' => (int) $run['input_characters']];
    }
}
