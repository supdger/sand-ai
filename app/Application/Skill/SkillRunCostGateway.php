<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\DispatchRouteSnapshot;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Application\Usage\UsageGateway;
use app\Infrastructure\Persistence\Model\Invocation;
use app\Infrastructure\Persistence\Model\InvocationDispatch;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\SkillRun;
use app\Infrastructure\Persistence\Model\SkillVersion;
use app\Infrastructure\Persistence\Model\UsageEvent;

/** Fee-only ownership proof; never resolves live sources or recovers results. */
final class SkillRunCostGateway
{
    /** @return array<string,mixed> */
    public function read(SkillConsumerContext $consumer, string $code, int $versionNo, string $kind, string $originalKey, callable $authorize, ?int $attemptNo = null): array
    {
        $proof = $this->proof($consumer, $code, $versionNo, $kind, $originalKey, $attemptNo);
        $cost = (new UsageGateway())->invocationCost(
            $consumer->identity->environmentId,
            $proof['invocation_request_id'],
            static function (Invocation $invocation) use ($proof, $authorize): void {
                if ((int) $invocation->id !== $proof['invocation_id']) self::unverified();
                $authorize($invocation);
            },
        );
        // Recheck the same immutable linkage after the cost projection. Ongoing
        // dispatch/metering may advance; it must not change the proved owner.
        if ($proof !== $this->proof($consumer, $code, $versionNo, $kind, $originalKey, $attemptNo)) self::unverified();
        return $cost;
    }

    /** @return array{invocation_id:int,invocation_request_id:string,run_id:int,fingerprint:string} */
    private function proof(SkillConsumerContext $consumer, string $code, int $versionNo, string $kind, string $originalKey, ?int $attemptNo): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1 || $versionNo < 1
            || !in_array($kind, ['test', 'workload'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill cost request is invalid');
        }
        $key = $consumer->key($originalKey);
        $environment = $consumer->identity->environmentId;
        $skill = Skill::where('environment_id', $environment)->where('code', $code)
            ->field('id,environment_id,organization_id,application_id,scope_type,scope_ref,scope_source')->find();
        if ($skill === null) self::missing();
        SkillConsumerContext::assertAccess($skill, $consumer);
        $version = SkillVersion::where('skill_id', (int) $skill->id)->where('version_no', $versionNo)
            ->field('id,skill_id,execution_snapshot')->find();
        $run = $version === null ? null : SkillRun::where('environment_id', $environment)
            ->where('skill_id', (int) $skill->id)->where('skill_version_id', (int) $version->id)
            ->where('request_id', $key)->where('run_kind', $kind)
            ->find();
        if ($version === null || $run === null) self::missing();
        $attemptNo = SkillRunRecoveryRequest::attemptNumber($attemptNo) ?? 1;
        if ($attemptNo > 1) {
            if (!array_key_exists('attempt_no', $run->getData())) self::missing();
            $original = $run;
            $run = SkillRun::where('original_run_id', (int) $original->id)->where('attempt_no', $attemptNo)
                ->where('environment_id', $environment)->where('skill_version_id', (int) $version->id)
                ->where('run_kind', $kind)->find();
            if ($run === null) self::missing();
            if (!hash_equals((string) $original->request_fingerprint, (string) $run->request_fingerprint)) self::unverified();
        }
        $consumer->assertRun($run->consumer_context);
        $fingerprint = $this->fingerprint($consumer, $version, $run, $kind);
        if ($run->invocation_id === null) self::missing();
        $invocationId = (int) $run->invocation_id;
        if ($invocationId < 1) self::unverified();
        $invocation = Invocation::where('id', $invocationId)->where('status', 1)
            ->field('id,organization_id,application_id,environment_id,workload_client_id,skill_id,skill_version_id,skill_run_id,request_id')->find();
        $dispatch = InvocationDispatch::where('invocation_id', $invocationId)
            ->field('invocation_id,environment_id,application_id,execution_scope,source_kind,service_action,skill_id,skill_version_id,skill_run_id')->find();
        if ($invocation === null || $dispatch === null) self::unverified();
        $sourceKind = $kind === 'test' ? 'skill_test' : 'skill';
        $action = $kind === 'test' ? 'sand_ai.skill.test' : 'sand_ai.skill.run';
        $tuple = ['skill_id' => (int) $skill->id, 'skill_version_id' => (int) $version->id, 'skill_run_id' => (int) $run->id];
        foreach ($tuple as $field => $id) {
            if ((int) $invocation->getAttr($field) !== $id || (int) $dispatch->getAttr($field) !== $id) self::unverified();
        }
        foreach ($consumer->snapshot() as $field => $id) {
            if (in_array($field, ['organization_id', 'application_id', 'environment_id', 'workload_client_id'], true)
                && (int) $invocation->getAttr($field) !== $id) self::unverified();
        }
        $requestId = sprintf('skill:v1:%d:%d:%d:%d', $environment, (int) $skill->id, (int) $version->id, (int) $run->id);
        if ($invocation->request_id !== $requestId || (int) $dispatch->invocation_id !== $invocationId
            || (int) $dispatch->environment_id !== $environment
            || (int) $dispatch->application_id !== (int) $consumer->identity->applicationId
            || $dispatch->execution_scope !== 'skill' || $dispatch->source_kind !== $sourceKind
            || $dispatch->service_action !== $action) self::unverified();
        // An event is optional: ambiguous/missing metering uses the existing
        // dispatch cost projection. An existing event must have exact links.
        $event = UsageEvent::where('invocation_id', $invocationId)
            ->field('invocation_id,organization_id,application_id,environment_id,workload_client_id,skill_id,skill_version_id,skill_run_id,source_kind,service_action')->find();
        if ($event !== null) {
            foreach ($tuple + ['invocation_id' => $invocationId] as $field => $id) {
                if ((int) $event->getAttr($field) !== $id) self::unverified();
            }
            foreach (['organization_id', 'application_id', 'environment_id', 'workload_client_id'] as $field) {
                if ((int) $event->getAttr($field) !== (int) $invocation->getAttr($field)) self::unverified();
            }
            if ($event->source_kind !== $sourceKind || $event->service_action !== $action) self::unverified();
        }
        return ['invocation_id' => $invocationId, 'invocation_request_id' => $requestId,
            'run_id' => (int) $run->id, 'fingerprint' => $fingerprint];
    }

    private function fingerprint(SkillConsumerContext $consumer, SkillVersion $version, SkillRun $run, string $kind): string
    {
        try {
            if (!is_array($run->input)) self::unverified();
            $runData = $run->getData();
            $promptVersion = array_key_exists('prompt_contract_version', $runData) ? $runData['prompt_contract_version'] : 1;
            if (!in_array($promptVersion, [1, 2, '1', '2'], true)) self::unverified();
            if (!is_array($version->execution_snapshot)) self::unverified();
            $snapshot = DispatchRouteSnapshot::assert($version->execution_snapshot, true);
            $attribution = UsageAttribution::fromIdentity($consumer->identity,
                $kind === 'test' ? 'skill_test' : 'skill', $kind === 'test' ? 'sand_ai.skill.test' : 'sand_ai.skill.run');
            $payload = ['environment_id' => $consumer->identity->environmentId,
                'skill_id' => (int) $version->skill_id, 'skill_version_id' => (int) $version->id,
                'run_kind' => $kind, 'input' => $run->input, 'execution_snapshot' => $snapshot,
                'trusted_scope' => RequestIdempotencyKey::trustedScopeForAttribution($attribution),
                'consumer_context' => $consumer->snapshot()];
            if ((int) $promptVersion !== 1) $payload['prompt_contract_version'] = (int) $promptVersion;
            $stored = (string) $run->request_fingerprint;
            if (!hash_equals($stored, SkillOperationFingerprint::for('run', $payload))
                && !hash_equals($stored, SkillOperationFingerprint::for('run', $payload + ['message_role' => 'user']))) self::unverified();
            return $stored;
        } catch (ApiProblem) {
            self::unverified();
        }
    }

    private static function missing(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill fee record not found');
    }

    private static function unverified(): never
    {
        throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill fee ownership is unavailable');
    }
}
