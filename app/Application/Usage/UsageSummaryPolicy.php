<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** Parses the bounded, admin-only usage-summary query. */
final class UsageSummaryPolicy
{
    private const MAX_DAYS = 93;

    public function __construct(private readonly PricingValuePolicy $values = new PricingValuePolicy()) {}

    /** @param array<string, mixed> $query @return array<string, int|string|null> */
    public function normalize(array $query, ?DateTimeImmutable $now = null): array
    {
        $allowed = ['organization_id', 'environment_id', 'from_time', 'to_time', 'application_id', 'provider_id', 'model_id', 'skill_id', 'skill_version_id', 'skill_run_id', 'composition_run_id', 'composition_node_code', 'workflow_run_id', 'agent_run_id', 'agent_definition_id', 'agent_version_id', 'channel_binding_id', 'delivery_platform', 'knowledge_base_id', 'knowledge_base_version_id', 'knowledge_query_id', 'knowledge_index_id', 'service_code', 'service_action', 'source_kind', 'capability_code', 'pricing_state', 'usage_state', 'reconciliation_state'];
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Usage summary query contains unknown fields');
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $to = $this->optionalTime($query['to_time'] ?? null) ?? $now;
        $from = $this->optionalTime($query['from_time'] ?? null) ?? $to->sub(new DateInterval('P30D'));
        if ($to <= $from || $from->add(new DateInterval('P' . self::MAX_DAYS . 'D')) < $to) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'The requested time range must be positive and no longer than 93 days');
        }

        $result = [
            'organization_id' => $this->optionalPositiveId($query['organization_id'] ?? null, 'organization_id'),
            'environment_id' => $this->optionalPositiveId($query['environment_id'] ?? null, 'environment_id'),
            'from_time' => $from->format('Y-m-d H:i:s'),
            'to_time' => $to->format('Y-m-d H:i:s'),
            'application_id' => $this->optionalPositiveId($query['application_id'] ?? null, 'application_id'),
            'provider_id' => $this->optionalPositiveId($query['provider_id'] ?? null, 'provider_id'),
            'model_id' => $this->optionalPositiveId($query['model_id'] ?? null, 'model_id'),
            'skill_id' => $this->optionalPositiveId($query['skill_id'] ?? null, 'skill_id'),
            'skill_version_id' => $this->optionalPositiveId($query['skill_version_id'] ?? null, 'skill_version_id'),
            'skill_run_id' => $this->optionalPositiveId($query['skill_run_id'] ?? null, 'skill_run_id'),
            'composition_run_id' => $this->optionalPositiveId($query['composition_run_id'] ?? null, 'composition_run_id'),
            'composition_node_code' => $this->optionalNodeCode($query['composition_node_code'] ?? null),
            'workflow_run_id' => $this->optionalPositiveId($query['workflow_run_id'] ?? null, 'workflow_run_id'),
            'agent_run_id' => $this->optionalPositiveId($query['agent_run_id'] ?? null, 'agent_run_id'),
            'agent_definition_id' => $this->optionalPositiveId($query['agent_definition_id'] ?? null, 'agent_definition_id'),
            'agent_version_id' => $this->optionalPositiveId($query['agent_version_id'] ?? null, 'agent_version_id'),
            'channel_binding_id' => $this->optionalPositiveId($query['channel_binding_id'] ?? null, 'channel_binding_id'),
            'delivery_platform' => $this->optionalEnum($query['delivery_platform'] ?? null, ['feishu', 'wecom'], 'delivery_platform'),
            'knowledge_base_id' => $this->optionalPositiveId($query['knowledge_base_id'] ?? null, 'knowledge_base_id'),
            'knowledge_base_version_id' => $this->optionalPositiveId($query['knowledge_base_version_id'] ?? null, 'knowledge_base_version_id'),
            'knowledge_query_id' => $this->optionalPositiveId($query['knowledge_query_id'] ?? null, 'knowledge_query_id'),
            'knowledge_index_id' => $this->optionalPositiveId($query['knowledge_index_id'] ?? null, 'knowledge_index_id'),
            'service_code' => $this->optionalEnum($query['service_code'] ?? null, ['sand_ai'], 'service_code') ?? 'sand_ai',
            'service_action' => $this->optionalCode($query['service_action'] ?? null, 'service_action'),
            'source_kind' => $this->optionalEnum($query['source_kind'] ?? null, ['chat', 'ai_task', 'agent', 'capability', 'workflow', 'structured_workflow', 'external_service', 'onboarding_verification', 'skill', 'skill_test', 'tool', 'knowledge_index', 'knowledge_query'], 'source_kind'),
            'capability_code' => $this->optionalCode($query['capability_code'] ?? null, 'capability_code'),
            'pricing_state' => $this->optionalEnum($query['pricing_state'] ?? null, ['priced', 'unpriced'], 'pricing_state'),
            'usage_state' => $this->optionalEnum($query['usage_state'] ?? null, ['measured', 'unmeasured'], 'usage_state'),
            'reconciliation_state' => $this->optionalEnum($query['reconciliation_state'] ?? null, ['unreconciled', 'reconciled', 'discrepancy'], 'reconciliation_state'),
        ];

        if ($result['composition_node_code'] !== null && $result['composition_run_id'] === null) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A composition node filter requires its run id');
        }
        return $result;
    }

    private function optionalNodeCode(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $value) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Composition node code is invalid');
        }
        return $value;
    }

    private function optionalTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Time filters must use Y-m-d H:i:s');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Time filters must use Y-m-d H:i:s');
        }

        return $date;
    }

    private function positiveId(mixed $value, string $field): int
    {
        return $this->values->positiveId($value, $field);
    }

    private function optionalPositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $field);
    }

    private function optionalCode(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$field} is invalid");
        $code = trim($value);
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/', $code) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$field} is invalid");
        }

        return $code;
    }

    /** @param list<string> $allowed */
    private function optionalEnum(mixed $value, array $allowed, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', "{$field} is invalid");
        }

        return $value;
    }
}
