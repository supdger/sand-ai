<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;

/** Projects one immutable audio event against the task's frozen execution facts. */
final class AudioTaskCostProjection
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $event
     * @return array{state:string,audio_seconds:string,metered_amount:?string,currency:?string,price_revision_id:?int}
     */
    public function present(int $taskId, int $environmentId, array $input, array $result, ?array $event): array
    {
        $seconds = new AudioSeconds((string) ($result['usage']['audio_seconds'] ?? ''));
        if ($event === null) {
            return [
                'state' => 'unavailable',
                'audio_seconds' => $seconds->value,
                'metered_amount' => null,
                'currency' => null,
                'price_revision_id' => null,
            ];
        }
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                is_array($input['usage_attribution'] ?? null) ? $input['usage_attribution'] : null,
                $environmentId, 'capability', 'sand_ai.audio.transcription',
            );
            $target = AudioExecutionTarget::fromPersistence(
                is_array($input['execution_target'] ?? null) ? $input['execution_target'] : [],
            );
            $pricing = AudioCallStartPricingSnapshot::fromPersistence(
                is_array($input['pricing_snapshot'] ?? null) ? $input['pricing_snapshot'] : [],
            )->usagePricing($seconds);
            if ($attribution->capabilityCode !== 'audio_transcription') {
                self::invalid();
            }
        } catch (\Throwable) {
            self::invalid();
        }
        $identity = $event['identity_snapshot'] ?? null;
        if (is_string($identity)) {
            $identity = json_decode($identity, true);
        }
        if (!is_array($identity) || $identity != $attribution->identitySnapshot) {
            self::invalid();
        }
        foreach ([
            'audio_task_run_id' => $taskId,
            'environment_id' => $environmentId,
            'organization_id' => $attribution->organizationId,
            'application_id' => $attribution->applicationId,
            'workload_client_id' => $attribution->workloadClientId,
            'provider_id' => $target->provider['id'],
            'model_id' => $target->model['id'],
            'deployment_id' => $target->deployment['id'],
            'service_action' => 'sand_ai.audio.transcription',
            'source_kind' => 'capability',
            'capability_code' => 'audio_transcription',
            'metering_unit' => 'audio_seconds',
            'usage_state' => 'measured',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'reconciliation_state' => 'unreconciled',
            'reconciled_amount' => null,
            'pricing_state' => $pricing['pricing_state'],
            'currency' => $pricing['currency'],
        ] as $field => $expected) {
            if ($expected === null ? ($event[$field] ?? null) !== null
                : (string) ($event[$field] ?? '') !== (string) $expected) {
                self::invalid();
            }
        }
        if ((string) ($event['metering_quantity'] ?? '') !== $seconds->value
            || ($pricing['price_revision_id'] === null
                ? ($event['price_revision_id'] ?? null) !== null
                : (string) ($event['price_revision_id'] ?? '') !== (string) $pricing['price_revision_id'])
            || ($pricing['metered_amount'] === null
                ? ($event['metered_amount'] ?? null) !== null
                : (string) ($event['metered_amount'] ?? '') !== $pricing['metered_amount'])) {
            self::invalid();
        }
        return [
            'state' => $pricing['pricing_state'],
            'audio_seconds' => $seconds->value,
            'metered_amount' => $pricing['metered_amount'],
            'currency' => $pricing['currency'],
            'price_revision_id' => $pricing['price_revision_id'],
        ];
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Audio usage evidence does not match the task');
    }
}
