<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

final class ChannelBindingPolicy
{
    /** @param array<string,mixed> $input @return array{code:string,name:string,agent_definition_id:int,application_id:string,workload_client_id:string} */
    public static function configuration(array $input): array
    {
        if (array_diff(array_keys($input), ['code','name','agent_definition_id','application_id','workload_client_id']) !== []) self::invalid();
        $code = self::code($input['code'] ?? null);
        $name = $input['name'] ?? null;
        $agent = $input['agent_definition_id'] ?? null;
        $application = $input['application_id'] ?? null;
        $client = $input['workload_client_id'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 128
            || !is_int($agent) || $agent <= 0 || !is_string($application) || trim($application) === '' || strlen($application) > 64
            || !is_string($client) || preg_match('/^[1-9][0-9]{0,18}$/D', $client) !== 1
            || preg_match('/^[1-9][0-9]{0,18}$/D', $application) !== 1) self::invalid();
        return ['code' => $code, 'name' => trim($name), 'agent_definition_id' => $agent, 'application_id' => trim($application), 'workload_client_id' => trim($client)];
    }

    public static function code(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value) !== 1) self::invalid();
        return $value;
    }

    /** Only verified platform callbacks interpret this exact control message. */
    public static function closesConversation(string $verifiedRawText): bool
    {
        return $verifiedRawText === '/结束对话';
    }

    /** @param array<string,mixed> $input @return array{query:string,conversation_scope:string,message_scope:string} */
    public static function message(array $input, int $channelId): array
    {
        if (array_diff(array_keys($input), ['message_id','conversation_id','sender_id','query']) !== []) self::invalid();
        foreach (['message_id','conversation_id','sender_id','query'] as $field) {
            if (!is_string($input[$field] ?? null) || trim($input[$field]) === '' || mb_strlen($input[$field], 'UTF-8') > ($field === 'query' ? 500 : 128)) self::invalid();
        }
        return ['query' => trim($input['query']),
            'conversation_scope' => self::scope($channelId, $input['conversation_id'], $input['sender_id']),
            'message_scope' => self::messageScope($channelId, $input['message_id'])];
    }

    /** @param array<string,mixed> $input @return array{query:string,conversation_scope:string,message_scope:string} */
    public static function platformMessage(array $input, int $channelId, string $platform): array
    {
        if (!in_array($platform, ['feishu', 'wecom'], true)) self::invalid();
        $message = self::message($input, $channelId);
        return [
            'query' => $message['query'],
            'conversation_scope' => self::scope($channelId, $input['conversation_id'], $input['sender_id'], $platform),
            'message_scope' => self::messageScope($channelId, $input['message_id'], $platform),
        ];
    }

    public static function messageScope(int $channelId, string $messageId, ?string $platform = null): string
    {
        if ($channelId <= 0 || trim($messageId) === '' || mb_strlen($messageId, 'UTF-8') > 128) self::invalid();
        if ($platform !== null && !in_array($platform, ['feishu', 'wecom'], true)) self::invalid();
        return hash('sha256', json_encode($platform === null ? [$channelId, $messageId] : [$channelId, $platform, $messageId],
            ($platform === null ? 0 : JSON_UNESCAPED_UNICODE) | JSON_THROW_ON_ERROR));
    }

    public static function scope(int $channelId, string $conversationId, string $senderId, ?string $platform = null): string
    {
        if ($channelId <= 0 || trim($conversationId) === '' || trim($senderId) === '' || mb_strlen($conversationId, 'UTF-8') > 128 || mb_strlen($senderId, 'UTF-8') > 128) self::invalid();
        if ($platform !== null && !in_array($platform, ['feishu', 'wecom'], true)) self::invalid();
        return hash('sha256', json_encode($platform === null ? [$channelId, $conversationId, $senderId] : [$channelId, $platform, $conversationId, $senderId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function invalid(): never { throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Channel request is invalid'); }
}
