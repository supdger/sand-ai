<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Identity\Contract\IdentityContext;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;

/** Keeps an old callback on its original task when a platform-scoped release receives a redelivery. */
final class ChannelWebhookMessageIdentity
{
    /**
     * @param array{message_id:string,conversation_id:string,sender_id:string,query:string} $message
     * @return array{message:array{query:string,conversation_scope:string,message_scope:string},key:string,legacy:bool}
     */
    public static function resolve(
        IdentityContext $identity,
        string $code,
        int $bindingId,
        array $message,
        string $platform,
    ): array {
        $current = ChannelBindingPolicy::platformMessage($message, $bindingId, $platform);
        $legacy = ChannelBindingPolicy::message($message, $bindingId);
        $legacyKey = self::key($identity, $code, $legacy['message_scope'], $message['message_id']);
        $task = TaskRun::where('request_id', $legacyKey)->find();
        if ($task !== null) {
            if ((int) $task->environment_id !== $identity->environmentId
                || (string) $task->task_type !== 'agent_run') {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The callback belongs to another task');
            }
            $input = is_object($task->input) ? (array) $task->input : $task->input;
            $frozen = is_array($input) ? ($input['_channel'] ?? null) : null;
            if (!is_array($frozen) || ($frozen['delivery_platform'] ?? null) !== null
                || !hash_equals((string) ($frozen['message_scope'] ?? ''), $legacy['message_scope'])) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The callback identity changed');
            }
            $opposite = $platform === 'wecom' ? 'sand_ai_feishu_delivery' : 'sand_ai_wecom_delivery';
            if (Db::connect('pgsql')->query("SELECT 1 FROM {$opposite} WHERE task_id=?", [(int) $task->id]) !== []) {
                throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'The callback belongs to another platform');
            }
            return ['message' => $legacy, 'key' => $legacyKey, 'legacy' => true];
        }
        return [
            'message' => $current,
            'key' => self::key($identity, $code, $current['message_scope'], $message['message_id']),
            'legacy' => false,
        ];
    }

    private static function key(IdentityContext $identity, string $code, string $scope, string $messageId): string
    {
        return 'channel:' . hash('sha256', json_encode([
            RequestIdempotencyKey::trustedScope($identity),
            $code . ':' . $scope,
            $messageId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
