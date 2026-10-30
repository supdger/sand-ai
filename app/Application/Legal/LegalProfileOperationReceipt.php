<?php

declare(strict_types=1);

namespace app\Application\Legal;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\DeploymentDraftSaveRequestPolicy;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Original legal profile operations retain only a safe snapshot and internal keyed proof. */
final class LegalProfileOperationReceipt
{
    private const VERSION = 'legal_profile_operation@1';
    private const ACTIONS = ['draft_save' => 'legal_analysis_profile.draft', 'publish' => 'legal_analysis_profile.publish', 'revoke' => 'legal_analysis_profile.revoke'];

    public function execute(ConnectionInterface $connection, string $operation, int $environment, string $code, string $version, ?int $expected, array $body, int $actor, string $key, \Closure $guard, \Closure $write): array
    {
        $guard();
        if ($key === '') return $write();
        $this->reference($operation, $environment, $code, $version, $expected, $actor, $key);
        $policy = new DeploymentDraftSaveRequestPolicy();
        $scope = $this->scope($operation, $environment, $actor);
        $lock = $policy->advisoryLockFingerprint($key, $scope);
        $connection->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sand_ai:legal_profile_operation:' . $lock]);
        $row = $this->original($connection, $operation, $environment, $actor, $lock);
        if ($row !== null) {
            $context = $this->context($row, $operation, $environment, $actor, $lock);
            $this->target($context, $code, $version, $expected);
            $proof = $policy->fingerprintForKeyId($body, $key, $scope, $context['key_id']);
            if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])
                || !hash_equals($context['request_fingerprint_hmac'], $proof['request_fingerprint_hmac'])) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', '原法律配置请求号对应不同输入');
            }
            $receipt = $this->snapshot($context, $row, $operation);
            $guard();
            return $receipt;
        }
        $proof = $policy->fingerprint($body, $key, $scope);
        $receipt = $write();
        $context = ['version' => self::VERSION, 'code' => $code, 'profile_version' => $version,
            'expected_revision' => $expected, 'lock_sha256' => $lock, 'receipt' => $receipt] + $proof;
        $this->snapshot($context, ['resource_id' => $receipt['id'] ?? null, 'environment_id' => $environment], $operation);
        $connection->query('INSERT INTO sand_ai_audit_log (environment_id,actor_type,actor_ref,action,resource_type,resource_id,summary,context) VALUES (?,?,?,?,?,?,?,CAST(? AS jsonb)) RETURNING id', [
            $environment, 'admin', (string) $actor, self::ACTIONS[$operation], 'legal_analysis_profile', $receipt['id'],
            '保留法律配置原操作回执', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $guard();
        return $receipt;
    }

    public function read(array $input, int $actor, \Closure $guard): array
    {
        if (array_diff(array_keys($input), ['operation','environment_id','code','version','request_key','expected_revision']) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '原法律配置查询字段无效');
        $operation = is_string($input['operation'] ?? null) ? $input['operation'] : '';
        $environment = $this->id($input['environment_id'] ?? null);
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        $version = is_string($input['version'] ?? null) ? $input['version'] : '';
        $key = is_string($input['request_key'] ?? null) ? $input['request_key'] : '';
        $expected = array_key_exists('expected_revision', $input) ? $this->id($input['expected_revision']) : null;
        $this->reference($operation, $environment, $code, $version, $expected, $actor, $key);
        $guard();
        $policy = new DeploymentDraftSaveRequestPolicy();
        $scope = $this->scope($operation, $environment, $actor);
        $lock = $policy->advisoryLockFingerprint($key, $scope);
        $row = $this->original(Db::connect('pgsql'), $operation, $environment, $actor, $lock);
        $result = ['operation' => $operation, 'actor_id' => null, 'environment_id' => $environment,
            'code' => $code, 'version' => $version, 'request_key' => $key, 'expected_revision' => $expected,
            'state' => 'unknown', 'receipt' => null];
        if ($row !== null) {
            $context = $this->context($row, $operation, $environment, $actor, $lock);
            $this->target($context, $code, $version, $expected);
            $proof = $policy->idempotencyForKeyId($key, $scope, $context['key_id']);
            if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', '原法律配置请求号尚未证实');
            $result['receipt'] = $this->snapshot($context, $row, $operation);
            $result['actor_id'] = $actor; $result['state'] = 'completed';
        }
        $guard();
        return $result;
    }

    private function original(ConnectionInterface $connection, string $operation, int $environment, int $actor, string $lock): ?array
    {
        $rows = $connection->query("SELECT environment_id,actor_type,actor_ref,action,resource_type,resource_id,context FROM sand_ai_audit_log WHERE actor_type='admin' AND actor_ref=? AND environment_id=? AND action=? AND resource_type='legal_analysis_profile' AND context->>'version'=? AND context->>'lock_sha256'=? ORDER BY id LIMIT 2", [(string) $actor, $environment, self::ACTIONS[$operation], self::VERSION, $lock]);
        if (count($rows) > 1) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', '原法律配置回执不唯一');
        return $rows[0] ?? null;
    }

    private function context(array $row, string $operation, int $environment, int $actor, string $lock): array
    {
        $context = $row['context'] ?? null;
        if (is_string($context)) $context = json_decode($context, true, 512, JSON_THROW_ON_ERROR);
        if (($row['actor_type'] ?? null) !== 'admin' || (string) ($row['actor_ref'] ?? '') !== (string) $actor
            || (int) ($row['environment_id'] ?? 0) !== $environment || ($row['action'] ?? null) !== self::ACTIONS[$operation]
            || ($row['resource_type'] ?? null) !== 'legal_analysis_profile' || !is_array($context)
            || ($context['version'] ?? null) !== self::VERSION || ($context['lock_sha256'] ?? null) !== $lock
            || !is_string($context['key_id'] ?? null) || ($context['key_version'] ?? null) !== (new DeploymentDraftSaveRequestPolicy())->keyVersion()
            || !is_string($context['idempotency_hmac'] ?? null) || !is_string($context['request_fingerprint_hmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $context['idempotency_hmac']) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $context['request_fingerprint_hmac']) !== 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', '原法律配置回执证明不可用');
        }
        return $context;
    }

    private function target(array $context, string $code, string $version, ?int $expected): void
    {
        if (($context['code'] ?? null) !== $code || ($context['profile_version'] ?? null) !== $version
            || !array_key_exists('expected_revision', $context) || $context['expected_revision'] !== $expected) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', '原法律配置请求目标不同');
        }
    }

    private function snapshot(array $context, array $row, string $operation): array
    {
        $value = $context['receipt'] ?? null;
        $state = ['draft_save' => 'draft', 'publish' => 'published', 'revoke' => 'revoked'][$operation];
        if (!is_array($value) || !is_int($value['id'] ?? null) || $value['id'] < 1 || (int) ($row['resource_id'] ?? 0) !== $value['id']
            || ($value['environment_id'] ?? null) !== (int) ($row['environment_id'] ?? 0) || ($value['code'] ?? null) !== ($context['code'] ?? null)
            || ($value['version'] ?? null) !== ($context['profile_version'] ?? null) || !is_string($value['name'] ?? null)
            || !is_int($value['revision'] ?? null) || $value['revision'] < 1 || ($value['state'] ?? null) !== $state
            || ($value['status'] ?? null) !== ($operation === 'publish' ? 1 : 2)
            || ($operation === 'draft_save' && $value['revision'] !== ($context['expected_revision'] ?? 0) + 1)
            || ($operation === 'publish' && $value['revision'] !== ($context['expected_revision'] ?? null))) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '原法律配置安全回执无效');
        }
        $safe = array_intersect_key($value, array_flip(['id','environment_id','code','version','name','revision','state','status']));
        if ($operation === 'draft_save') {
            if (!is_array($value['config'] ?? null)) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', '原法律配置内容不可用');
            $safe['config'] = (new LegalAnalysisProfileRegistry())->validate($value['config']);
        }
        return $safe;
    }

    private function reference(string $operation, int $environment, string $code, string $version, ?int $expected, int $actor, string $key): void
    {
        if (!isset(self::ACTIONS[$operation]) || $environment < 1 || $actor < 1
            || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code) !== 1 || strlen($version) > 32 || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key) !== 1 || ($expected !== null && $expected < 1)
            || ($operation === 'publish' && $expected === null) || ($operation === 'revoke' && $expected !== null)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '原法律配置操作标识无效');
    }

    private function scope(string $operation, int $environment, int $actor): string { return 'legal-profile:' . $operation . ':environment:' . $environment . ':admin:' . $actor; }
    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', '原法律配置环境或修订无效');
        return $id;
    }
}
