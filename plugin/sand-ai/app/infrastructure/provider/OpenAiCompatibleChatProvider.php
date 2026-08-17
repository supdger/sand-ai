<?php

declare(strict_types=1);

namespace plugin\SandAi\app\infrastructure\provider;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use plugin\SandAi\app\api\support\ApiProblem;

final class OpenAiCompatibleChatProvider
{
    public function __construct(private readonly ClientInterface $client = new Client())
    {
    }

    /** @param array<string, mixed> $config @param list<array{role: string, content: string}> $messages @return array{content: string, input_tokens: int, output_tokens: int} */
    public function complete(array $config, string $remoteModel, array $messages, int $timeoutMs): array
    {
        $endpoint = $this->endpoint($config);
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        if ($apiKey === '' || trim($remoteModel) === '') {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider configuration is incomplete');
        }
        $headers = ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'];
        foreach (($config['extra_headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value) && preg_match('/^[A-Za-z0-9-]+$/', $name) === 1) {
                $headers[$name] = $value;
            }
        }
        try {
            $response = $this->client->request('POST', $endpoint, [
                'headers' => $headers,
                'json' => ['model' => $remoteModel, 'messages' => $messages, 'stream' => false],
                'timeout' => max(1, min($timeoutMs / 1000, 120)),
                'connect_timeout' => min(10, max(1, $timeoutMs / 1000)),
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', 'Provider network request failed', true);
        }
        $status = $response->getStatusCode();
        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned an invalid response', $status >= 500);
        }
        if ($status < 200 || $status >= 300) {
            if ($status === 429) {
                throw new ApiProblem('SAND_AI_PROVIDER_RATE_LIMITED', 'Provider rate limit reached', true);
            }
            throw new ApiProblem($status >= 500 ? 'SAND_AI_PROVIDER_UNAVAILABLE' : 'SAND_AI_PROVIDER_REJECTED', 'Provider rejected the request', $status >= 500);
        }
        $choice = is_array($payload['choices'][0] ?? null) ? $payload['choices'][0] : null;
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : null;
        $content = $message['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new ApiProblem('SAND_AI_PROVIDER_FAILURE', 'Provider returned no completion content', true);
        }
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return [
            'content' => $content,
            'input_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($usage['completion_tokens'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $config */
    private function endpoint(array $config): string
    {
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider base_url must be an HTTPS API base URL');
        }

        return rtrim($baseUrl, '/') . '/chat/completions';
    }
}
