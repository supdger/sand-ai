<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\AiModel;
use app\Infrastructure\Persistence\Model\ModelDeployment;
use app\Infrastructure\Persistence\Model\Provider;
use app\Infrastructure\Persistence\Model\UsageEvent;
use think\facade\Db;
use app\Application\Skill\SkillAttribution;
use app\Application\Budget\BudgetDecimal;

/** Writes one immutable pricing event for each successful model invocation. */
final class UsageMeter
{
    /** @return array<string, mixed> */
    /** @param array<string, int|string>|null $routeSnapshot */
    public function meter(Invocation $invocation, ModelDeployment $deployment, Provider $provider, AiModel $model, UsageAttribution $attribution, int $inputTokens, int $outputTokens, bool $usageMeasured, CallStartPricingSnapshot $pricingSnapshot, ?array $routeSnapshot = null, ?SkillAttribution $skillAttribution = null): array
    {
        return $this->meterFrozen(
            $invocation, $attribution, $inputTokens, $outputTokens, $usageMeasured, $pricingSnapshot,
            ['id' => (int) $provider->id, 'code' => (string) $provider->code, 'name' => (string) $provider->getAttr('name')],
            ['id' => (int) $model->id, 'code' => (string) $model->code, 'name' => (string) $model->getAttr('name')],
            ['id' => (int) $deployment->id, 'remote_model' => (string) $deployment->remote_model, 'revision' => (int) $deployment->revision, 'route' => $this->usageRouteSnapshot($routeSnapshot)],
            $skillAttribution,
        );
    }

    /** @param array<string,mixed> $providerSnapshot @param array<string,mixed> $modelSnapshot @param array<string,mixed> $deploymentSnapshot @return array<string,mixed> */
    public function meterFrozen(Invocation $invocation, UsageAttribution $attribution, int $inputTokens, int $outputTokens, bool $usageMeasured, CallStartPricingSnapshot $pricingSnapshot, array $providerSnapshot, array $modelSnapshot, array $deploymentSnapshot, ?SkillAttribution $skillAttribution = null): array
    {
        if ($inputTokens < 0 || $outputTokens < 0 || $inputTokens > PHP_INT_MAX - $outputTokens
            || (int) ($providerSnapshot['id'] ?? 0) < 1 || (int) ($modelSnapshot['id'] ?? 0) !== (int) $invocation->model_id
            || (int) ($deploymentSnapshot['id'] ?? 0) !== (int) $invocation->deployment_id) {
            throw new \app\Api\Support\ApiProblem('SAND_AI_RECEIPT_INVALID', 'Frozen usage facts are invalid');
        }
        $existing = UsageEvent::where('invocation_id', (int) $invocation->id)->find();
        if ($existing !== null) {
            // Model JSON casts decode objects as associative arrays. Read the
            // immutable row without casts so replay retains object/list types,
            // nulls and exact PostgreSQL numeric evidence.
            $persisted = Db::connect('pgsql')->table('sand_ai_usage_event')
                ->where('id', (int) $existing->id)->find();
            if (!is_array($persisted)) {
                throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Immutable usage evidence is unavailable');
            }
            $this->assertReplayMatches($persisted, $invocation, $attribution, $inputTokens, $outputTokens, $usageMeasured, $pricingSnapshot, $providerSnapshot, $modelSnapshot, $deploymentSnapshot, $skillAttribution);
            return $this->summary($existing);
        }

        // UsageEvent is inserted with raw SQL rather than model timestamps.
        // Store its query boundary in the same explicit UTC convention as the
        // call-start price decision; PostgreSQL's session default must not move
        // an event outside an administrator's UTC summary window.
        $recordedAt = gmdate('Y-m-d H:i:s');
        $event = [
            'environment_id' => $attribution->environmentId,
            'organization_id' => $attribution->organizationId,
            'application_id' => $attribution->applicationId,
            'workload_client_id' => $attribution->workloadClientId,
            'identity_snapshot' => $attribution->identitySnapshot,
            'provider_snapshot' => $providerSnapshot,
            'model_snapshot' => $modelSnapshot,
            // A public workload can never select this itself: ChatGateway
            // derives the published profile/route while resolving the model.
            // Keep that immutable route evidence beside the deployment target
            // without widening the public Usage DTO.
            'deployment_snapshot' => $deploymentSnapshot,
            'invocation_id' => (int) $invocation->id,
            'provider_id' => (int) $providerSnapshot['id'],
            'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $deploymentSnapshot['id'],
            'skill_id' => $skillAttribution?->skillId, 'skill_version_id' => $skillAttribution?->skillVersionId, 'skill_run_id' => $skillAttribution?->skillRunId,
            'service_action' => $attribution->serviceAction,
            'source_kind' => $attribution->sourceKind,
            'capability_code' => $attribution->capabilityCode,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'usage_state' => $usageMeasured ? 'measured' : 'unmeasured',
            'reconciliation_state' => 'unreconciled',
            'reconciled_amount' => null,
            'idempotency_key' => 'invocation:' . (int) $invocation->id,
            'create_time' => $recordedAt,
        ];
        $event += $pricingSnapshot->usagePricing($inputTokens, $outputTokens, $usageMeasured);

        return $this->summary($this->insertEvent($event));
    }


    /** @param array<string,mixed> $attempt @param array<string,mixed> $receipt @return array<string,mixed> */
    public function meterAdminValidation(array $attempt, array $receipt): array
    {
        if (($attempt['capability_code'] ?? null) === 'rerank') return $this->meterProfessionalAdminValidation($attempt, $receipt);
        $id = (int) $attempt['id'];
        $measured = $receipt['usage_measured'] === true;
        $input = $measured ? (int) $receipt['input_tokens'] : 0;
        $output = $measured ? (int) $receipt['output_tokens'] : 0;
        $pricing = CallStartPricingSnapshot::fromPersistence($attempt['call_started_at'], $attempt['call_start_pricing_state'], $attempt['call_start_price_revision_id'], $attempt['call_start_price_snapshot']);
        $snapshot = $attempt['execution_snapshot'];
        $expected = [
            'admin_validation_attempt_id' => $id, 'admin_actor_id' => (int) $attempt['admin_actor_id'],
            'organization_id' => null, 'application_id' => null, 'environment_id' => null, 'workload_client_id' => null,
            'invocation_id' => null, 'provider_id' => $attempt['provider_id'] === null ? null : (int) $attempt['provider_id'],
            'model_id' => $attempt['model_id'] === null ? null : (int) $attempt['model_id'],
            'deployment_id' => $attempt['deployment_id'] === null ? null : (int) $attempt['deployment_id'],
            'service_action' => $attempt['service_action'], 'source_kind' => 'admin_verify', 'capability_code' => $attempt['capability_code'],
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output,
            'usage_state' => $measured ? 'measured' : 'unmeasured', 'reconciliation_state' => 'unreconciled', 'reconciled_amount' => null,
            'idempotency_key' => 'admin_validation:' . $id,
        ] + $pricing->usagePricing($input, $output, $measured);
        $snapshots = [
            'identity_snapshot' => ['scope_kind' => 'platform_admin', 'admin_actor_id' => (int) $attempt['admin_actor_id']],
            'provider_snapshot' => $snapshot['provider'] ?? [], 'model_snapshot' => $snapshot['model'] ?? [],
            'deployment_snapshot' => $snapshot['deployment'] ?? [], 'price_snapshot' => $expected['price_snapshot'],
        ];
        unset($expected['price_snapshot']);
        $connection = Db::connect('pgsql');
        $existing = $connection->table('sand_ai_usage_event')->where('admin_validation_attempt_id', $id)->find();
        if (is_array($existing)) {
            foreach ($expected as $field => $value) {
                if (!array_key_exists($field, $existing) || !$this->sameReplayValue($field, $existing[$field], $value)) {
                    throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Platform usage facts differ from the immutable receipt');
                }
            }
            foreach ($snapshots as $field => $value) {
                if (!$this->sameReplaySnapshot($existing[$field] ?? null, $value)) throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Platform usage snapshot differs from the immutable receipt');
            }
            return $existing;
        }
        $expected['create_time'] = gmdate('Y-m-d H:i:s');
        foreach ($snapshots as $field => $value) $expected[$field] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $eventId = $connection->table('sand_ai_usage_event')->insertGetId($expected);
        return $connection->table('sand_ai_usage_event')->where('id', $eventId)->find();
    }

    /** Professional platform calls retain actual service units and no token quantities. */
    private function meterProfessionalAdminValidation(array $attempt, array $receipt): array
    {
        $price = \app\Application\Knowledge\KnowledgeProfessionalRerankPrice::fromPersistence(
            $attempt['call_started_at'], $attempt['call_start_pricing_state'],
            $attempt['call_start_price_revision_id'], $attempt['call_start_price_snapshot']);
        $quantity = $receipt['rerank_quantity'] ?? null;
        if (($receipt['rerank_meter_code'] ?? null) !== $price->meteringUnit
            || !is_bool($receipt['usage_measured'] ?? null) || $receipt['usage_measured'] !== ($quantity !== null)
            || ($quantity !== null && (!is_int($quantity) || $quantity < 0))
            || ($receipt['input_tokens'] ?? null) !== null || ($receipt['output_tokens'] ?? null) !== null
            || ($receipt['total_tokens'] ?? null) !== null) {
            throw new \app\Api\Support\ApiProblem('SAND_AI_RECEIPT_INVALID', 'Professional platform usage facts are invalid');
        }
        $amount = $price->amount($quantity);
        $snapshot = $attempt['execution_snapshot'];
        $expected = [
            'admin_validation_attempt_id' => (int) $attempt['id'], 'admin_actor_id' => (int) $attempt['admin_actor_id'],
            'organization_id' => null, 'application_id' => null, 'environment_id' => null, 'workload_client_id' => null,
            'invocation_id' => null, 'provider_id' => (int) $attempt['provider_id'], 'model_id' => (int) $attempt['model_id'],
            'deployment_id' => (int) $attempt['deployment_id'], 'service_action' => $attempt['service_action'],
            'source_kind' => 'admin_verify', 'capability_code' => 'rerank', 'metering_unit' => $price->meteringUnit,
            'metering_quantity' => $quantity, 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'usage_state' => $quantity === null ? 'unmeasured' : 'measured', 'reconciliation_state' => 'unreconciled',
            'reconciled_amount' => null, 'idempotency_key' => 'admin_validation:' . (int) $attempt['id'],
            'pricing_state' => $amount === null ? 'unpriced' : 'priced', 'price_revision_id' => $amount === null ? null : $price->revisionId,
            'metered_amount' => $amount, 'currency' => $amount === null ? null : $price->currency,
        ];
        $snapshots = [
            'identity_snapshot' => ['scope_kind' => 'platform_admin', 'admin_actor_id' => (int) $attempt['admin_actor_id']],
            'provider_snapshot' => $snapshot['provider'] ?? [], 'model_snapshot' => $snapshot['model'] ?? [],
            'deployment_snapshot' => $snapshot['deployment'] ?? [],
            'price_snapshot' => $price->snapshot() + ['called_at' => $price->calledAt, 'quantity' => $quantity, 'metered_amount' => $amount],
        ];
        $connection = Db::connect('pgsql');
        $existing = $connection->table('sand_ai_usage_event')->where('admin_validation_attempt_id', (int) $attempt['id'])->find();
        if (is_array($existing)) {
            foreach ($expected as $field => $value) {
                $actual = $existing[$field] ?? null;
                if ($field === 'metering_quantity' && $value !== null) {
                    if ((!is_string($actual) && !is_int($actual)) || BudgetDecimal::compare((string) $actual, (string) $value) !== 0) {
                        throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Professional platform quantity changed');
                    }
                } elseif (!$this->sameReplayValue($field, $actual, $value)) {
                    throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Professional platform usage changed');
                }
            }
            foreach ($snapshots as $field => $value) if (!$this->sameReplaySnapshot($existing[$field] ?? null, $value)) {
                throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Professional platform snapshot changed');
            }
            return $existing;
        }
        foreach ($snapshots as $field => $value) $expected[$field] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected['create_time'] = gmdate('Y-m-d H:i:s');
        $id = $connection->table('sand_ai_usage_event')->insertGetId($expected);
        return $connection->table('sand_ai_usage_event')->where('id', $id)->find();
    }

    /** @param array<string,mixed> $event */
    private function assertReplayMatches(array $event, Invocation $invocation, UsageAttribution $attribution, int $inputTokens, int $outputTokens, bool $usageMeasured, CallStartPricingSnapshot $pricingSnapshot, array $providerSnapshot, array $modelSnapshot, array $deploymentSnapshot, ?SkillAttribution $skillAttribution): void
    {
        $pricing = $pricingSnapshot->usagePricing($inputTokens, $outputTokens, $usageMeasured);
        $expected = [
            'environment_id' => $attribution->environmentId, 'organization_id' => $attribution->organizationId,
            'application_id' => $attribution->applicationId, 'workload_client_id' => $attribution->workloadClientId,
            'provider_id' => (int) $providerSnapshot['id'], 'model_id' => (int) $invocation->model_id,
            'deployment_id' => (int) $deploymentSnapshot['id'], 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens, 'usage_state' => $usageMeasured ? 'measured' : 'unmeasured',
            'service_action' => $attribution->serviceAction, 'source_kind' => $attribution->sourceKind,
            'capability_code' => $attribution->capabilityCode, 'skill_id' => $skillAttribution?->skillId,
            'skill_version_id' => $skillAttribution?->skillVersionId, 'skill_run_id' => $skillAttribution?->skillRunId,
            'invocation_id' => (int) $invocation->id,
            'idempotency_key' => 'invocation:' . (int) $invocation->id,
            'metering_unit' => null, 'metering_quantity' => null,
            'reconciliation_state' => 'unreconciled', 'reconciled_amount' => null,
        ];
        foreach (['price_revision_id', 'pricing_state', 'currency'] as $field) {
            $expected[$field] = $pricing[$field];
        }
        $expected['metered_amount'] = $pricing['metered_amount'];
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $event) || !$this->sameReplayValue($field, $event[$field], $value)) {
                throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Usage replay does not match the immutable invocation attribution');
            }
        }
        $snapshots = [
            'identity_snapshot' => $attribution->identitySnapshot,
            'provider_snapshot' => $providerSnapshot,
            'model_snapshot' => $modelSnapshot,
            'deployment_snapshot' => $deploymentSnapshot,
            'price_snapshot' => $pricing['price_snapshot'],
        ];
        foreach ($snapshots as $field => $expectedSnapshot) {
            if (!array_key_exists($field, $event) || !$this->sameReplaySnapshot($event[$field], $expectedSnapshot)) {
                throw new \app\Api\Support\ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Usage replay snapshot differs from the immutable invocation');
            }
        }
    }

    private function sameReplayValue(string $field, mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if ($field === 'metered_amount') {
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
            return (is_int($actual) || is_string($actual)) && (string) $actual === (string) $expected;
        }
        return $actual === $expected;
    }

    /** @param array<string,mixed> $expected */
    private function sameReplaySnapshot(mixed $actual, array $expected): bool
    {
        try {
            if (is_string($actual)) {
                $actual = json_decode($actual, false, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($actual) && !$actual instanceof \stdClass) {
                return false;
            }
            return json_encode($this->sortSnapshot($actual), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                === json_encode($this->sortSnapshot($expected), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            return false;
        }
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

    /** @param array<string,mixed> $event */
    private function insertEvent(array $event): UsageEvent
    {
        $rows = Db::connect('pgsql')->query(
            "INSERT INTO sand_ai_usage_event (organization_id, application_id, environment_id, workload_client_id, identity_snapshot, provider_snapshot, model_snapshot, deployment_snapshot, invocation_id, provider_id, model_id, deployment_id, skill_id, skill_version_id, skill_run_id, service_action, source_kind, capability_code, input_tokens, output_tokens, total_tokens, usage_state, price_revision_id, price_snapshot, metered_amount, reconciled_amount, currency, pricing_state, reconciliation_state, idempotency_key, create_time) VALUES (?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS numeric(20,6)), CAST(? AS numeric(20,6)), ?, ?, ?, ?, ?) RETURNING id",
            [$event['organization_id'], $event['application_id'], $event['environment_id'], $event['workload_client_id'], json_encode($event['identity_snapshot'], JSON_THROW_ON_ERROR), json_encode($event['provider_snapshot'], JSON_THROW_ON_ERROR), json_encode($event['model_snapshot'], JSON_THROW_ON_ERROR), json_encode($event['deployment_snapshot'], JSON_THROW_ON_ERROR), $event['invocation_id'], $event['provider_id'], $event['model_id'], $event['deployment_id'], $event['skill_id'], $event['skill_version_id'], $event['skill_run_id'], $event['service_action'], $event['source_kind'], $event['capability_code'], $event['input_tokens'], $event['output_tokens'], $event['total_tokens'], $event['usage_state'], $event['price_revision_id'], json_encode($event['price_snapshot'], JSON_THROW_ON_ERROR), ...NumericSql::binds($event['metered_amount'], $event['reconciled_amount']), $event['currency'], $event['pricing_state'], $event['reconciliation_state'], $event['idempotency_key'], $event['create_time']]
        );
        return UsageEvent::find((int) $rows[0]['id']);
    }

    /** @return array<string, mixed> */
    public function summary(UsageEvent $event): array
    {
        $measured = (string) $event->usage_state === 'measured';
        return [
            'estimated_amount' => null,
            'metered_amount' => $event->metered_amount === null ? null : (string) $event->metered_amount,
            'reconciled_amount' => null,
            'pricing_state' => (string) $event->pricing_state,
            'usage_state' => (string) $event->usage_state,
            'reconciliation_state' => (string) $event->reconciliation_state,
            'currency' => $event->currency === null ? null : (string) $event->currency,
            'tokens' => [
                'input_tokens' => $measured ? (int) $event->input_tokens : null,
                'output_tokens' => $measured ? (int) $event->output_tokens : null,
                'total_tokens' => $measured ? (int) $event->total_tokens : null,
            ],
        ];
    }

    /** @param array<string, int|string>|null $routeSnapshot @return array<string,int|string> */
    public function usageRouteSnapshot(?array $routeSnapshot): array
    {
        if ($routeSnapshot === null) {
            return [];
        }
        $allowed = [];
        foreach (['selection_kind', 'profile_id', 'profile_revision', 'route_id'] as $field) {
            $value = $routeSnapshot[$field] ?? null;
            if ($field === 'selection_kind') {
                if (in_array($value, ['capability_profile', 'lowest_cost'], true)) {
                    $allowed[$field] = $value;
                }
                continue;
            }
            if (is_int($value) && $value > 0) {
                $allowed[$field] = $value;
            }
        }

        return $allowed;
    }
}
