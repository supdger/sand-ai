<?php

declare(strict_types=1);

if (!class_exists(\app\Api\Support\ApiProblem::class)) require dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
if (!class_exists(\app\Application\Channel\FeishuEventDecoder::class)) require dirname(__DIR__) . '/app/Application/Channel/FeishuEventDecoder.php';

use app\Api\Support\ApiProblem;
use app\Application\Channel\FeishuEventDecoder;

$decoder = new FeishuEventDecoder();
$key = 'encryption-key';
$token = 'verification-token';
$now = 1780000000;
$event = ['schema' => '2.0', 'header' => ['token' => $token, 'event_type' => 'im.message.receive_v1'],
    'event' => ['sender' => ['sender_id' => ['open_id' => 'ou-user']],
        'message' => ['message_id' => 'om-message', 'chat_id' => 'oc-chat', 'message_type' => 'text',
            'content' => json_encode(['text' => '你好'], JSON_THROW_ON_ERROR)]]];
$raw = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$headers = static fn (string $body): array => [
    'x-lark-request-timestamp' => (string) $now, 'x-lark-request-nonce' => 'nonce-1',
    'x-lark-signature' => hash('sha256', $now . 'nonce-1' . $key . $body),
];
$mapped = $decoder->decode($raw, $headers($raw), $key, $token, $now);
if ($mapped['message'] !== ['message_id' => 'om-message', 'conversation_id' => 'oc-chat', 'sender_id' => 'ou-user', 'query' => '你好']
    || $mapped['chat_id'] !== 'oc-chat') throw new RuntimeException('Feishu event was not mapped');
$iv = str_repeat('a', 16);
$encrypted = ['encrypt' => base64_encode($iv . openssl_encrypt($raw, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv))];
$encryptedRaw = json_encode($encrypted, JSON_THROW_ON_ERROR);
if ($decoder->decode($encryptedRaw, $headers($encryptedRaw), $key, $token, $now) !== $mapped) throw new RuntimeException('Encrypted Feishu event failed');
$challenge = json_encode(['type' => 'url_verification', 'token' => $token, 'challenge' => 'check-me'], JSON_THROW_ON_ERROR);
if ($decoder->decode($challenge, [], $key, $token, $now) !== ['challenge' => 'check-me']) throw new RuntimeException('Feishu challenge failed');
$reject = static function (string $body, array $header, string $expected) use ($decoder, $key, $token, $now): void {
    try {
        $decoder->decode($body, $header, $key, $token, $now);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode === $expected) return;
        throw $problem;
    }
    throw new RuntimeException('Unsafe Feishu event accepted');
};
$reject($raw . ' ', $headers($raw), 'SAND_AI_CHANNEL_CALLBACK_INVALID');
$reject($raw, [...$headers($raw), 'x-lark-request-timestamp' => (string) ($now - 301)], 'SAND_AI_CHANNEL_CALLBACK_INVALID');
$badToken = $event;
$badToken['header']['token'] = 'wrong';
$badTokenRaw = json_encode($badToken, JSON_THROW_ON_ERROR);
$reject($badTokenRaw, $headers($badTokenRaw), 'SAND_AI_CHANNEL_CALLBACK_INVALID');
$unsupported = $event;
$unsupported['event']['message']['message_type'] = 'image';
$unsupportedRaw = json_encode($unsupported, JSON_THROW_ON_ERROR);
$reject($unsupportedRaw, $headers($unsupportedRaw), 'SAND_AI_CHANNEL_UNSUPPORTED_MESSAGE');
echo "feishu-event-decoder 7/7\n";
