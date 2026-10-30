<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;
use app\Application\Billing\BillingCredentialOperationStore;
use app\Infrastructure\Persistence\Model\Provider;
use think\db\ConnectionInterface;
use think\facade\Db;

final class ProviderModelBillingCredentialStore implements BillingCredentialOperationStore
{
    private ?ConnectionInterface $transactionConnection = null;

    public function transaction(callable $callback): mixed
    {
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use ($callback): mixed {
            if ($this->transactionConnection !== null) {
                throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'nested billing credential transactions are not supported');
            }
            $this->transactionConnection = $connection;
            try {
                return $callback();
            } finally {
                $this->transactionConnection = null;
            }
        });
    }

    public function find(int $id): ?array
    {
        $provider = Provider::where('id', $id)->find();
        if ($provider === null) {
            return null;
        }

        return [
            'id' => (int) $provider->id,
            'status' => (int) $provider->status,
            'code' => (string) $provider->code,
            'adapter' => (string) $provider->adapter,
            'encrypted_config' => $this->encryptedConfig($provider->encrypted_config),
        ];
    }

    public function lock(int $id): ?array
    {
        $rows = $this->connection()->query(
            'SELECT id, status, code, adapter, encrypted_config FROM sand_ai_provider WHERE id = ? AND delete_time IS NULL FOR UPDATE',
            [$id],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'id' => (int) $rows[0]['id'],
            'status' => (int) $rows[0]['status'],
            'code' => (string) $rows[0]['code'],
            'adapter' => (string) $rows[0]['adapter'],
            'encrypted_config' => $this->encryptedConfig($rows[0]['encrypted_config'] ?? null),
        ];
    }

    public function update(int $id, array $encryptedConfig): void
    {
        $json = json_encode($encryptedConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rows = $this->connection()->query(
            'UPDATE sand_ai_provider SET encrypted_config = CAST(? AS jsonb), update_time = ? WHERE id = ? AND delete_time IS NULL RETURNING id',
            [$json, date('Y-m-d H:i:s'), $id],
        );
        if ($rows === []) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'provider not found');
        }
    }

    public function createAudit(int $adminId, int $providerId): void
    {
        $this->connection()->query(
            'INSERT INTO sand_ai_audit_log (actor_type, actor_ref, action, resource_type, resource_id, summary, context) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
            [
                'admin',
                (string) $adminId,
                'billing.credential.update',
                'provider',
                $providerId,
                'Updated dedicated Provider billing credential',
                json_encode(['provider_id' => $providerId], JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function lockOriginalSave(string $lock): void
    {
        if ($this->transactionConnection === null) {
            throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'billing credential save requires a transaction');
        }
        $this->connection()->query('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['sand_ai:billing_credential_save:' . $lock]);
    }

    public function originalSave(int $adminId, int $providerId, string $lock): ?array
    {
        $rows = $this->connection()->query(
            "SELECT actor_type, actor_ref, action, resource_type, resource_id, context FROM sand_ai_audit_log WHERE actor_type='admin' AND actor_ref=? AND action='billing.credential.update' AND resource_type='provider' AND resource_id=? AND context->>'version'='billing_credential_save@1' AND context->>'lock_sha256'=? ORDER BY id LIMIT 2",
            [(string) $adminId, $providerId, $lock],
        );
        if (count($rows) > 1) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original billing credential receipt is not unique');
        }
        return $rows[0] ?? null;
    }

    public function createOriginalSave(int $adminId, int $providerId, array $context): void
    {
        if ($this->transactionConnection === null) {
            throw new ApiProblem('SAND_AI_BILLING_TRANSACTION_INVALID', 'billing credential receipt requires the save transaction');
        }
        $this->connection()->query(
            'INSERT INTO sand_ai_audit_log (actor_type, actor_ref, action, resource_type, resource_id, summary, context) VALUES (?, ?, ?, ?, ?, ?, CAST(? AS jsonb)) RETURNING id',
            ['admin', (string) $adminId, 'billing.credential.update', 'provider', $providerId,
                'Saved dedicated Provider billing credential receipt',
                json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        );
    }

    private function connection(): ConnectionInterface
    {
        return $this->transactionConnection ?? Db::connect('pgsql');
    }

    /** @return array<string,mixed> */
    private function encryptedConfig(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'provider encrypted config invalid');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'provider encrypted config invalid');
        }

        return $decoded;
    }
}
