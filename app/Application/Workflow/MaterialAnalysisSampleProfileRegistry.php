<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\MaterialAnalysisProfile;

/** Resolves a draft only for the service-owned material sample path. */
final class MaterialAnalysisSampleProfileRegistry
{
    /** @param null|\Closure(int,string,string,bool):?array<string,mixed> $lookup */
    public function __construct(
        private readonly ?\Closure $lookup = null,
        private readonly MaterialAnalysisProfilePolicy $profilePolicy = new MaterialAnalysisProfilePolicy(),
    ) {
    }

    /** @return array{id:int,environment_id:int,code:string,version:string,revision:int,config:array<string,mixed>} */
    public function draft(int $environmentId, string $code, string $version, int $revision): array
    {
        return $this->read($environmentId, $code, $version, $revision, false);
    }

    /** Must be called inside the PostgreSQL task submission or activation transaction. */
    public function assertSnapshotCurrent(int $environmentId, array $snapshot): void
    {
        if (!is_int($snapshot['id'] ?? null) || !is_int($snapshot['revision'] ?? null)
            || ($snapshot['environment_id'] ?? null) !== $environmentId
            || !is_string($snapshot['code'] ?? null) || !is_string($snapshot['version'] ?? null)
            || !is_array($snapshot['config'] ?? null)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft snapshot is invalid');
        }
        $current = $this->read(
            $environmentId,
            $snapshot['code'],
            $snapshot['version'],
            $snapshot['revision'],
            true,
        );
        if ($current['id'] !== $snapshot['id']
            || !hash_equals(
                StructuredWorkflowPolicy::fingerprint($current['config']),
                StructuredWorkflowPolicy::fingerprint($snapshot['config']),
            )) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft changed before submission');
        }
    }

    /** @return array{id:int,environment_id:int,code:string,version:string,revision:int,config:array<string,mixed>} */
    private function read(int $environmentId, string $code, string $version, int $revision, bool $forUpdate): array
    {
        if ($environmentId < 1 || $revision < 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample requires an exact draft revision');
        }
        $row = $this->lookup !== null
            ? ($this->lookup)($environmentId, $code, $version, $forUpdate)
            : $this->draftProfile($environmentId, $code, $version, $forUpdate);
        if (!is_array($row) || ($row['environment_id'] ?? null) !== $environmentId
            || ($row['code'] ?? null) !== $code || ($row['version'] ?? null) !== $version
            || ($row['revision'] ?? null) !== $revision
            || ($row['state'] ?? null) !== 'draft' || ($row['status'] ?? null) !== 2
            || !is_int($row['id'] ?? null) || $row['id'] < 1
            || !is_array($row['config'] ?? null)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft changed or is unavailable');
        }
        $config = $this->profilePolicy->validate($row['config']);
        if ($config['code'] !== $code || $config['version'] !== $version) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'The material sample draft is inconsistent');
        }
        return [
            'id' => $row['id'],
            'environment_id' => $environmentId,
            'code' => $code,
            'version' => $version,
            'revision' => $revision,
            'config' => $config,
        ];
    }

    /** @return array<string,mixed>|null */
    private function draftProfile(int $environmentId, string $code, string $version, bool $forUpdate): ?array
    {
        $query = MaterialAnalysisProfile::where('environment_id', $environmentId)
            ->where('code', $code)->where('version', $version)->where('state', 'draft')
            ->where('status', 2)->whereNull('delete_time');
        $profile = ($forUpdate ? $query->lock(true) : $query)->find();
        if ($profile === null) return null;
        return [
            'id' => (int) $profile->id,
            'environment_id' => (int) $profile->environment_id,
            'code' => (string) $profile->code,
            'version' => (string) $profile->version,
            'revision' => (int) $profile->revision,
            'state' => (string) $profile->state,
            'status' => (int) $profile->status,
            'config' => $profile->config,
        ];
    }
}
