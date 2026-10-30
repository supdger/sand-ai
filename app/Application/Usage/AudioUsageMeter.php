<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use app\Application\Audio\AudioBudgetGuard;
use app\Application\Budget\BudgetDecimal;

/** Writes the audio_seconds usage evidence after an audio task has a measured duration. */
final class AudioUsageMeter
{
    public function __construct(private readonly AudioUsageEventStore $store) {}

    /** @return array<string,mixed> */
    public function meter(AudioUsageRequest $request, AudioCallStartPricingSnapshot $pricing): array
    {
        $this->assertAudioAttribution($request->attribution);
        $guard = new AudioBudgetGuard();
        $anchor = $guard->lock($request->externalDispatchId,$request->taskRunId,$request->executionId);
        $event = $this->event($request, $pricing);
        $existing = $this->existing($request);
        if ($existing !== null) {
            $this->assertSame($existing, $event);
            $guard->finish($anchor,$event['metered_amount'],$event['price_revision_id']);
            return $this->summary($existing);
        }
        $saved = $this->store->append($event);
        $guard->finish($anchor,$event['metered_amount'],$event['price_revision_id']);
        return $this->summary($saved);
    }

    private function existing(AudioUsageRequest $request): ?array
    {
        if ($this->store instanceof PostgresAudioUsageEventStore) {
            return $this->store->findByExecution($request);
        }
        return $this->store->findByTaskRunId($request->taskRunId);
    }

    private function assertAudioAttribution(UsageAttribution $attribution): void
    {
        if ($attribution->sourceKind !== 'capability' || $attribution->serviceAction !== 'sand_ai.audio.transcription'
            || $attribution->capabilityCode !== 'audio_transcription') {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio Usage requires the frozen SandAI audio attribution');
        }
        try {
            UsageAttribution::fromCurrentPersisted($attribution->toPersisted(), $attribution->environmentId, 'capability', 'sand_ai.audio.transcription')->canonicalTrustedIdentity();
        } catch (\InvalidArgumentException) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio Usage requires current trusted identity references');
        }
    }

    /** @return array<string,mixed> */
    private function event(AudioUsageRequest $request, AudioCallStartPricingSnapshot $pricing): array
    {
        $price = $pricing->usagePricing($request->audioSeconds);
        return [
            'audio_task_run_id' => $request->taskRunId, 'audio_execution_id' => $request->executionId,
            'external_audio_dispatch_id' => $request->externalDispatchId, 'environment_id' => $request->attribution->environmentId,
            'organization_id' => $request->attribution->organizationId, 'application_id' => $request->attribution->applicationId,
            'workload_client_id' => $request->attribution->workloadClientId, 'identity_snapshot' => $request->attribution->identitySnapshot,
            'provider_id' => $request->providerId, 'model_id' => $request->modelId, 'deployment_id' => $request->deploymentId,
            'provider_snapshot' => $request->target->provider, 'model_snapshot' => $request->target->model, 'deployment_snapshot' => $request->target->deployment,
            'service_action' => 'sand_ai.audio.transcription', 'source_kind' => 'capability', 'capability_code' => 'audio_transcription',
            'metering_quantity' => $request->audioSeconds->value, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'usage_state' => 'measured', 'reconciliation_state' => 'unreconciled', 'reconciled_amount' => null,
            'idempotency_key' => $request->externalDispatchId !== null ? 'audio-dispatch:' . $request->externalDispatchId : 'audio-task-run:' . $request->taskRunId . ($request->executionId === null ? '' : ':' . $request->executionId), 'create_time' => $pricing->calledAt,
        ] + $price;
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function assertSame(array $actual, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $actual) || !$this->sameValue($field, $actual[$field], $value)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Audio Usage replay differs from immutable task attribution');
            }
        }
    }

    private function sameValue(string $field, mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if (in_array($field, ['metering_quantity', 'metered_amount', 'reconciled_amount'], true)) {
            if (!is_string($actual) || !is_string($expected)) {
                return false;
            }
            try {
                return BudgetDecimal::normalize($actual, false) === BudgetDecimal::normalize($expected, false);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }
        if (is_int($expected)) {
            // PDO may return bigint as text. No cast may truncate, overflow,
            // accept a float or turn a missing reference into zero.
            return (is_int($actual) || is_string($actual)) && (string) $actual === (string) $expected;
        }
        if (is_array($expected)) {
            try {
                // Db::table returns JSONB text without UsageEvent's JSON casts.
                // Decode objects as objects to retain object/list distinctions,
                // then sort keys without dropping any stored snapshot fields.
                if (is_string($actual)) {
                    $actual = json_decode($actual, false, 512, JSON_THROW_ON_ERROR);
                }
                if (!is_array($actual) && !$actual instanceof \stdClass) {
                    return false;
                }
                return $this->canonical($actual) === $this->canonical($expected);
            } catch (\JsonException) {
                return false;
            }
        }
        return $actual === $expected;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($this->sortSnapshot($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sortSnapshot(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            foreach ($fields as $key => $item) {
                $fields[$key] = $this->sortSnapshot($item);
            }
            return (object) $fields;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortSnapshot($item);
        }
        return $value;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function summary(array $event): array
    {
        return ['usage' => ['audio_seconds' => (string) $event['metering_quantity'], 'tokens' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0]],
            'cost' => ['pricing_state' => (string) $event['pricing_state'], 'price_revision_id' => $event['price_revision_id'] === null ? null : (int) $event['price_revision_id'],
                'metered_amount' => $event['metered_amount'] === null ? null : (string) $event['metered_amount'], 'currency' => $event['currency'] === null ? null : (string) $event['currency']]];
    }
}
