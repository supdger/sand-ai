<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Application\File\FileOwnershipGuard;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\FileInvocationFacts;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\KnowledgeBaseSource;
use app\Infrastructure\Persistence\Model\KnowledgeBaseVersion;

/** Checks every frozen knowledge source before a public Skill reads evidence. */
final class SkillSourceScope
{
    public function forVersion(int $versionId, UsageAttribution $attribution): ?string
    {
        $version = KnowledgeBaseVersion::where('id', $versionId)->where('state', 'published')->find();
        if ($version === null) {
            self::unverified();
        }
        $sources = KnowledgeBaseSource::where('knowledge_base_version_id', $versionId)->select();
        if (count($sources) < 1 || count($sources) !== (int) $version->source_count) {
            self::unverified();
        }
        $expectedScope = FileOwnershipGuard::scope($attribution);
        $facts = [];
        foreach ($sources as $source) {
            $file = AiFile::where('id', (int) $source->file_id)
                ->where('status', 1)->whereNull('delete_time')->find();
            if ($file === null) {
                self::unverified();
            }
            (new FileOwnershipGuard())->assert($file, $expectedScope);
            $facts[] = FileInvocationFacts::fromPersisted([
                'id' => $file->id, 'status' => $file->status,
                'delete_time' => $file->delete_time, 'state' => $file->state,
                'environment_id' => $file->environment_id,
                'owner_scope' => $file->owner_scope,
                'owner_attribution' => $file->owner_attribution,
            ], (int) $source->file_id);
        }
        return self::fromFacts($facts, $attribution);
    }

    /** @param list<array<string,mixed>> $facts */
    public static function fromFacts(array $facts, UsageAttribution $attribution): ?string
    {
        if ($facts === [] || $attribution->organizationId === null
            || $attribution->applicationId === null || $attribution->workloadClientId === null) {
            self::unverified();
        }
        $first = true;
        $dataClass = null;
        foreach ($facts as $item) {
            if (($item['organization_id'] ?? null) !== $attribution->organizationId
                || ($item['application_id'] ?? null) !== $attribution->applicationId
                || ($item['environment_id'] ?? null) !== $attribution->environmentId
                || ($item['workload_client_id'] ?? null) !== $attribution->workloadClientId
                || ($item['resource_type'] ?? null) !== 'sand_ai.file'
                || !array_key_exists('data_class', $item)) {
                self::unverified();
            }
            $candidate = $item['data_class'];
            if ($candidate !== null && (!is_string($candidate)
                || preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/D', $candidate) !== 1)) {
                self::unverified();
            }
            if (!$first && $candidate !== $dataClass) {
                self::unverified();
            }
            $dataClass = $candidate;
            $first = false;
        }
        return $dataClass;
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill knowledge source is unavailable');
    }
}
