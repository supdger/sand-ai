<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use think\facade\Db;
use Throwable;

final class FeishuChannelStatusGateway
{
    /** @param array<string,string> $credentials @return array{int,int}|null */
    public static function managedScope(array $credentials): ?array
    {
        if (!isset($credentials['_environment_id'], $credentials['_binding_id'])) return null;
        $environmentId = filter_var($credentials['_environment_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $bindingId = filter_var($credentials['_binding_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $environmentId === false || $bindingId === false ? null : [(int) $environmentId, (int) $bindingId];
    }

    public function verified(int $environmentId, int $bindingId): void
    {
        $this->record(
            'INSERT INTO sand_ai_feishu_channel_status (environment_id,channel_binding_id,last_verified_callback_at)
             VALUES (?,?,CURRENT_TIMESTAMP(0))
             ON CONFLICT (channel_binding_id) DO UPDATE SET last_verified_callback_at=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)',
            [$environmentId, $bindingId],
        );
    }

    public function accepted(int $environmentId, int $bindingId): void
    {
        $this->record(
            'INSERT INTO sand_ai_feishu_channel_status (environment_id,channel_binding_id,last_accepted_message_at)
             VALUES (?,?,CURRENT_TIMESTAMP(0))
             ON CONFLICT (channel_binding_id) DO UPDATE SET last_accepted_message_at=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)',
            [$environmentId, $bindingId],
        );
    }

    public function error(int $environmentId, int $bindingId, string $code): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code) !== 1) $code = 'SAND_AI_CHANNEL_CALLBACK_FAILED';
        $this->record(
            'INSERT INTO sand_ai_feishu_channel_status
                (environment_id,channel_binding_id,last_callback_error_code,last_callback_error_at)
             VALUES (?,?,?,CURRENT_TIMESTAMP(0))
             ON CONFLICT (channel_binding_id) DO UPDATE SET
                last_callback_error_code=EXCLUDED.last_callback_error_code,
                last_callback_error_at=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)',
            [$environmentId, $bindingId, $code],
        );
    }

    public function deliveryError(int $environmentId, int $bindingId, string $code): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code) !== 1) $code = 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE';
        $this->record(
            'INSERT INTO sand_ai_feishu_channel_status
                (environment_id,channel_binding_id,last_delivery_error_code,last_delivery_error_at)
             VALUES (?,?,?,CURRENT_TIMESTAMP(0))
             ON CONFLICT (channel_binding_id) DO UPDATE SET
                last_delivery_error_code=EXCLUDED.last_delivery_error_code,
                last_delivery_error_at=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)',
            [$environmentId, $bindingId, $code],
        );
    }

    /** @return array<string,mixed> */
    public function read(int $environmentId, int $bindingId): array
    {
        if ($environmentId < 1 || $bindingId < 1) self::invalid();
        try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); }
        catch (IdentityContextException $e) { throw IdentityContextProblem::from($e); }
        $db = Db::connect('pgsql');
        $binding = $db->query('SELECT id FROM sand_ai_channel_binding
            WHERE environment_id=? AND id=? AND delete_time IS NULL', [$environmentId, $bindingId]);
        if ($binding === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel binding is unavailable');
        $rows = $db->query('SELECT last_verified_callback_at,last_accepted_message_at,
            last_callback_error_code,last_callback_error_at,last_delivery_error_code,last_delivery_error_at
            FROM sand_ai_feishu_channel_status WHERE environment_id=? AND channel_binding_id=?',
            [$environmentId, $bindingId]);
        $status = $rows[0] ?? [];
        $latest = $db->query('SELECT state,update_time FROM sand_ai_feishu_delivery
            WHERE environment_id=? AND channel_binding_id=? ORDER BY task_id DESC LIMIT 1',
            [$environmentId, $bindingId]);
        $failed = $db->query("SELECT error_code,update_time FROM sand_ai_feishu_delivery
            WHERE environment_id=? AND channel_binding_id=? AND state IN ('failed','uncertain')
            ORDER BY task_id DESC LIMIT 1", [$environmentId, $bindingId]);
        return [
            'channel_binding_id' => $bindingId,
            'last_verified_callback_at' => self::nullable($status['last_verified_callback_at'] ?? null),
            'last_accepted_message_at' => self::nullable($status['last_accepted_message_at'] ?? null),
            'last_callback_error_code' => self::nullable($status['last_callback_error_code'] ?? null),
            'last_callback_error_at' => self::nullable($status['last_callback_error_at'] ?? null),
            'last_delivery_state' => self::nullable($latest[0]['state'] ?? null),
            'last_delivery_at' => self::nullable($latest[0]['update_time'] ?? null),
            'last_delivery_error_code' => self::nullable($status['last_delivery_error_code'] ?? $failed[0]['error_code'] ?? null),
            'last_delivery_error_at' => self::nullable($status['last_delivery_error_at'] ?? $failed[0]['update_time'] ?? null),
        ];
    }

    /** @param list<mixed> $parameters */
    private function record(string $sql, array $parameters): void
    {
        if ($parameters[0] < 1 || $parameters[1] < 1) return;
        try { Db::connect('pgsql')->execute($sql, $parameters); }
        catch (Throwable) { error_log('SandAI Feishu callback status could not be recorded'); }
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel status query is invalid');
    }
}
