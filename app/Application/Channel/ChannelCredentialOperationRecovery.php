<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use think\facade\Db;

/** Reads only the original non-secret save receipt, never the stored credential. */
final class ChannelCredentialOperationRecovery
{
    /** @param callable():void $revalidateAdmin @return array<string,mixed> */
    public function read(string $platform, int $environmentId, int $bindingId, string $code,
        string $key, string $fingerprint, string $actor, callable $revalidateAdmin): array
    {
        $table = self::table($platform);
        self::assertActor($actor);
        if ($environmentId < 1 || $bindingId < 1 || trim($key) !== $key || $key === '' || strlen($key) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $key) === 1
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) self::invalid();
        ChannelBindingPolicy::code($code);
        $revalidateAdmin();
        $this->requireEnvironment($environmentId);
        $this->requireBinding($environmentId, $bindingId, $code);
        $result = ['platform' => $platform, 'actor_id' => null, 'environment_id' => $environmentId,
            'channel_binding_id' => $bindingId, 'code' => $code, 'request_key' => $key,
            'input_sha256' => $fingerprint, 'state' => 'unknown', 'receipt' => null];
        $rows = Db::connect('pgsql')->query('SELECT channel_binding_id,request_fingerprint,response_snapshot,created_by
            FROM ' . $table . ' WHERE environment_id=? AND idempotency_key=?', [$environmentId, $key]);
        if (count($rows) === 1 && is_string($rows[0]['created_by']) && hash_equals($rows[0]['created_by'], $actor)) {
            $row = $rows[0];
            if (!is_string($row['request_fingerprint']) || !hash_equals($row['request_fingerprint'], $fingerprint)) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Original credential request fingerprint differs');
            }
            if ((int) $row['channel_binding_id'] !== $bindingId) self::proofUnavailable();
            $result['receipt'] = self::receipt($platform, $row['response_snapshot'], $bindingId, $code);
            // This actor is proven by the original operation, not inferred from the current session.
            $result['actor_id'] = (int) $row['created_by'];
            $result['state'] = 'completed';
        }
        $this->requireEnvironment($environmentId);
        $this->requireBinding($environmentId, $bindingId, $code);
        $revalidateAdmin();
        return $result;
    }

    /** Whitelist an original receipt; a configured flag does not prove the current credential version.
     * @return array<string,mixed>
     */
    public static function receipt(string $platform, mixed $snapshot, int $bindingId, string $code): array
    {
        self::table($platform);
        if (is_string($snapshot)) {
            try { $snapshot = json_decode($snapshot, true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { self::proofUnavailable(); }
        }
        if (is_object($snapshot)) $snapshot = (array) $snapshot;
        $flag = $platform . '_configured';
        if (!is_array($snapshot) || count($snapshot) !== 3
            || array_diff(array_keys($snapshot), ['channel_binding_id', 'code', $flag]) !== []
            || ($snapshot['channel_binding_id'] ?? null) !== $bindingId
            || ($snapshot['code'] ?? null) !== $code || ($snapshot[$flag] ?? null) !== true) {
            self::proofUnavailable();
        }
        return ['channel_binding_id' => $bindingId, 'code' => $code, $flag => true];
    }

    public static function assertActor(string $actor): void
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $actor) !== 1
            || filter_var($actor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) self::invalid();
    }

    private static function table(string $platform): string
    {
        return match ($platform) {
            'feishu' => 'sand_ai_feishu_credential_operation',
            'wecom' => 'sand_ai_wecom_credential_operation',
            default => throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Credential operation platform is invalid'),
        };
    }

    private function requireEnvironment(int $environmentId): void
    {
        try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); }
        catch (IdentityContextException $error) { throw IdentityContextProblem::from($error); }
    }

    private function requireBinding(int $environmentId, int $bindingId, string $code): void
    {
        $rows = Db::connect('pgsql')->query('SELECT id FROM sand_ai_channel_binding
            WHERE id=? AND environment_id=? AND code=? AND delete_time IS NULL', [$bindingId, $environmentId, $code]);
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original channel binding is unavailable');
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Original credential query is invalid');
    }

    private static function proofUnavailable(): never
    {
        throw new ApiProblem('SAND_AI_SCOPE_SNAPSHOT_INVALID', 'Original credential receipt is unavailable');
    }
}
