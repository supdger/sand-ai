<?php

declare(strict_types=1);

namespace app\Identity\Infrastructure;

use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Identity\Contract\IdentityContextException;
use think\facade\Db;

/** SandIAM facts come only from the reserved SandAI speech run, never request fields. */
final class SpeechInvocationFacts
{
    /** @return array{organization_id:int,application_id:int,environment_id:int,workload_client_id:int,data_class:null,resource_type:string,resource_ref:string} */
    public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array
    {
        if ($serviceCode !== 'sand_ai'
            || !in_array($actionCode, ['sand_ai.audio.speech', 'sand_ai.audio.speech.read'], true)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $resourceKey) !== 1) {
            self::unverified();
        }
        $runId = filter_var($resourceKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($runId === false || (string) $runId !== $resourceKey) self::unverified();
        $rows = Db::connect('pgsql')->query(
            'SELECT id,environment_id,owner_scope,identity_snapshot,state
             FROM sand_ai_speech_run WHERE id=?',
            [$runId],
        );
        if (count($rows) !== 1) self::unverified();
        $row = $rows[0];
        $snapshot = is_string($row['identity_snapshot'])
            ? json_decode($row['identity_snapshot'], true) : $row['identity_snapshot'];
        if (!is_array($snapshot) || !in_array($row['state'], [
            'prepared', 'dispatching', 'awaiting_usage', 'succeeded', 'failed', 'ambiguous',
        ], true)) self::unverified();
        try {
            $attribution = UsageAttribution::fromCurrentPersisted(
                $snapshot, (int) $row['environment_id'], 'capability', 'sand_ai.audio.speech',
            );
            if ($attribution->capabilityCode !== 'audio_speech'
                || !hash_equals((string) $row['owner_scope'], RequestIdempotencyKey::trustedScopeForAttribution($attribution))
                || $attribution->organizationId === null
                || $attribution->applicationId === null
                || $attribution->workloadClientId === null) {
                self::unverified();
            }
        } catch (\InvalidArgumentException) {
            self::unverified();
        }
        return [
            'organization_id' => $attribution->organizationId,
            'application_id' => $attribution->applicationId,
            'environment_id' => $attribution->environmentId,
            'workload_client_id' => $attribution->workloadClientId,
            'data_class' => null,
            'resource_type' => 'sand_ai.speech_run',
            'resource_ref' => (string) $runId,
        ];
    }

    private static function unverified(): never
    {
        throw new IdentityContextException('SAND_AI_INVOCATION_FACTS_UNVERIFIED');
    }
}
