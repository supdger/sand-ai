<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\WorkflowRun;

/** Resolves SandIAM invocation facts from an actual SandAI workflow run. */
final class WorkflowInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, ['sand_ai.workflow.run', 'sand_ai.workflow.read'], true)) {
            self::unverified();
        }
        $runId = self::positiveId($resourceKey);
        $run = WorkflowRun::where('id', $runId)->where('status', 1)->whereNull('delete_time')->find();
        if ($run === null) {
            self::unverified();
        }

        return self::fromPersisted([
            'id' => $run->id,
            'status' => $run->status,
            'delete_time' => $run->delete_time,
            'organization_id' => $run->organization_id,
            'application_id' => $run->application_id,
            'environment_id' => $run->environment_id,
            'workload_client_id' => $run->workload_client_id,
            'definition_snapshot' => $run->definition_snapshot,
        ], $runId);
    }

    /**
     * Pure projection for testing the persisted-row boundary. Never call with
     * request fields or decoded identity claims.
     *
     * @param array<string,mixed> $row
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $row, int $runId): array
    {
        if (self::positiveId($row['id'] ?? null) !== $runId
            || !array_key_exists('delete_time', $row)
            || $row['delete_time'] !== null
            || self::positiveId($row['status'] ?? null) !== 1) {
            self::unverified();
        }
        $definition = $row['definition_snapshot'] ?? null;
        if (!is_array($definition) || !array_key_exists('data_class', $definition)) {
            self::unverified();
        }
        $dataClass = $definition['data_class'];
        if ($dataClass !== null
            && (!is_string($dataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) {
            self::unverified();
        }

        return [
            'organization_id' => self::positiveId($row['organization_id'] ?? null),
            'application_id' => self::positiveId($row['application_id'] ?? null),
            'environment_id' => self::positiveId($row['environment_id'] ?? null),
            'workload_client_id' => self::positiveId($row['workload_client_id'] ?? null),
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.workflow_run',
            'resource_ref' => (string) $runId,
        ];
    }

    private static function positiveId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed !== false && (string) $parsed === $value) {
                return $parsed;
            }
        }
        self::unverified();
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
