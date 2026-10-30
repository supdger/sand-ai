<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\MaterialAnalysisProfile;

final class MaterialAnalysisProfileRegistry
{
    /** @param null|\Closure(int,string,string,bool):?array<string,mixed> $lookup */
    public function __construct(
        private readonly ?\Closure $lookup = null,
        private readonly MaterialAnalysisProfilePolicy $profilePolicy = new MaterialAnalysisProfilePolicy(),
    )
    {
    }

    /** @return array<string,mixed> */
    public function profile(int $environmentId, string $code, string $version): array
    {
        return $this->read($environmentId, $code, $version, false);
    }

    /** Must be called inside the PostgreSQL submit transaction. */
    public function assertSnapshotCurrent(int $environmentId, string $code, string $version, array $snapshot): void
    {
        $current = $this->read($environmentId, $code, $version, true);
        if (!hash_equals(
            StructuredWorkflowPolicy::fingerprint($current),
            StructuredWorkflowPolicy::fingerprint($snapshot),
        )) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The published material analysis profile changed before submission');
        }
    }

    /** @return array<string,mixed> */
    private function read(int $environmentId, string $code, string $version, bool $forUpdate): array
    {
        if ($environmentId < 1 || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis profile is not registered');
        }
        $row = $this->lookup !== null
            ? ($this->lookup)($environmentId, $code, $version, $forUpdate)
            : $this->publishedProfile($environmentId, $code, $version, $forUpdate);
        if (!is_array($row) || ($row['environment_id'] ?? null) !== $environmentId
            || ($row['code'] ?? null) !== $code || ($row['version'] ?? null) !== $version
            || ($row['state'] ?? null) !== 'published' || ($row['status'] ?? null) !== 1
            || !is_array($row['config'] ?? null)) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis profile is not published for this environment');
        }
        $profile = $this->profilePolicy->validate($row['config']);
        if ($profile['code'] !== $code || $profile['version'] !== $version) {
            throw new ApiProblem('SAND_AI_SCHEMA_UNSUPPORTED', 'The material analysis profile snapshot is inconsistent');
        }
        return $profile;
    }

    /** @return array<string,mixed>|null */
    private function publishedProfile(int $environmentId, string $code, string $version, bool $forUpdate): ?array
    {
        $query = MaterialAnalysisProfile::where('environment_id', $environmentId)
            ->where('code', $code)->where('version', $version)->where('state', 'published')
            ->where('status', 1)->whereNull('delete_time');
        $profile = ($forUpdate ? $query->lock(true) : $query)->find();
        if ($profile === null) {
            return null;
        }
        return [
            'environment_id' => (int) $profile->environment_id,
            'code' => (string) $profile->code,
            'version' => (string) $profile->version,
            'state' => (string) $profile->state,
            'status' => (int) $profile->status,
            'config' => $profile->config,
        ];
    }
}
