<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** Keyed catalogue writes retain only their safe original receipt in SandAI audit storage. */
final class CatalogOperationReceipt
{
    private const VERSION = 'catalog_operation@1';

    public function execute(string $resource, string $operation, array $input, int $actor, string $key, \Closure $guard, \Closure $write): array
    {
        $this->assertReference($resource, $operation, $actor, $key);
        $policy = new DeploymentDraftSaveRequestPolicy();
        $actorScope = $this->actorScope($resource, $operation, $actor);
        $lock = $policy->advisoryLockFingerprint($key, $actorScope);
        return Db::connect('pgsql')->transaction(function () use ($resource, $operation, $input, $actor, $key, $guard, $write, $policy, $actorScope, $lock): array {
            $guard();
            Db::connect('pgsql')->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sand_ai:catalog_operation:' . $lock]);
            $row = $this->original($resource, $operation, $actor, $lock);
            if ($row !== null) {
                $context = $this->context($row);
                $proof = $policy->fingerprintForKeyId(['resource'=>$resource,'operation'=>$operation,'input'=>$input], $key, $actorScope, $context['key_id']);
                if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])
                    || !hash_equals($context['request_fingerprint_hmac'], $proof['request_fingerprint_hmac'])) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original catalogue request key belongs to different input');
                }
                $receipt = $this->snapshot($context, $resource, $operation);
                if ((int) $row['resource_id'] !== $receipt['id']) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original catalogue receipt attribution differs');
                $guard();
                return $receipt;
            }
            // The existing server keyring signs the original input in memory, including
            // credentials. Only the keyed proof and safe receipt enter persistent audit.
            $proof = $policy->fingerprint(['resource'=>$resource,'operation'=>$operation,'input'=>$input], $key, $actorScope);
            $receipt = $this->safe($resource, $write());
            $target = $operation === 'update' ? $this->positiveId($input['id'] ?? null) : null;
            if ($target !== null && $receipt['id'] !== $target) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Catalogue receipt target changed');
            $context = ['version'=>self::VERSION,'lock_sha256'=>$lock,'target_id'=>$target,'code'=>$receipt['code'],'receipt'=>$receipt] + $proof;
            Db::connect('pgsql')->query('INSERT INTO sand_ai_audit_log (actor_type,actor_ref,action,resource_type,resource_id,summary,context) VALUES (?,?,?,?,?,?,CAST(? AS jsonb)) RETURNING id', [
                'admin', (string) $actor, 'catalog.' . $resource . '.' . $operation, $resource, $receipt['id'],
                'Saved catalogue operation receipt', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $guard();
            return $receipt;
        });
    }

    public function read(string $resource, array $input, int $actor, \Closure $guard): array
    {
        $operation = $input['operation'] ?? null;
        $key = $input['request_key'] ?? null;
        $this->assertReference($resource, is_string($operation) ? $operation : '', $actor, is_string($key) ? $key : '');
        if (array_diff(array_keys($input), ['operation','request_key','code','target_id']) !== []) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Unsupported catalogue operation reference');
        $code = $input['code'] ?? null;
        if (!is_string($code) || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $code) !== 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original catalogue code is invalid');
        $target = isset($input['target_id']) ? $this->positiveId($input['target_id']) : null;
        if (($operation === 'update') !== ($target !== null)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original catalogue target is invalid');
        $guard();
        $policy = new DeploymentDraftSaveRequestPolicy();
        $scope = $this->actorScope($resource, $operation, $actor);
        $row = $this->original($resource, $operation, $actor, $policy->advisoryLockFingerprint($key, $scope));
        $dto = ['resource_type'=>$resource,'operation'=>$operation,'actor_id'=>null,'request_key'=>$key,'code'=>$code,'target_id'=>$target,'state'=>'unknown','receipt'=>null];
        if ($row !== null) {
            $context = $this->context($row);
            if ($context['code'] === $code && $context['target_id'] === $target) {
                $proof = $policy->idempotencyForKeyId($key, $scope, $context['key_id']);
                if (!hash_equals($context['idempotency_hmac'], $proof['idempotency_hmac'])) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original catalogue key is not confirmed');
                $dto['receipt'] = $this->snapshot($context, $resource, $operation);
                if ((int) $row['resource_id'] !== $dto['receipt']['id']) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original catalogue receipt attribution differs');
                $dto['actor_id'] = (int) $row['actor_ref'];
                $dto['state'] = 'completed';
            }
        }
        $guard();
        return $dto;
    }

    private function original(string $resource, string $operation, int $actor, string $lock): ?array
    {
        $rows = Db::connect('pgsql')->query("SELECT * FROM sand_ai_audit_log WHERE actor_type='admin' AND actor_ref=? AND action=? AND resource_type=? AND context->>'version'=? AND context->>'lock_sha256'=? ORDER BY id LIMIT 2", [
            (string) $actor, 'catalog.' . $resource . '.' . $operation, $resource, self::VERSION, $lock,
        ]);
        if (count($rows) > 1) throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original catalogue operation is not unique');
        return $rows[0] ?? null;
    }

    private function context(array $row): array
    {
        $context = $row['context'];
        if (is_string($context)) $context = json_decode($context, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($context) || ($context['version'] ?? null) !== self::VERSION
            || !is_string($context['key_id'] ?? null) || !is_string($context['key_version'] ?? null)
            || $context['key_version'] !== (new DeploymentDraftSaveRequestPolicy())->keyVersion()
            || !is_string($context['idempotency_hmac'] ?? null) || !is_string($context['request_fingerprint_hmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $context['idempotency_hmac']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $context['request_fingerprint_hmac']) !== 1
            || !is_string($context['code'] ?? null) || !array_key_exists('target_id', $context)) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_KEY_UNAVAILABLE', 'Original catalogue receipt proof is unavailable');
        }
        return $context;
    }

    private function snapshot(array $context, string $resource, string $operation): array
    {
        if (!is_array($context['receipt'] ?? null)) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original catalogue receipt is unavailable');
        $receipt = $this->safe($resource, $context['receipt']);
        if ($receipt['code'] !== $context['code'] || ($operation === 'save' ? $context['target_id'] !== null : $context['target_id'] !== $receipt['id'])) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original catalogue receipt target differs');
        }
        return $receipt;
    }

    private function safe(string $resource, array $row): array
    {
        $result = ['id'=>$this->positiveId($row['id'] ?? null),'code'=>$row['code'] ?? null,'name'=>$row['name'] ?? null,'status'=>(int) ($row['status'] ?? 0)];
        if (!is_string($result['code']) || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $result['code']) !== 1
            || !is_string($result['name']) || $result['name'] === '' || !in_array($result['status'], [1,2], true)) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Catalogue public receipt is invalid');
        if ($resource === 'provider') {
            $result['adapter'] = $row['adapter'] ?? null;
            $result['timeout_ms'] = $this->positiveId($row['timeout_ms'] ?? null);
            $result['has_encrypted_config'] = $row['has_encrypted_config'] ?? !empty($row['encrypted_config']);
            if (!is_string($result['adapter']) || !is_bool($result['has_encrypted_config'])) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Provider public receipt is invalid');
        } else {
            $result['type'] = $row['type'] ?? null;
            $result['capabilities'] = $row['capabilities'] ?? null;
            if (!is_string($result['type']) || !is_array($result['capabilities']) || !array_is_list($result['capabilities'])
                || array_filter($result['capabilities'], 'is_string') !== $result['capabilities']) throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Model public receipt is invalid');
        }
        return $result;
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!is_int($id)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Catalogue identifier is invalid');
        return $id;
    }

    private function actorScope(string $resource, string $operation, int $actor): string
    {
        return 'catalog:' . $resource . ':' . $operation . ':admin:' . $actor;
    }

    private function assertReference(string $resource, string $operation, int $actor, string $key): void
    {
        if (!in_array($resource, ['provider','model'], true) || !in_array($operation, ['save','update'], true)
            || $actor < 1 || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key) !== 1) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Catalogue operation reference is invalid');
    }
}
