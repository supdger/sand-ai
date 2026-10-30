<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;
use app\Application\Audio\MeetingSummaryTokenCountLedger;
use app\Application\Gateway\RequestPayloadFingerprint;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;

/** Official text-only message counting; these receipts are not generation usage. */
final class BigModelMeetingTokenCounter
{
    public const ENDPOINT = 'https://open.bigmodel.cn/api/paas/v4/tokenizer';
    public const BASE_URL = 'https://open.bigmodel.cn/api/paas/v4';
    private const MAX_CACHE = 128;
    /** @var array<string,array<string,mixed>> */
    private array $cache = [];
    private int $callCount = 0;

    public function __construct(
        private readonly array $config,
        private readonly string $remoteModel,
        private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]),
        private readonly ProviderEndpointResolver $endpoints = new HttpsProviderEndpointResolver(),
        private readonly ?MeetingSummaryTokenCountLedger $ledger = null,
    ) {
        if ($remoteModel !== 'glm-5.3-flash'
            || rtrim((string) ($config['base_url'] ?? ''), '/') !== self::BASE_URL) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_CAPACITY_UNAVAILABLE', '该模型尚未登记可核验的会议容量与分词接口，请管理员登记当前部署后重试');
        }
    }

    /** @param list<array{role:string,content:string}> $messages @return array<string,mixed> */
    public function count(array $messages): array
    {
        if (count($messages) !== 2 || !array_is_list($messages)
            || array_column($messages, 'role') !== ['system', 'user']) $this->invalid();
        foreach ($messages as $message) {
            if (array_diff(array_keys($message), ['role', 'content']) !== []
                || !is_string($message['content'] ?? null)
                || preg_match('//u', $message['content']) !== 1
                || mb_strlen($message['content'], 'UTF-8') > 32000) $this->invalid();
        }
        $digest = RequestPayloadFingerprint::fingerprint($messages, []);
        if (isset($this->cache[$digest])) return $this->cache[$digest];
        if ($this->ledger === null) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_AUDIT_UNAVAILABLE', '会议分词预检的调用审计与费用记录尚未接通，未发送分词或摘要调用；请管理员完成分词调用账本配置');
        }
        $cached = $this->ledger->find($digest);
        if ($cached !== null) return $cached;
        $config = (new OpenAiCompatibleExecutionConfigPolicy())->normalize($this->config);
        $endpoint = $this->endpoints->chatCompletions($config);
        if ($endpoint->url !== self::BASE_URL . '/chat/completions'
            || !function_exists('curl_init') || !defined('CURLOPT_RESOLVE')
            || $endpoint->curlResolveEntry === null) $this->invalid();
        $started = $this->ledger->begin([
            'counter' => 'bigmodel_tokenizer@1', 'endpoint' => self::ENDPOINT,
            'remote_model' => $this->remoteModel, 'input_sha256' => $digest,
            'metering_unit' => 'external_request', 'metering_quantity' => '1',
            'pricing_state' => 'unpriced', 'metered_amount' => null, 'currency' => null,
        ]);
        if (is_array($started['cached_receipt'] ?? null)) return $started['cached_receipt'];
        $eventId = $started['started_event_id'] ?? null;
        $requestId = $started['request_id'] ?? null;
        if (!is_int($eventId) || $eventId < 1 || !is_string($requestId)
            || strlen($requestId) < 6 || strlen($requestId) > 64) {
            throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_AUDIT_UNAVAILABLE', '分词调用未能记录不可变账本，未发送新的分词或摘要调用');
        }
        ++$this->callCount;
        $outcome = ['state' => 'ambiguous', 'http_status' => null];
        $body = null;
        try {
            $body = new BoundedEmbeddingResponseStream(16384);
            try {
                $response = $this->client->request('POST', self::ENDPOINT, [
                    'json' => ['model' => $this->remoteModel, 'messages' => $messages, 'request_id' => $requestId],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $config['api_key'],
                        'Accept' => 'application/json', 'Content-Type' => 'application/json',
                    ],
                    'timeout' => 30, 'connect_timeout' => 10,
                    'allow_redirects' => false, 'proxy' => '', 'http_errors' => false,
                    'curl' => [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry]], 'sink' => $body,
                ]);
            } catch (GuzzleException) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_UNAVAILABLE', '会议分词预检失败，尚未发送新的摘要模型调用；请确认服务商连接与分词权限后重试');
            }
            $outcome = ['state' => $response->getStatusCode() === 200 ? 'ambiguous' : 'rejected',
                'http_status' => $response->getStatusCode()];
            if ($response->getStatusCode() !== 200 || $body->exceeded()) $this->invalid();
            $body->rewind();
            try {
                $value = json_decode($body->getContents(), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->invalid();
            }
            $usage = is_array($value) ? ($value['usage'] ?? null) : null;
            $tokens = is_array($usage) ? ($usage['prompt_tokens'] ?? null) : null;
            if (!is_int($tokens) || $tokens < 1 || $tokens > 1000000
                || ($usage['total_tokens'] ?? null) !== $tokens
                || !is_string($value['id'] ?? null) || $value['id'] === ''
                || strlen($value['id']) > 160
                || (isset($value['request_id']) && $value['request_id'] !== $requestId)) $this->invalid();
            $receipt = [
                'counter' => 'bigmodel_tokenizer@1', 'remote_model' => $this->remoteModel,
                'input_sha256' => $digest, 'prompt_tokens' => $tokens,
                'request_id' => $requestId, 'provider_request_id' => $value['id'],
                'pricing_state' => 'unpriced', 'external_request_event_id' => $eventId,
            ];
            $outcome = ['state' => 'succeeded', 'http_status' => 200, 'capacity_receipt' => $receipt];

        } finally {
            $body?->close();
            $this->ledger->finish($eventId, $outcome);
        }
        // Only a durably appended success can enter the in-process cache.
        if (count($this->cache) >= self::MAX_CACHE) array_shift($this->cache);
        $this->cache[$digest] = $receipt;
        return $receipt;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    public function persistedCallCount(): int
    {
        return $this->ledger?->countRequests() ?? 0;
    }

    private function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_TOKEN_COUNT_UNAVAILABLE', '服务商未返回可核验的完整消息 Token 数，请管理员确认当前模型的分词接口支持后重试');
    }
}
