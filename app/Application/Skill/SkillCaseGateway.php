<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\Skill;
use app\Infrastructure\Persistence\Model\SkillCase;
use app\Infrastructure\Persistence\Model\SkillCaseEvaluation;
use app\Infrastructure\Persistence\Model\SkillRun;
use app\Infrastructure\Persistence\Model\SkillVersion;
use think\facade\Db;

/** Human-reviewed examples for one immutable Skill draft version. */
final class SkillCaseGateway
{
    /** @return array{skill_version_id:int,cases:list<array<string,mixed>>} */
    public function list(int $environmentId, int $versionId, ?SkillConsumerContext $consumer = null): array
    {
        $this->requireEnvironment($environmentId);
        $version = SkillVersion::where('id', $versionId)->find();
        if ($version === null || Skill::where('id', (int) $version->skill_id)
            ->where('environment_id', $environmentId)->whereNull('delete_time')->find() === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill version not found');
        }
        $this->skill($environmentId, (int) $version->skill_id, $consumer);
        $rows = Db::connect('pgsql')->query(
            'SELECT c.id,c.skill_version_id,c.case_no,c.name,c.input,c.expected_criteria,c.state,
                    c.retire_reason,e.id evaluation_id,e.skill_run_id,e.verdict,e.note,e.create_time evaluation_time
             FROM sand_ai_skill_case c
             LEFT JOIN LATERAL (
                SELECT id,skill_run_id,verdict,note,create_time
                FROM sand_ai_skill_case_evaluation
                WHERE case_id=c.id ORDER BY id DESC LIMIT 1
             ) e ON true
             WHERE c.skill_version_id=? ORDER BY c.case_no',
            [$versionId],
        );
        $cases = array_map(static function (array $row): array {
            $input = is_string($row['input']) ? json_decode($row['input'], true, 64, JSON_THROW_ON_ERROR) : $row['input'];
            return [
                'id' => (int) $row['id'],
                'skill_version_id' => (int) $row['skill_version_id'],
                'case_no' => (int) $row['case_no'],
                'name' => (string) $row['name'],
                'input' => $input,
                'expected_criteria' => (string) $row['expected_criteria'],
                'state' => (string) $row['state'],
                'retire_reason' => $row['retire_reason'] === null ? null : (string) $row['retire_reason'],
                'latest_evaluation' => $row['evaluation_id'] === null ? null : [
                    'id' => (int) $row['evaluation_id'],
                    'skill_run_id' => (int) $row['skill_run_id'],
                    'verdict' => (string) $row['verdict'],
                    'note' => (string) $row['note'],
                    'create_time' => (string) $row['evaluation_time'],
                ],
            ];
        }, $rows);
        return ['skill_version_id' => $versionId, 'cases' => $cases];
    }

    /** @param array<string,mixed> $input @return array{case_id:int,case_no:int,idempotent_replay:bool} */
    public function create(int $environmentId, int $skillId, int $versionId, string $name, array $input, string $criteria, string $requestId, string $actor, ?SkillConsumerContext $consumer = null): array
    {
        $this->requireEnvironment($environmentId);
        $name = trim($name);
        $criteria = trim($criteria);
        $this->request($requestId, $actor);
        if ($skillId < 1 || $versionId < 1 || $name === '' || mb_strlen($name, 'UTF-8') > 100
            || $criteria === '' || mb_strlen($criteria, 'UTF-8') > 1000
            || array_is_list($input) || strlen(SkillOperationFingerprint::canonicalJson($input)) > 32768) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill case is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $skillId, $versionId, $name, $input, $criteria, $requestId, $actor, $consumer,
        ): array {
            $skill = $this->skill($environmentId, $skillId, $consumer);
            $version = SkillVersion::where('id', $versionId)->where('skill_id', (int) $skill->id)
                ->lock(true)->find();
            if ($version === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill version not found');
            }
            $existing = SkillCase::where('skill_version_id', (int) $version->id)
                ->where('create_request_id', $requestId)->find();
            if ($existing !== null) {
                $this->assertReplayContext($existing->consumer_context, $consumer);
                if ((string) $existing->name !== $name
                    || (string) $existing->expected_criteria !== $criteria
                    || SkillOperationFingerprint::canonicalJson((array) $existing->input)
                        !== SkillOperationFingerprint::canonicalJson($input)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Skill case request changed');
                }
                return ['case_id' => (int) $existing->id, 'case_no' => (int) $existing->case_no, 'idempotent_replay' => true];
            }
            if ((string) $version->state !== 'draft') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill version is not a draft');
            }
            SkillSchemaPolicy::assertValue((array) $version->input_schema, $input);
            $caseNo = (int) SkillCase::where('skill_version_id', (int) $version->id)->max('case_no') + 1;
            if ($caseNo > 100) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill case limit reached');
            }
            $case = SkillCase::create([
                'skill_version_id' => (int) $version->id,
                'consumer_context' => $consumer?->snapshot(),
                'case_no' => $caseNo,
                'name' => $name,
                'input' => $input,
                'expected_criteria' => $criteria,
                'state' => 'active',
                'create_request_id' => $requestId,
                'created_by' => $actor,
            ]);
            $this->audit($actor, 'sand_ai.skill.case.create', (int) $case->id, ['version_id' => (int) $version->id], $consumer);
            return ['case_id' => (int) $case->id, 'case_no' => $caseNo, 'idempotent_replay' => false];
        });
    }

    /** Original administrator case receipt, including a historical review rather than the latest verdict. */
    public function readAdminReceipt(int $environmentId, string $operation, string $key, string $actor, ?int $versionId = null, ?int $caseId = null): array
    {
        $this->requireEnvironment($environmentId);
        $this->request($key, $actor);
        if (!in_array($operation, ['create', 'review', 'retire'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Administrator case receipt operation is unsupported');
        }
        $case = $operation === 'create'
            ? SkillCase::where('skill_version_id', $versionId)->where('create_request_id', $key)->find()
            : SkillCase::where('id', $caseId)->find();
        if ($case === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original case receipt is unavailable; retain its request key');
        $version = SkillVersion::where('id', (int) $case->skill_version_id)->find();
        if ($version === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original case version is unavailable');
        $skill = $this->skill($environmentId, (int) $version->skill_id, null);
        $this->assertReplayContext($case->consumer_context, null);
        if ($operation === 'create') {
            if ((string) $case->created_by !== $actor) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original case is unavailable to this administrator');
            $result = ['case_id' => (int) $case->id, 'case_no' => (int) $case->case_no];
        } elseif ($operation === 'review') {
            $evaluation = SkillCaseEvaluation::where('case_id', (int) $case->id)->where('request_id', $key)->find();
            if ($evaluation === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original review receipt is unavailable');
            $this->assertReplayContext($evaluation->consumer_context, null);
            if ((string) $evaluation->reviewer_ref !== $actor) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original review is unavailable to this administrator');
            $run = SkillRun::where('id', (int) $evaluation->skill_run_id)->where('environment_id', $environmentId)
                ->where('skill_id', (int) $skill->id)->where('skill_version_id', (int) $version->id)
                ->where('run_kind', 'test')->where('state', 'succeeded')->find();
            if ($run === null || !in_array((string) $evaluation->verdict, ['pass', 'fail'], true)) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original reviewed test proof is unavailable');
            }
            $result = ['case_id' => (int) $case->id, 'evaluation_id' => (int) $evaluation->id,
                'skill_run_id' => (int) $evaluation->skill_run_id, 'verdict' => (string) $evaluation->verdict];
        } else {
            if ((string) $case->retire_request_id !== $key) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original retirement receipt is unavailable');
            if ((string) $case->retired_by !== $actor) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original retirement is unavailable to this administrator');
            if ((string) $case->state !== 'retired' || trim((string) $case->retire_reason) === '') {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original retirement result is unavailable');
            }
            $result = ['case_id' => (int) $case->id, 'state' => 'retired'];
        }
        return $result + ['operation' => $operation, 'request_id' => $key, 'environment_id' => $environmentId,
            'actor_ref' => $actor, 'skill_id' => (int) $skill->id, 'version_id' => (int) $version->id,
            'code' => (string) $skill->code, 'version' => (int) $version->version_no, 'idempotent_replay' => true];
    }

    /** Read one immutable, committed case using its original consumer request. */
    public function readCreatedReceipt(int $environmentId, string $code, int $versionNo, string $requestId, SkillConsumerContext $consumer, callable $authorizeResource): array
    {
        $this->requireEnvironment($environmentId);
        $this->request($requestId, $consumer->auditRef());
        $skill = Skill::where('environment_id', $environmentId)->where('code', $code)->whereNull('delete_time')->find();
        if ($skill === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
        SkillConsumerContext::assertAccess($skill, $consumer);
        $version = SkillVersion::where('skill_id', (int) $skill->id)->where('version_no', $versionNo)->find();
        if ($version === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original Skill version not found');
        $case = SkillCase::where('skill_version_id', (int) $version->id)->where('create_request_id', $requestId)->find();
        if ($case === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original case receipt is not available; retain the original request key');
        $this->assertReplayContext($case->consumer_context, $consumer);
        $authorizeResource((int) $skill->id, (int) $version->id);
        $storedHash = hash('sha256', SkillOperationFingerprint::canonicalJson([$code, $versionNo,
            (string) $case->name, (array) $case->input, (string) $case->expected_criteria]));
        return ['case_id' => (int) $case->id, 'case_no' => (int) $case->case_no,
            'skill_id' => (int) $skill->id, 'version_id' => (int) $version->id, 'code' => $code,
            'version' => $versionNo, 'request_fingerprint' => $storedHash, 'idempotent_replay' => true];
    }

    /** Read a committed review or retirement without changing the case. */
    public function readOperationReceipt(int $environmentId, string $code, int $versionNo, int $caseId, string $operation, string $requestId, SkillConsumerContext $consumer, callable $authorizeResource, ?string $inputHash = null): array
    {
        $this->requireEnvironment($environmentId);
        $this->request($requestId, $consumer->auditRef());
        if (!in_array($operation, ['review', 'retire'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill case receipt operation is unsupported');
        }
        $skill = Skill::where('environment_id', $environmentId)->where('code', $code)->whereNull('delete_time')->find();
        if ($skill === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
        SkillConsumerContext::assertAccess($skill, $consumer);
        $version = SkillVersion::where('skill_id', (int) $skill->id)->where('version_no', $versionNo)->find();
        $case = $version === null ? null : SkillCase::where('id', $caseId)->where('skill_version_id', (int) $version->id)->find();
        if ($case === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original Skill case is unavailable');
        $authorizeResource((int) $skill->id, (int) $version->id);
        $body = ['scope' => ['type' => $consumer->scopeType, 'ref' => $consumer->scopeRef], 'actor_ref' => $consumer->actorRef];
        if ($operation === 'review') {
            $evaluation = SkillCaseEvaluation::where('case_id', $caseId)->where('request_id', $requestId)->find();
            if ($evaluation === null) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original review receipt is unavailable; retain its request key');
            $this->assertReplayContext($evaluation->consumer_context, $consumer);
            if ((string) $evaluation->reviewer_ref !== $consumer->auditRef()
                || !in_array((string) $evaluation->verdict, ['pass', 'fail'], true) || (int) $evaluation->skill_run_id < 1) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original review actor or target is unavailable');
            }
            $run = SkillRun::where('id', (int) $evaluation->skill_run_id)->where('environment_id', $environmentId)
                ->where('skill_id', (int) $skill->id)->where('skill_version_id', (int) $version->id)
                ->where('run_kind', 'test')->where('state', 'succeeded')->find();
            if ($run === null) throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original reviewed test is unavailable');
            $body += ['skill_run_id' => (int) $evaluation->skill_run_id,
                'verdict' => (string) $evaluation->verdict, 'note' => (string) $evaluation->note];
            $result = ['evaluation_id' => (int) $evaluation->id, 'case_id' => $caseId,
                'skill_run_id' => (int) $evaluation->skill_run_id, 'verdict' => (string) $evaluation->verdict];
        } else {
            if ((string) $case->retire_request_id !== $requestId) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original retirement receipt is unavailable; retain its request key');
            }
            if ((string) $case->state !== 'retired' || (string) $case->retired_by !== $consumer->auditRef()
                || trim((string) $case->retire_reason) === '') {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Original retirement actor or result is unavailable');
            }
            $body += ['reason' => (string) $case->retire_reason];
            $result = ['case_id' => $caseId, 'state' => 'retired'];
        }
        $hash = hash('sha256', SkillOperationFingerprint::canonicalJson($body));
        if ($inputHash !== null && !hash_equals($hash, $inputHash)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original case operation input changed');
        }
        return $result + ['operation' => $operation, 'input_sha256' => $hash, 'skill_id' => (int) $skill->id,
            'version_id' => (int) $version->id, 'code' => $code, 'version' => $versionNo, 'idempotent_replay' => true];
    }

    /** @return array{evaluation_id:int,case_id:int,verdict:string,idempotent_replay:bool} */
    public function review(int $environmentId, int $caseId, int $runId, string $verdict, string $note, string $requestId, string $actor, ?SkillConsumerContext $consumer = null): array
    {
        $this->requireEnvironment($environmentId);
        $this->request($requestId, $actor);
        $note = trim($note);
        if ($caseId < 1 || $runId < 1 || !in_array($verdict, ['pass', 'fail'], true)
            || mb_strlen($note, 'UTF-8') > 1000) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill case review is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use (
            $environmentId, $caseId, $runId, $verdict, $note, $requestId, $actor, $consumer,
        ): array {
            [$version, $case] = $this->lockedCase($environmentId, $caseId, $consumer);
            $existing = SkillCaseEvaluation::where('case_id', $caseId)->where('request_id', $requestId)->find();
            if ($existing !== null) {
                $this->assertReplayContext($existing->consumer_context, $consumer);
                if ((int) $existing->skill_run_id !== $runId || (string) $existing->verdict !== $verdict
                    || (string) $existing->note !== $note) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Skill case review request changed');
                }
                return ['evaluation_id' => (int) $existing->id, 'case_id' => $caseId, 'verdict' => $verdict, 'idempotent_replay' => true];
            }
            if ((string) $version->state !== 'draft' || (string) $case->state !== 'active') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill case is no longer reviewable');
            }
            $run = SkillRun::where('id', $runId)->where('environment_id', $environmentId)
                ->where('skill_version_id', (int) $version->id)->where('run_kind', 'test')
                ->where('state', 'succeeded')->lock(true)->find();
            if ($consumer !== null && $run !== null) {
                $snapshot = $run->consumer_context;
                if (!is_array($snapshot) || ($snapshot['scope']['type'] ?? null) !== $consumer->scopeType
                    || ($snapshot['scope']['ref'] ?? null) !== $consumer->scopeRef
                    || ($snapshot['organization_id'] ?? null) !== (int) $consumer->identity->organizationId
                    || ($snapshot['application_id'] ?? null) !== (int) $consumer->identity->applicationId) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill test is unavailable in this consumer scope');
                }
            }
            if ($run === null || (int) $run->skill_id !== (int) $version->skill_id
                || (string) $run->create_time < (string) $case->create_time
                || SkillOperationFingerprint::canonicalJson((array) $run->input)
                !== SkillOperationFingerprint::canonicalJson((array) $case->input)) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill case requires its matching successful test');
            }
            $evaluation = SkillCaseEvaluation::create([
                'case_id' => $caseId,
                'skill_run_id' => $runId,
                'verdict' => $verdict,
                'note' => $note,
                'reviewer_ref' => $actor,
                'consumer_context' => $consumer?->snapshot(),
                'request_id' => $requestId,
            ]);
            $this->audit($actor, 'sand_ai.skill.case.review', $caseId, ['evaluation_id' => (int) $evaluation->id, 'verdict' => $verdict], $consumer);
            return ['evaluation_id' => (int) $evaluation->id, 'case_id' => $caseId, 'verdict' => $verdict, 'idempotent_replay' => false];
        });
    }

    /** @return array{case_id:int,state:string,idempotent_replay:bool} */
    public function retire(int $environmentId, int $caseId, string $reason, string $requestId, string $actor, ?SkillConsumerContext $consumer = null): array
    {
        $this->requireEnvironment($environmentId);
        $this->request($requestId, $actor);
        $reason = trim($reason);
        if ($caseId < 1 || $reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill case retirement is invalid');
        }
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $caseId, $reason, $requestId, $actor, $consumer): array {
            [$version, $case] = $this->lockedCase($environmentId, $caseId, $consumer);
            if ((string) $case->state === 'retired') {
                if ((string) $case->retire_request_id !== $requestId || (string) $case->retire_reason !== $reason) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Skill case was retired by another request');
                }
                return ['case_id' => $caseId, 'state' => 'retired', 'idempotent_replay' => true];
            }
            if ((string) $version->state !== 'draft') {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Published Skill cases cannot be retired');
            }
            $updated = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_skill_case SET state='retired',retired_by=?,retire_reason=?,retire_request_id=?,
                        retire_time=CURRENT_TIMESTAMP(0) WHERE id=? AND state='active'",
                [$actor, $reason, $requestId, $caseId],
            );
            if ($updated !== 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Skill case changed');
            }
            $this->audit($actor, 'sand_ai.skill.case.retire', $caseId, ['version_id' => (int) $version->id], $consumer);
            return ['case_id' => $caseId, 'state' => 'retired', 'idempotent_replay' => false];
        });
    }

    /** @return array{SkillVersion,SkillCase} */
    private function lockedCase(int $environmentId, int $caseId, ?SkillConsumerContext $consumer): array
    {
        $candidate = SkillCase::where('id', $caseId)->find();
        if ($candidate === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill case not found');
        }
        $version = SkillVersion::where('id', (int) $candidate->skill_version_id)->find();
        if ($version === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill version not found');
        }
        $this->skill($environmentId, (int) $version->skill_id, $consumer);
        $version = SkillVersion::where('id', (int) $version->id)->lock(true)->find();
        $case = SkillCase::where('id', $caseId)->lock(true)->find();
        if ($version === null || $case === null || (int) $case->skill_version_id !== (int) $version->id) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill case not found');
        }
        return [$version, $case];
    }

    private function skill(int $environmentId, int $skillId, ?SkillConsumerContext $consumer): Skill
    {
        $skill = Skill::where('id', $skillId)->where('environment_id', $environmentId)
            ->whereNull('delete_time')->lock(true)->find();
        if ($skill === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill not found');
        }
        SkillConsumerContext::assertAccess($skill, $consumer);
        return $skill;
    }

    private function assertReplayContext(mixed $snapshot, ?SkillConsumerContext $consumer): void
    {
        if ($consumer !== null) {
            $consumer->assertRun($snapshot);
        } elseif ($snapshot !== null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Consumer Skill case is unavailable to legacy management');
        }
    }

    private function requireEnvironment(int $environmentId): void
    {
        if ($environmentId < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill environment is invalid');
        }
        try {
            (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function request(string $requestId, string $actor): void
    {
        if ($requestId === '' || strlen($requestId) > 128 || $actor === '' || strlen($actor) > 64) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill case request is invalid');
        }
    }

    /** @param array<string,mixed> $context */
    private function audit(string $actor, string $action, int $caseId, array $context, ?SkillConsumerContext $consumer = null): void
    {
        AuditLog::create([
            'actor_type' => $consumer === null ? 'admin' : 'api_credential',
            'actor_ref' => $actor,
            'action' => $action,
            'resource_type' => 'skill_case',
            'resource_id' => $caseId,
            'summary' => 'SandAI Skill case changed',
            'context' => $context + ($consumer === null ? [] : ['consumer_context' => $consumer->snapshot()]),
        ]);
    }
}
