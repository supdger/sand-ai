<?php

declare(strict_types=1);

namespace app\Application\Usage;

use app\Api\Support\ApiProblem;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Original price writes are recovered from their committed receipt, never the current directory. */
final class PricingOperationReceipt
{
    public function key(?string $key): ?string
    {
        if ($key === null) return null;
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key) !== 1) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Price operation request key is invalid');
        }
        return $key;
    }

    public function fingerprint(array $input): string
    {
        return hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item);
        return $value;
    }

    public function replay(ConnectionInterface $connection, int $actor, string $kind, string $key, string $hash): ?array
    {
        $connection->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sand_ai:pricing_operation:' . $actor . ':' . $kind . ':' . $key]);
        $row = $connection->table('sand_ai_pricing_operation')->where('actor_id', $actor)->where('kind', $kind)->where('request_key', $key)->find();
        if (!is_array($row)) return null;
        if (!hash_equals((string) $row['input_sha256'], $hash)) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original price request key belongs to different input');
        }
        return $this->snapshot($row) + ['idempotent_replay' => true];
    }

    public function record(ConnectionInterface $connection, int $actor, string $kind, string $key, string $hash, ?int $target, array $receipt): void
    {
        $connection->query('INSERT INTO sand_ai_pricing_operation (actor_id,kind,request_key,input_sha256,provider_id,model_id,capability_code,target_id,response_snapshot) VALUES (?,?,?,?,?,?,?,?,CAST(? AS jsonb)) RETURNING id', [
            $actor, $kind, $key, $hash, $receipt['provider_id'], $receipt['model_id'], $receipt['capability_code'], $target,
            json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function read(array $input, int $actor, \Closure $revalidate): array
    {
        $revalidate();
        if ($actor < 1 || array_diff(array_keys($input), ['kind','provider_id','model_id','capability_code','target_id','request_key','input_sha256']) !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Price operation reference is invalid');
        }
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['save','publish'], true)) throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Price operation kind is invalid');
        $values = new PricingValuePolicy();
        $provider = $values->positiveId($input['provider_id'] ?? null, 'provider_id');
        $model = $values->positiveId($input['model_id'] ?? null, 'model_id');
        $target = isset($input['target_id']) && $input['target_id'] !== '' ? $values->positiveId($input['target_id'], 'target_id') : null;
        $capability = $input['capability_code'] ?? null;
        $hash = $input['input_sha256'] ?? null;
        $key = $this->key(is_string($input['request_key'] ?? null) ? $input['request_key'] : '');
        if (!is_string($capability) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $capability) !== 1
            || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || ($kind === 'publish' && $target === null)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Price operation proof is invalid');
        }
        $dto = ['kind'=>$kind,'actor_id'=>null,'provider_id'=>$provider,'model_id'=>$model,'capability_code'=>$capability,
            'target_id'=>$target,'request_key'=>$key,'input_sha256'=>$hash,'state'=>'unknown','receipt'=>null];
        $row = Db::connect('pgsql')->table('sand_ai_pricing_operation')->where('actor_id', $actor)->where('kind', $kind)->where('request_key', $key)->find();
        if (is_array($row) && (int) $row['provider_id'] === $provider && (int) $row['model_id'] === $model
            && (string) $row['capability_code'] === $capability && ($row['target_id'] === null ? null : (int) $row['target_id']) === $target
            && hash_equals((string) $row['input_sha256'], $hash)) {
            $dto['actor_id'] = (int) $row['actor_id'];
            $dto['state'] = 'completed';
            $dto['receipt'] = $this->snapshot($row);
        }
        $revalidate();
        return $dto;
    }

    private function snapshot(array $row): array
    {
        $snapshot = $row['response_snapshot'];
        if (is_string($snapshot)) $snapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || (int) ($snapshot['id'] ?? 0) < 1
            || (int) ($snapshot['provider_id'] ?? 0) !== (int) $row['provider_id']
            || (int) ($snapshot['model_id'] ?? 0) !== (int) $row['model_id']
            || ($snapshot['capability_code'] ?? null) !== $row['capability_code']
            || ($row['target_id'] !== null && (int) $snapshot['id'] !== (int) $row['target_id'])
            || !is_array($snapshot['rates'] ?? null) || !in_array($snapshot['state'] ?? null, ['draft','published'], true)
            || ($row['kind'] === 'publish' && $snapshot['state'] !== 'published')) {
            throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Original price receipt is not confirmed');
        }
        return $snapshot;
    }
}
