<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use think\facade\Db;

/** @internal api_base is an optional override enabling Lark (open.larksuite.com) and private deployments; absent means open.feishu.cn. */
final class FeishuChannelCredentials
{
    /** @return array<string,string> */
    public static function forCode(string $code): array
    {
        ChannelBindingPolicy::code($code);
        $rows = Db::connect('pgsql')->query(
            'SELECT f.environment_id,f.channel_binding_id,f.credential_envelope,
                b.environment_id AS binding_environment_id,b.code AS binding_code,b.status AS binding_status,b.delete_time AS binding_delete_time
             FROM sand_ai_feishu_channel_credential f
             JOIN sand_ai_channel_binding b ON b.id=f.channel_binding_id WHERE f.code=?', [$code],
        );
        if ($rows !== []) {
            $row = $rows[0];
            if ((int) $row['environment_id'] !== (int) $row['binding_environment_id']
                || $code !== (string) $row['binding_code'] || (int) $row['binding_status'] !== 1
                || $row['binding_delete_time'] !== null) self::unavailable();
            $envelope = $row['credential_envelope'];
            if (is_string($envelope)) {
                try { $envelope = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { self::unavailable(); }
            }
            if (is_object($envelope)) $envelope = (array) $envelope;
            if (!is_array($envelope)) self::unavailable();
            $environmentId = (int) $row['environment_id'];
            $bindingId = (int) $row['channel_binding_id'];
            $credentials = (new FeishuCredentialCipher((array) config('sand_ai_iam_context', [])))
                ->open($envelope, $environmentId, $bindingId, $code);
            return [...$credentials, '_environment_id' => (string) $environmentId, '_binding_id' => (string) $bindingId];
        }
        $raw = config('sand_ai_feishu.channels_json', '');
        if (!is_string($raw) || $raw === '') self::unavailable();
        try { $map = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { self::unavailable(); }
        $value = is_array($map) ? ($map[$code] ?? null) : null;
        if (!is_array($value)) self::unavailable();
        $value = array_intersect_key($value, array_fill_keys(
            ['encrypt_key','verification_token','workload_credential','app_id','app_secret','api_base'], true,
        ));
        self::validate($value);
        return $value;
    }

    /** @param array<string,mixed> $credentials */
    public static function validate(array $credentials): void
    {
        $fields = array_keys($credentials);
        sort($fields, SORT_STRING);
        $required = ['app_id','app_secret','encrypt_key','verification_token','workload_credential'];
        $withApiBase = $required; $withApiBase[] = 'api_base'; sort($withApiBase, SORT_STRING);
        if ($fields !== $required && $fields !== $withApiBase) self::unavailable();
        foreach ($required as $field) {
            $value = $credentials[$field];
            if (!is_string($value) || trim($value) === '' || strlen($value) > 8192) self::unavailable();
        }
        if (array_key_exists('api_base', $credentials)) {
            ChannelApiBase::resolve($credentials['api_base'], 'https://open.feishu.cn');
        }
    }

    /** @param array<string,string> $credentials */
    public static function assertManagedScope(array $credentials, int $environmentId, int $bindingId): void
    {
        if (!isset($credentials['_environment_id'], $credentials['_binding_id'])) return;
        if (!hash_equals($credentials['_environment_id'], (string) $environmentId)
            || !hash_equals($credentials['_binding_id'], (string) $bindingId)) self::unavailable();
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_UNAVAILABLE', 'Feishu channel configuration is unavailable');
    }
}
