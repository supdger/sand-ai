<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

/** Validates a Feishu v2 event before mapping it to a SandAI channel message. */
final class FeishuEventDecoder
{
    /**
     * @param array<string,string> $headers Lowercase HTTP header names.
     * @return array{challenge?:string,message?:array{message_id:string,conversation_id:string,sender_id:string,query:string},chat_id?:string,conversation_close?:bool}
     */
    public function decode(string $raw, array $headers, string $encryptKey, string $verificationToken, int $now): array
    {
        if ($encryptKey === '' || $verificationToken === '' || $raw === '' || strlen($raw) > 65536) self::invalid();
        $outer = self::jsonObject($raw);
        if (isset($outer['encrypt'])) {
            $cipher = $outer['encrypt'];
            if (!is_string($cipher)) self::invalid();
            $bytes = base64_decode($cipher, true);
            if (!is_string($bytes) || strlen($bytes) < 32 || strlen($bytes) % 16 !== 0) self::invalid();
            $plain = openssl_decrypt(substr($bytes, 16), 'aes-256-cbc', hash('sha256', $encryptKey, true), OPENSSL_RAW_DATA, substr($bytes, 0, 16));
            if (!is_string($plain)) self::invalid();
            $event = self::jsonObject($plain);
        } else {
            $event = $outer;
        }
        $isChallenge = ($event['type'] ?? null) === 'url_verification';
        $timestamp = $headers['x-lark-request-timestamp'] ?? null;
        $nonce = $headers['x-lark-request-nonce'] ?? null;
        $signature = $headers['x-lark-signature'] ?? null;
        if ($signature !== null || !$isChallenge) {
            if (!is_string($timestamp) || preg_match('/^[0-9]{10}$/D', $timestamp) !== 1
                || abs((int) $timestamp - $now) > 300 || !is_string($nonce) || $nonce === ''
                || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
                || !hash_equals(hash('sha256', $timestamp . $nonce . $encryptKey . $raw), $signature)) self::invalid();
        }
        $token = $event['header']['token'] ?? $event['token'] ?? null;
        if (!is_string($token) || !hash_equals($verificationToken, $token)) self::invalid();
        if (($event['type'] ?? null) === 'url_verification') {
            $challenge = $event['challenge'] ?? null;
            if (!is_string($challenge) || $challenge === '' || strlen($challenge) > 256) self::invalid();
            return ['challenge' => $challenge];
        }
        if (($event['schema'] ?? null) !== '2.0' || ($event['header']['event_type'] ?? null) !== 'im.message.receive_v1') {
            throw new ApiProblem('SAND_AI_CHANNEL_UNSUPPORTED_EVENT', 'Feishu event is unsupported');
        }
        $message = $event['event']['message'] ?? null;
        $sender = $event['event']['sender']['sender_id']['open_id'] ?? null;
        if (!is_array($message) || ($message['message_type'] ?? null) !== 'text') {
            throw new ApiProblem('SAND_AI_CHANNEL_UNSUPPORTED_MESSAGE', 'Feishu message type is unsupported');
        }
        $content = is_string($message['content'] ?? null) ? self::jsonObject($message['content']) : null;
        $text = $content['text'] ?? null;
        foreach ([$message['message_id'] ?? null, $message['chat_id'] ?? null, $sender] as $value) {
            if (!is_string($value) || $value === '' || mb_strlen($value, 'UTF-8') > 128) self::invalid();
        }
        if (!is_string($text) || trim($text) === '' || mb_strlen($text, 'UTF-8') > 500) self::invalid();
        return [
            'message' => ['message_id' => $message['message_id'], 'conversation_id' => $message['chat_id'],
                'sender_id' => $sender, 'query' => trim($text)],
            'chat_id' => $message['chat_id'],
            'conversation_close' => ChannelBindingPolicy::closesConversation($text),
        ];
    }

    /** @return array<string,mixed> */
    private static function jsonObject(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!is_array($value) || array_is_list($value)) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_CHANNEL_CALLBACK_INVALID', 'Feishu callback is invalid');
    }
}
