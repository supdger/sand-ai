<?php

declare(strict_types=1);

namespace app\Application\Channel;

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\VerifiedEndpoint;

/** Sends one self-built application text message; ambiguous POST results are never retried automatically. */
final class WecomMessageClient
{
    /**
     * @param null|\Closure(string,?array<string,mixed>,bool):array<string,mixed> $request
     * @param null|ChannelApiBasePolicy $endpointPolicy egress gate for api_base;
     *        tests inject a fixture resolver, production uses the system one
     */
    public function __construct(
        private readonly ?\Closure $request = null,
        private readonly ?ChannelApiBasePolicy $endpointPolicy = null,
    ) {
    }

    public function sendText(string $corpId, string $corpSecret, string $agentId, string $userId, string $text,
        ?\Closure $beforeSend = null, ?string $apiBase = null): void
    {
        if ($corpId === '' || $corpSecret === '' || preg_match('/^[0-9]{1,20}$/D', $agentId) !== 1
            || $userId === '' || strlen($userId) > 128 || trim($text) === ''
            || mb_strlen($text, 'UTF-8') > 2048 || strlen($text) > 4000) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'WeCom delivery facts are invalid');
        }
        // The egress gate runs before any credential-bearing request: the
        // effective api_base must resolve to public (or explicitly allowlisted)
        // addresses, and both requests below pin the checked address via
        // CURLOPT_RESOLVE so a DNS rebind cannot redirect corp secrets.
        $endpoint = ($this->endpointPolicy ?? new ChannelApiBasePolicy())->verify($apiBase, 'https://qyapi.weixin.qq.com');
        $tokenResponse = $this->request(
            $endpoint->baseUrl . '/cgi-bin/gettoken?corpid=' . rawurlencode($corpId)
                . '&corpsecret=' . rawurlencode($corpSecret), null, false, $endpoint,
        );
        $token = $tokenResponse['access_token'] ?? null;
        \Log::error('SandAI wecom gettoken response', ['body' => json_encode($tokenResponse)]);
        if (($tokenResponse['errcode'] ?? null) !== 0 || !is_string($token)
            || $token === '' || strlen($token) > 512) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'WeCom token request failed', true);
        }
        if ($beforeSend !== null) $beforeSend();
        $response = $this->request(
            $endpoint->baseUrl . '/cgi-bin/message/send?access_token=' . rawurlencode($token),
            ['touser' => $userId, 'msgtype' => 'text', 'agentid' => (int) $agentId,
                'text' => ['content' => $text], 'safe' => 0], true, $endpoint,
        );
        if (($response['errcode'] ?? null) !== 0) {
            throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'WeCom rejected the message', true);
        }
        foreach (['invaliduser','invalidparty','invalidtag','unlicenseduser'] as $field) {
            if (is_string($response[$field] ?? null) && $response[$field] !== '') {
                throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_INVALID', 'WeCom recipient is unavailable');
            }
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $url, ?array $body, bool $messagePost, ?VerifiedEndpoint $endpoint = null): array
    {
        if ($this->request !== null) {
            try {
                return ($this->request)($url, $body, $messagePost);
            } catch (\Throwable) {
                throw new ApiProblem($messagePost ? 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN' : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE',
                    'WeCom transport failed', true);
            }
        }
        $handle = curl_init($url);
        if ($handle === false) throw new ApiProblem('SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE', 'WeCom transport is unavailable', true);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ];
        // Pin the TCP connection to the address the egress policy already
        // verified; TLS/SNI still uses the original hostname.
        if ($endpoint !== null) {
            $options[CURLOPT_RESOLVE] = [$endpoint->curlResolveEntry()];
        }
        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($raw) || strlen($raw) > 65536 || $status !== 200) {
            \Log::error('SandAI wecom transport failed', ['status' => $status, 'raw' => is_string($raw) ? substr($raw, 0, 300) : null]);
            throw new ApiProblem($messagePost ? 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN' : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE',
                'WeCom transport failed', true);
        }
        try { $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) {
            throw new ApiProblem($messagePost ? 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN' : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE',
                'WeCom response is invalid', true);
        }
        if (!is_array($decoded) || array_is_list($decoded) || !is_int($decoded['errcode'] ?? null)) {
            throw new ApiProblem($messagePost ? 'SAND_AI_CHANNEL_DELIVERY_UNCERTAIN' : 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE',
                'WeCom response is invalid', true);
        }
        return $decoded;
    }
}
