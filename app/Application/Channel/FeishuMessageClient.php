<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;

/** Sends a text result; an ambiguous message POST cannot be replayed from a UUID alone. */
final class FeishuMessageClient
{
    /** @param null|\Closure(string,array<string,string>,array<string,mixed>):array{status:int,body:array<string,mixed>} $post */
    public function __construct(private readonly ?\Closure $post = null)
    {
    }

    /** @return array{message_id:string} */
    public function sendText(string $appId, string $appSecret, string $chatId, string $text, string $uuid,
        ?\Closure $beforeSend = null, ?string $apiBase = null): array
    {
        if ($appId === '' || $appSecret === '' || $chatId === '' || trim($text) === ''
            || mb_strlen($text, 'UTF-8') > 4000
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid) !== 1) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'Feishu delivery facts are invalid');
        }
        $base = ChannelApiBase::resolve($apiBase, 'https://open.feishu.cn');
        $tokenResponse = $this->post(
            $base . '/open-apis/auth/v3/tenant_access_token/internal',
            [], ['app_id' => $appId, 'app_secret' => $appSecret],
        );
        $token = $tokenResponse['body']['tenant_access_token'] ?? null;
        if ($tokenResponse['status'] !== 200 || ($tokenResponse['body']['code'] ?? null) !== 0
            || !is_string($token) || preg_match('/^[A-Za-z0-9._-]+$/D', $token) !== 1) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Feishu token request failed', true);
        }
        if ($beforeSend !== null) $beforeSend();
        $response = $this->post(
            $base . '/open-apis/im/v1/messages?receive_id_type=chat_id',
            ['Authorization' => 'Bearer ' . $token],
            ['receive_id' => $chatId, 'msg_type' => 'text',
                'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'uuid' => $uuid],
            true,
        );
        $code = $response['body']['code'] ?? null;
        if ($response['status'] !== 200 || !is_int($code)) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNCERTAIN', 'Feishu delivery outcome is unavailable');
        }
        if ($code !== 0) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Feishu rejected the message', true);
        }
        $data = $response['body']['data'] ?? null;
        $messageId = is_array($data) ? ($data['message_id'] ?? null) : null;
        if (!is_string($messageId) || $messageId === '') {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNCERTAIN', 'Feishu delivery receipt is unavailable');
        }
        return ['message_id' => $messageId];
    }

    /** @param array<string,string> $headers @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function post(string $url, array $headers, array $body, bool $messagePost = false): array
    {
        $errorCode = $messagePost ? 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN' : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE';
        if ($this->post !== null) {
            try {
                return ($this->post)($url, $headers, $body);
            } catch (\Throwable) {
                throw new ApiProblem($errorCode, 'Feishu transport failed', !$messagePost);
            }
        }
        $handle = curl_init($url);
        if ($handle === false) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Feishu transport is unavailable', true);
        $lines = ['Content-Type: application/json; charset=utf-8'];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $configured = curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        if (!$configured) {
            curl_close($handle);
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'Feishu transport is unavailable', true);
        }
        try {
            $raw = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } catch (\Throwable) {
            throw new ApiProblem($errorCode, 'Feishu transport failed', !$messagePost);
        } finally {
            curl_close($handle);
        }
        if (!is_string($raw) || strlen($raw) > 65536) {
            throw new ApiProblem($errorCode, 'Feishu transport failed', !$messagePost);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem($errorCode, 'Feishu response is invalid', !$messagePost);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiProblem($errorCode, 'Feishu response is invalid', !$messagePost);
        }
        return ['status' => $status, 'body' => $decoded];
    }
}
