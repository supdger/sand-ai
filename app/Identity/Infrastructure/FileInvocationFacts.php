<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use app\Infrastructure\Persistence\Model\AiFile;

/** Resolves file-backed service facts from the uploaded file, not the request. */
final class FileInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, [
                'sand_ai.document_parse', 'sand_ai.audio.transcription', 'sand_ai.audio.review',
                'sand_ai.file.read', 'sand_ai.file.write',
                'sand_ai.agent.run', 'sand_ai.retrieval.search',
            ], true)) {
            self::unverified();
        }
        $fileId = self::positiveId($resourceKey);
        $file = AiFile::where('id', $fileId)->where('status', 1)->whereNull('delete_time')->find();
        if ($file === null) {
            self::unverified();
        }
        return self::fromPersisted([
            'id' => $file->id,
            'status' => $file->status,
            'delete_time' => $file->delete_time,
            'state' => $file->state,
            'environment_id' => $file->environment_id,
            'owner_scope' => $file->owner_scope,
            'owner_attribution' => $file->owner_attribution,
        ], $fileId, $actionCode);
    }

    /** Explicit metadata purpose selected only by the server's file metadata reader.
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public function resolveMetadata(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai' || $actionCode !== 'sand_ai.file.read') self::unverified();
        $fileId = self::positiveId($resourceKey);
        $file = AiFile::withTrashed()->where('id', $fileId)->find();
        if ($file === null) self::unverified();
        return self::fromPersisted([
            'id' => $file->id,
            'status' => $file->status,
            'delete_time' => $file->delete_time,
            'state' => $file->state,
            'environment_id' => $file->environment_id,
            'owner_scope' => $file->owner_scope,
            'owner_attribution' => $file->owner_attribution,
        ], $fileId, $actionCode, true);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:?string,resource_type:string,resource_ref:string}
     */
    public static function fromPersisted(array $row, int $fileId, string $actionCode = 'sand_ai.file.read', bool $metadataOnly = false): array
    {
        $states = ['uploaded', 'parsing', 'parsed', 'parse_failed', 'parse_canceled'];
        // Read authorizes lifecycle metadata only; each content gateway still enforces its file state.
        if (in_array($actionCode, ['sand_ai.file.write', 'sand_ai.file.read'], true)) {
            $states[] = 'delete_failed';
            $states[] = 'deleting';
        }
        // Only the existing write/delete action may clean an unfinished upload.
        // Read/parse/content actions must not treat pending bytes as a source.
        if ($actionCode === 'sand_ai.file.write') $states[] = 'upload_pending';
        $tombstone = $metadataOnly && $actionCode === 'sand_ai.file.read'
            && ($row['state'] ?? null) === 'deleted'
            && self::positiveId($row['status'] ?? null) === 2
            && is_string($row['delete_time'] ?? null) && $row['delete_time'] !== '';
        $active = self::positiveId($row['status'] ?? null) === 1
            && array_key_exists('delete_time', $row) && $row['delete_time'] === null
            && in_array($row['state'] ?? null, $states, true);
        if (self::positiveId($row['id'] ?? null) !== $fileId || (!$active && !$tombstone)) {
            self::unverified();
        }
        $snapshot = is_object($row['owner_attribution'] ?? null)
            ? (array) $row['owner_attribution'] : ($row['owner_attribution'] ?? null);
        $scope = $row['owner_scope'] ?? null;
        if (!is_array($snapshot) || !array_key_exists('data_class', $snapshot)
            || !is_string($scope) || preg_match('/\A[a-f0-9]{64}\z/', $scope) !== 1) {
            self::unverified();
        }
        try {
            $environmentId = self::positiveId($row['environment_id'] ?? null);
            $owner = UsageAttribution::fromCurrentPersisted($snapshot, $environmentId, 'file', 'sand_ai.file.write');
            $expectedScope = \app\Application\File\FileOwnershipGuard::scope($owner);
            if (!hash_equals($scope, $expectedScope)) {
                self::unverified();
            }
        } catch (\InvalidArgumentException) {
            self::unverified();
        }
        $dataClass = $snapshot['data_class'];
        if ($dataClass !== null && (!is_string($dataClass)
            || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $dataClass) !== 1)) {
            self::unverified();
        }
        return [
            'organization_id' => self::positiveId($owner->organizationId),
            'application_id' => self::positiveId($owner->applicationId),
            'environment_id' => $environmentId,
            'workload_client_id' => self::positiveId($owner->workloadClientId),
            'data_class' => $dataClass,
            'resource_type' => 'sand_ai.file',
            'resource_ref' => (string) $fileId,
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
