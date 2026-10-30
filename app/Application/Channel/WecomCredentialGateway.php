<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamEnvironmentReferenceVerifier;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;

final class WecomCredentialGateway
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $environmentId, int $bindingId, array $input, string $key, string $actor, ?\Closure $revalidateAdmin = null): array
    {
        if ($environmentId < 1 || $bindingId < 1 || trim($key) === '' || strlen($key) > 128
            || trim($actor) === '' || strlen($actor) > 64) self::invalid();
        ChannelCredentialOperationRecovery::assertActor($actor);
        if ($revalidateAdmin !== null) $revalidateAdmin();
        try { (new HostSandIamEnvironmentReferenceVerifier())->requireEnvironment($environmentId); }
        catch (IdentityContextException $e) { throw IdentityContextProblem::from($e); }
        self::validateInput($input);
        $fingerprint = RequestPayloadFingerprint::fingerprint(['channel_binding_id' => $bindingId, ...$input]);
        return Db::connect('pgsql')->transaction(function () use ($environmentId, $bindingId, $input, $key, $actor, $fingerprint, $revalidateAdmin): array {
            if ($revalidateAdmin !== null) $revalidateAdmin();
            $db = Db::connect('pgsql');
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$environmentId . ':wecom-credential-operation:' . $key]);
            $binding = $db->query('SELECT id,code FROM sand_ai_channel_binding
                WHERE id=? AND environment_id=? AND status=1 AND delete_time IS NULL FOR UPDATE', [$bindingId, $environmentId]);
            if ($binding === []) throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Channel binding is unavailable');
            $code = (string) $binding[0]['code'];
            $replays = $db->query('SELECT request_fingerprint,response_snapshot,channel_binding_id,created_by FROM sand_ai_wecom_credential_operation
                WHERE environment_id=? AND idempotency_key=? FOR UPDATE', [$environmentId, $key]);
            if ($replays !== []) {
                if (count($replays) !== 1 || !is_string($replays[0]['created_by'])
                    || !hash_equals($replays[0]['created_by'], $actor)
                    || (int) $replays[0]['channel_binding_id'] !== $bindingId) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Original credential operation is unavailable');
                }
                if (!hash_equals((string) $replays[0]['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The Idempotency-Key belongs to another request');
                }
                $snapshot = ChannelCredentialOperationRecovery::receipt('wecom', $replays[0]['response_snapshot'], $bindingId, $code);
                if ($revalidateAdmin !== null) $revalidateAdmin();
                return [...$snapshot, 'idempotent_replay' => true];
            }
            $db->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['wecom-credential-code:' . $code]);
            $existing = $db->query('SELECT channel_binding_id FROM sand_ai_wecom_channel_credential WHERE code=? FOR UPDATE', [$code]);
            if ($existing !== [] && (int) $existing[0]['channel_binding_id'] !== $bindingId) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Wecom callback code belongs to another channel');
            }
            $envelope = (new WecomCredentialCipher((array) config('sand_ai_iam_context', [])))
                ->seal($input, $environmentId, $bindingId, $code);
            $db->execute('INSERT INTO sand_ai_wecom_channel_credential
                (channel_binding_id,environment_id,code,credential_envelope,updated_by)
                VALUES (?,?,?,CAST(? AS jsonb),?)
                ON CONFLICT (channel_binding_id) DO UPDATE SET credential_envelope=EXCLUDED.credential_envelope,
                updated_by=EXCLUDED.updated_by,update_time=CURRENT_TIMESTAMP(0)',
                [$bindingId, $environmentId, $code, json_encode($envelope, JSON_THROW_ON_ERROR), $actor]);
            $result = ['channel_binding_id' => $bindingId, 'code' => $code, 'wecom_configured' => true];
            $db->execute('INSERT INTO sand_ai_wecom_credential_operation
                (environment_id,idempotency_key,request_fingerprint,channel_binding_id,response_snapshot,created_by)
                VALUES (?,?,?,?,CAST(? AS jsonb),?)',
                [$environmentId, $key, $fingerprint, $bindingId, json_encode($result, JSON_THROW_ON_ERROR), $actor]);
            AuditLog::create(['actor_type' => 'admin', 'actor_ref' => $actor, 'action' => 'channel_wecom_credential.save',
                'resource_type' => 'channel_binding', 'resource_id' => $bindingId,
                'summary' => 'channel_wecom_credential.save', 'context' => ['code' => $code]]);
            if ($revalidateAdmin !== null) $revalidateAdmin();
            return $result;
        });
    }

    /** @param callable():void $revalidateAdmin @return array<string,mixed> */
    public function readOperation(int $environmentId, int $bindingId, string $code, string $key,
        string $fingerprint, string $actor, callable $revalidateAdmin): array
    {
        return (new ChannelCredentialOperationRecovery())->read('wecom', $environmentId, $bindingId,
            $code, $key, $fingerprint, $actor, $revalidateAdmin);
    }

    /** @param array<string,mixed> $input */
    private static function validateInput(array $input): void
    {
        $fields = array_keys($input);
        sort($fields, SORT_STRING);
        $required = ['agent_id','corp_id','corp_secret','encoding_aes_key','token','workload_credential'];
        $withApiBase = $required; $withApiBase[] = 'api_base'; sort($withApiBase, SORT_STRING);
        if ($fields !== $required && $fields !== $withApiBase) self::invalid();
        foreach ($required as $field) {
            $value = $input[$field];
            if (!is_string($value) || trim($value) === '' || strlen($value) > 8192) self::invalid();
        }
        if (preg_match('/^[0-9]{1,20}$/D', $input['agent_id']) !== 1
            || preg_match('/^[A-Za-z0-9]{43}$/D', $input['encoding_aes_key']) !== 1) self::invalid();
        if (array_key_exists('api_base', $input)) {
            try { ChannelApiBase::resolve($input['api_base'], 'https://qyapi.weixin.qq.com'); }
            catch (ApiProblem) { self::invalid(); }
        }
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Wecom channel credentials are invalid');
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_UNAVAILABLE', 'Wecom channel configuration is unavailable');
    }
}
