<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Capability\EnvironmentCapabilityLock;
use app\Identity\Contract\EnvironmentReferenceVerifier;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\LegalAnalysisProfile;
use think\db\ConnectionInterface;
use think\facade\Db;

final class LegalAnalysisProfileGateway
{
    public function __construct(
        private readonly ?EnvironmentReferenceVerifier $environmentReferences = null,
        private readonly EnvironmentCapabilityLock $environmentLock = new EnvironmentCapabilityLock(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(int $environmentId): array
    {
        $this->requireEnvironment($environmentId);
        return LegalAnalysisProfile::where('environment_id', $environmentId)->whereNull('delete_time')
            ->order('code')->order('version', 'desc')->select()
            ->map(static fn (LegalAnalysisProfile $row): array => self::summary($row, false))->all();
    }

    /** @return array<string,mixed> */
    public function read(int $environmentId, string $code, string $version): array
    {
        $this->requireEnvironment($environmentId);
        $row = $this->find($environmentId, $code, $version);
        if ($row === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '法律分析配置不存在');
        }
        return self::summary($row, true);
    }

    /** @return list<array<string,mixed>> */
    public function templates(): array
    {
        $templates = config('sand_ai_legal.profiles', []);
        if (!is_array($templates)) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', '法律分析配置模板不可用');
        }
        $result = [];
        $registry = new LegalAnalysisProfileRegistry();
        foreach ($templates as $code => $template) {
            if (!is_string($code) || !is_array($template)) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_INVALID', '法律分析配置模板无效');
            }
            $result[] = $registry->validate(['code' => $code, ...$template]);
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveDraft(array $input, string $actorRef, string $requestKey = '', ?\Closure $permissionGuard = null): array
    {
        if (array_diff(array_keys($input), ['environment_id', 'expected_revision', 'profile']) !== []
            || count($input) !== 3 || !is_int($input['environment_id'] ?? null)
            || !array_key_exists('expected_revision', $input)
            || ($input['expected_revision'] !== null
                && (!is_int($input['expected_revision']) || $input['expected_revision'] < 1))
            || !is_array($input['profile'] ?? null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析配置草稿输入无效');
        }
        $environmentId = $input['environment_id'];
        $raw = $input['profile'];
        if (array_diff(array_keys($raw), ['code', 'version', 'name', 'output_language', 'max_prompt_characters', 'max_output_characters']) !== []
            || count($raw) !== 6 || !is_string($raw['name'] ?? null)
            || trim($raw['name']) === '' || mb_strlen($raw['name'], 'UTF-8') > 160) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析配置名称或字段无效');
        }
        $kinds = [
            'legal_research' => 'research_memo',
            'legal_research_planning' => 'research_plan',
            'judgment_remedy_planning' => 'research_plan',
            'judgment_remedy_analysis' => 'judgment_remedy_memo',
            'judgment_remedy_drafting' => 'remedy_document_draft',
            'litigation_preparation' => 'litigation_outline',
            'judgment_analysis' => 'judgment_analysis_memo',
            'complaint_drafting' => 'complaint_draft',
        ];
        $code = $raw['code'] ?? null;
        if (!is_string($code) || !isset($kinds[$code])) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析模式无效');
        }
        $config = (new LegalAnalysisProfileRegistry())->validate([
            'code' => $code,
            'version' => $raw['version'] ?? null,
            'draft_kind' => $kinds[$code],
            'output_language' => $raw['output_language'] ?? null,
            'max_prompt_characters' => $raw['max_prompt_characters'] ?? null,
            'max_output_characters' => $raw['max_output_characters'] ?? null,
        ]);
        $actorId = $this->actorId($actorRef);
        $this->requireEnvironment($environmentId);
        $guard = $this->operationGuard($environmentId, $permissionGuard);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($environmentId, $input, $raw, $config, $actorId, $requestKey, $guard): array {
            $this->environmentLock->acquire($connection, $environmentId);
            return (new LegalProfileOperationReceipt())->execute($connection, 'draft_save', $environmentId, $config['code'], $config['version'], $input['expected_revision'], $input, $actorId, $requestKey, $guard, function () use ($environmentId, $input, $raw, $config, $actorId): array {
                $row = $this->find($environmentId, $config['code'], $config['version'], true);
                if ($row === null) {
                    if ($input['expected_revision'] !== null) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置修订号已变化');
                    }
                    $row = LegalAnalysisProfile::create([
                        'environment_id' => $environmentId, 'code' => $config['code'],
                        'version' => $config['version'], 'name' => trim($raw['name']),
                        'revision' => 1, 'config' => $config, 'state' => 'draft',
                        'status' => 2, 'created_by' => $actorId, 'updated_by' => $actorId,
                    ]);
                } else {
                    if ((string) $row->state !== 'draft' || (int) $row->status !== 2
                        || (int) $row->revision !== $input['expected_revision']) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置已发布、撤销或修订号已变化');
                    }
                    if ($row->save([
                        'name' => trim($raw['name']), 'config' => $config,
                        'revision' => (int) $row->revision + 1, 'updated_by' => $actorId,
                    ]) !== true) {
                        throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置草稿未能保存');
                    }
                }
                $this->audit($environmentId, $actorId, 'legal_analysis_profile.draft', $row, '保存法律分析配置草稿');
                return self::summary($row, true);
            });
        });
    }

    /** @return array<string,mixed> */
    public function publish(int $environmentId, string $code, string $version, int $revision, string $actorRef, string $requestKey = '', ?\Closure $permissionGuard = null): array
    {
        if ($revision < 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '需要精确草稿修订号');
        }
        $actorId = $this->actorId($actorRef);
        $this->requireEnvironment($environmentId);
        $guard = $this->operationGuard($environmentId, $permissionGuard);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($environmentId, $code, $version, $revision, $actorId, $requestKey, $guard): array {
            $this->environmentLock->acquire($connection, $environmentId);
            return (new LegalProfileOperationReceipt())->execute($connection, 'publish', $environmentId, $code, $version, $revision,
                ['environment_id' => $environmentId, 'code' => $code, 'version' => $version, 'revision' => $revision], $actorId, $requestKey, $guard,
                function () use ($environmentId, $code, $version, $revision, $actorId): array {
                $row = $this->find($environmentId, $code, $version, true);
                if ($row === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '法律分析配置不存在');
                }
                if ((string) $row->state !== 'draft' || (int) $row->status !== 2 || (int) $row->revision !== $revision
                    || !is_array($row->config)) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置草稿修订号已变化或已发布');
                }
                (new LegalAnalysisProfileRegistry())->validate($row->config);
                if ($row->save([
                    'state' => 'published', 'status' => 1,
                    'published_time' => gmdate('Y-m-d H:i:s'), 'updated_by' => $actorId,
                ]) !== true) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置发布状态未能保存');
                }
                $this->audit($environmentId, $actorId, 'legal_analysis_profile.publish', $row, '发布法律分析配置');
                return self::summary($row, false);
            });
        });
    }

    /** @return array<string,mixed> */
    public function revoke(int $environmentId, string $code, string $version, string $actorRef, string $requestKey = '', ?\Closure $permissionGuard = null): array
    {
        $actorId = $this->actorId($actorRef);
        $this->requireEnvironment($environmentId);
        $guard = $this->operationGuard($environmentId, $permissionGuard);
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($environmentId, $code, $version, $actorId, $requestKey, $guard): array {
            $this->environmentLock->acquire($connection, $environmentId);
            return (new LegalProfileOperationReceipt())->execute($connection, 'revoke', $environmentId, $code, $version, null,
                ['environment_id' => $environmentId, 'code' => $code, 'version' => $version], $actorId, $requestKey, $guard,
                function () use ($environmentId, $code, $version, $actorId): array {
                $row = $this->find($environmentId, $code, $version, true);
                if ($row === null) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', '法律分析配置不存在');
                }
                if ((string) $row->state !== 'published' || (int) $row->status !== 1) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '仅能撤销已发布的法律分析配置');
                }
                if ($row->save([
                    'state' => 'revoked', 'status' => 2,
                    'revoked_time' => gmdate('Y-m-d H:i:s'), 'updated_by' => $actorId,
                ]) !== true) {
                    throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '法律分析配置撤销状态未能保存');
                }
                $this->audit($environmentId, $actorId, 'legal_analysis_profile.revoke', $row, '撤销法律分析配置');
                return self::summary($row, false);
            });
        });
    }

    public function readOperation(array $input, string $actorRef, \Closure $permissionGuard): array
    {
        $environmentId = filter_var($input['environment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($environmentId)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '需要原法律配置环境标识');
        $guard = $this->operationGuard($environmentId, $permissionGuard);
        return (new LegalProfileOperationReceipt())->read($input, $this->actorId($actorRef), $guard);
    }

    private function operationGuard(int $environmentId, ?\Closure $permissionGuard): \Closure
    {
        return function () use ($environmentId, $permissionGuard): void {
            $this->requireEnvironment($environmentId);
            if ($permissionGuard !== null) $permissionGuard();
        };
    }

    private function find(int $environmentId, string $code, string $version, bool $forUpdate = false): ?LegalAnalysisProfile
    {
        if ($environmentId < 1 || !in_array($code, ['legal_research', 'litigation_preparation', 'judgment_analysis', 'complaint_drafting', 'legal_research_planning', 'judgment_remedy_planning', 'judgment_remedy_analysis', 'judgment_remedy_drafting'], true)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $version) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '法律分析配置标识无效');
        }
        $query = LegalAnalysisProfile::where('environment_id', $environmentId)
            ->where('code', $code)->where('version', $version)->whereNull('delete_time');
        return ($forUpdate ? $query->lock(true) : $query)->find();
    }

    /** @return array<string,mixed> */
    private static function summary(LegalAnalysisProfile $row, bool $includeConfig): array
    {
        $result = [
            'id' => (int) $row->id, 'environment_id' => (int) $row->environment_id,
            'code' => (string) $row->code, 'version' => (string) $row->version,
            'name' => (string) $row->name, 'revision' => (int) $row->revision,
            'state' => (string) $row->state, 'status' => (int) $row->status,
        ];
        if ($includeConfig) {
            $result['config'] = $row->config;
        }
        return $result;
    }

    private function audit(int $environmentId, int $actorId, string $action, LegalAnalysisProfile $row, string $summary): void
    {
        AuditLog::create([
            'environment_id' => $environmentId, 'actor_type' => 'admin',
            'actor_ref' => (string) $actorId, 'action' => $action,
            'resource_type' => 'legal_analysis_profile', 'resource_id' => (int) $row->id,
            'summary' => $summary, 'context' => [
                'code' => (string) $row->code, 'version' => (string) $row->version,
                'revision' => (int) $row->revision,
            ],
        ]);
    }

    private function requireEnvironment(int $environmentId): void
    {
        try {
            ($this->environmentReferences ?? new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId);
        } catch (IdentityContextException $exception) {
            throw IdentityContextProblem::from($exception);
        }
    }

    private function actorId(string $actorRef): int
    {
        if (!ctype_digit($actorRef) || (int) $actorRef < 1) {
            throw new ApiProblem('SAND_AI_AUTHENTICATION_FAILED', '需要已登录的后台管理员');
        }
        return (int) $actorRef;
    }
}
