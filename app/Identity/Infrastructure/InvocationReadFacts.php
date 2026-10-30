<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;

/** Resolves the owner of a persisted model invocation for result and cost reads. */
final class InvocationReadFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, ['sand_ai.invocation.read', 'sand_ai.usage.read'], true)) {
            self::unverified();
        }
        $invocationId = self::positiveId($resourceKey);
        $invocation = Invocation::where('id', $invocationId)
            ->where('status', 1)->whereNull('delete_time')->find();
        $dispatch = InvocationDispatch::where('invocation_id', $invocationId)->find();
        if ($invocation === null || $dispatch === null) {
            self::unverified();
        }
        return self::fromPersisted([
            'id' => $invocation->id,
            'status' => $invocation->status,
            'delete_time' => $invocation->delete_time,
            'organization_id' => $invocation->organization_id,
            'application_id' => $invocation->application_id,
            'environment_id' => $invocation->environment_id,
            'workload_client_id' => $invocation->workload_client_id,
            'data_class' => $invocation->data_class,
        ], [
            'invocation_id' => $dispatch->invocation_id,
            'environment_id' => $dispatch->environment_id,
            'application_id' => $dispatch->application_id,
            'execution_scope' => $dispatch->execution_scope,
            'source_kind' => $dispatch->source_kind,
            'service_action' => $dispatch->service_action,
        ], $invocationId);
    }

    /**
     * @param array<string,mixed> $invocation
     * @param array<string,mixed> $dispatch
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $invocation, array $dispatch, int $invocationId): array
    {
        if (self::positiveId($invocation['id'] ?? null) !== $invocationId
            || self::positiveId($invocation['status'] ?? null) !== 1
            || !array_key_exists('delete_time', $invocation)
            || $invocation['delete_time'] !== null
            || self::positiveId($dispatch['invocation_id'] ?? null) !== $invocationId) {
            self::unverified();
        }
        $organizationId = self::positiveId($invocation['organization_id'] ?? null);
        $applicationId = self::positiveId($invocation['application_id'] ?? null);
        $environmentId = self::positiveId($invocation['environment_id'] ?? null);
        $workloadClientId = self::positiveId($invocation['workload_client_id'] ?? null);
        if (self::positiveId($dispatch['environment_id'] ?? null) !== $environmentId
            || self::positiveId($dispatch['application_id'] ?? null) !== $applicationId) {
            self::unverified();
        }
        $scope = $dispatch['execution_scope'] ?? null;
        $sourceKind = $dispatch['source_kind'] ?? null;
        $serviceAction = $dispatch['service_action'] ?? null;
        $origin = match ($scope) {
            'chat' => ['action' => 'sand_ai.chat.complete', 'kinds' => ['chat']],
            'ai_task' => ['action' => 'sand_ai.chat.complete', 'kinds' => ['ai_task']],
            'agent' => ['action' => 'sand_ai.agent.run', 'kinds' => ['agent']],
            'structured_workflow' => ['action' => 'sand_ai.workflow.run', 'kinds' => ['structured_workflow']],
            'skill' => match ($sourceKind) {
                'skill' => ['action' => 'sand_ai.skill.run', 'kinds' => ['skill']],
                'skill_test' => ['action' => 'sand_ai.skill.test', 'kinds' => ['skill_test']],
                default => self::unverified(),
            },
            'embedding' => match ($sourceKind) {
                'knowledge_index' => ['action' => 'sand_ai.knowledge.index', 'kinds' => ['knowledge_index']],
                'knowledge_query' => ['action' => 'sand_ai.knowledge.query', 'kinds' => ['knowledge_query']],
                default => self::unverified(),
            },
            default => self::unverified(),
        };
        if ($serviceAction !== $origin['action']
            || !in_array($sourceKind, $origin['kinds'], true)) {
            self::unverified();
        }
        $dataClass = $invocation['data_class'] ?? null;
        if (!array_key_exists('data_class', $invocation)
            || ($dataClass !== null && (!is_string($dataClass)
                || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1))) {
            self::unverified();
        }
        return [
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'environment_id' => $environmentId,
            'workload_client_id' => $workloadClientId,
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.invocation',
            'resource_ref' => (string) $invocationId,
        ];
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false && (string) $parsed === $value) return $parsed;
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
