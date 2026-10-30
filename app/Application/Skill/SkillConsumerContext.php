<?php

declare(strict_types=1);

namespace app\Application\Skill;

use app\Api\Support\ApiProblem;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\Skill;
use think\facade\Db;

/** Application-declared content ownership; never a verified human identity. */
final readonly class SkillConsumerContext
{
    private function __construct(
        public IdentityContext $identity,
        public string $scopeType,
        public string $scopeRef,
        public string $actorRef,
    ) {
    }

    public static function requireSchema(): void
    {
        $rows = Db::connect('pgsql')->query("SELECT count(*) total FROM information_schema.columns
            WHERE table_schema=current_schema() AND ((table_name='sand_ai_skill' AND column_name IN
                ('scope_type','scope_ref','scope_source','organization_id','application_id','created_workload_client_id','created_actor_ref'))
                OR (table_name IN ('sand_ai_skill_version','sand_ai_skill_run','sand_ai_skill_operation','sand_ai_skill_case','sand_ai_skill_case_evaluation') AND column_name='consumer_context')
                OR (table_name='sand_ai_skill_version' AND column_name='published_consumer_context'))");
        if ((int) ($rows[0]['total'] ?? 0) !== 13) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'Consumer Skill schema is not installed');
        }
    }

    public static function fromRequest(IdentityContext $identity, mixed $scope, mixed $actor): self
    {
        if (!is_array($scope) || array_diff(array_keys($scope), ['type', 'ref']) !== []
            || !in_array($scope['type'] ?? null, ['personal', 'team', 'organization'], true)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill scope is required');
        }
        $reference = self::reference($scope['ref'] ?? null);
        if ($scope['type'] === 'organization' && !hash_equals($identity->organizationId, $reference)) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill organization scope is unavailable');
        }
        foreach ([$identity->organizationId, $identity->applicationId, $identity->workloadClientId] as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new ApiProblem('SAND_AI_INVOCATION_FACTS_UNVERIFIED', 'Skill consumer identity is incomplete');
            }
        }
        return new self($identity, $scope['type'], $reference, self::reference($actor));
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 128
            || preg_match('//u', $value) !== 1 || preg_match('/[\p{C}]/u', $value) === 1
            || preg_match('/^\s*$/u', $value) === 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill scope and actor references must be nonempty bounded text');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'organization_id' => (int) $this->identity->organizationId,
            'application_id' => (int) $this->identity->applicationId,
            'environment_id' => $this->identity->environmentId,
            'workload_client_id' => (int) $this->identity->workloadClientId,
            'scope' => ['type' => $this->scopeType, 'ref' => $this->scopeRef],
            'scope_source' => 'consumer_declared',
            'actor_ref' => $this->actorRef,
            'actor_source' => 'consumer_declared',
        ];
    }

    /** @return array<string,int|string> */
    public function skillFields(): array
    {
        return [
            'organization_id' => (int) $this->identity->organizationId,
            'application_id' => (int) $this->identity->applicationId,
            'created_workload_client_id' => (int) $this->identity->workloadClientId,
            'scope_type' => $this->scopeType,
            'scope_ref' => $this->scopeRef,
            'scope_source' => 'consumer_declared',
            'created_actor_ref' => $this->actorRef,
        ];
    }

    public static function assertAccess(Skill $skill, ?self $consumer): void
    {
        $type = $skill->scope_type ?? 'legacy';
        if ($consumer === null) {
            if ($type !== 'legacy') self::unavailable();
            return;
        }
        if ($type !== $consumer->scopeType || $skill->scope_ref !== $consumer->scopeRef
            || $skill->scope_source !== 'consumer_declared'
            || (int) $skill->organization_id !== (int) $consumer->identity->organizationId
            || (int) $skill->application_id !== (int) $consumer->identity->applicationId
            || (int) $skill->environment_id !== $consumer->identity->environmentId) {
            self::unavailable();
        }
    }

    public function assertRun(mixed $snapshot): void
    {
        if (!is_array($snapshot)
            || SkillOperationFingerprint::canonicalJson($snapshot)
                !== SkillOperationFingerprint::canonicalJson($this->snapshot())) {
            self::unavailable();
        }
    }

    public function key(string $requestId): string
    {
        if ($requestId === '' || strlen($requestId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $requestId) === 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Skill request key is invalid');
        }
        return hash('sha256', 'skill-consumer-v1:' . SkillOperationFingerprint::canonicalJson([
            'consumer' => $this->snapshot(), 'request_id' => $requestId,
        ]));
    }

    public function auditRef(): string
    {
        return hash('sha256', SkillOperationFingerprint::canonicalJson($this->snapshot()));
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Skill is unavailable in this consumer scope');
    }
}
