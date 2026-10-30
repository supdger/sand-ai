<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\ToolConnectionRevision;
use app\Infrastructure\Persistence\Model\ToolRun;

/** Resolves a tool run's frozen caller and source classification. */
final class ToolInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || !in_array($actionCode, ['sand_ai.tool.run', 'sand_ai.tool.read'], true)) {
            self::unverified();
        }
        $runId = self::positiveId($resourceKey);
        $run = ToolRun::where('id', $runId)->find();
        if ($run === null) self::unverified();
        $revision = ToolConnectionRevision::where('id', (int) $run->tool_connection_revision_id)->find();
        if ($revision === null) self::unverified();
        return self::fromPersisted([
            'id' => $run->id,
            'state' => $run->state,
            'organization_id' => $run->organization_id,
            'application_id' => $run->application_id,
            'environment_id' => $run->environment_id,
            'workload_client_id' => $run->workload_client_id,
            'identity_snapshot' => $run->identity_snapshot,
            'execution_snapshot' => $run->execution_snapshot,
            'tool_connection_id' => $run->tool_connection_id,
            'tool_connection_revision_id' => $run->tool_connection_revision_id,
        ], [
            'id' => $revision->id,
            'tool_connection_id' => $revision->tool_connection_id,
            'data_class' => $revision->data_class,
            'state' => $revision->state,
        ], $runId);
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $revision
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $run, array $revision, int $runId): array
    {
        if (self::positiveId($run['id'] ?? null) !== $runId
            || !in_array($run['state'] ?? null, ['running', 'succeeded', 'failed', 'ambiguous'], true)
            || ($revision['state'] ?? null) !== 'published'
            || self::positiveId($run['tool_connection_id'] ?? null) !== self::positiveId($revision['tool_connection_id'] ?? null)
            || self::positiveId($run['tool_connection_revision_id'] ?? null) !== self::positiveId($revision['id'] ?? null)) {
            self::unverified();
        }
        $identity = $run['identity_snapshot'] ?? null;
        $execution = $run['execution_snapshot'] ?? null;
        $dataClass = $revision['data_class'] ?? null;
        if (!is_array($identity) || !is_array($execution)
            || !is_string($dataClass) || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1
            || ($execution['data_class'] ?? null) !== $dataClass) {
            self::unverified();
        }
        $organizationId = self::positiveId($run['organization_id'] ?? null);
        $applicationId = self::positiveId($run['application_id'] ?? null);
        $environmentId = self::positiveId($run['environment_id'] ?? null);
        $clientId = self::positiveId($run['workload_client_id'] ?? null);
        if (($identity['organization_ref'] ?? null) !== (string) $organizationId
            || ($identity['application_ref'] ?? null) !== (string) $applicationId
            || ($identity['workload_client_ref'] ?? null) !== (string) $clientId
            || ($execution['connection_id'] ?? null) !== self::positiveId($run['tool_connection_id'] ?? null)
            || ($execution['connection_revision_id'] ?? null) !== self::positiveId($revision['id'] ?? null)) {
            self::unverified();
        }
        return [
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'environment_id' => $environmentId,
            'workload_client_id' => $clientId,
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.tool_run',
            'resource_ref' => (string) $runId,
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
