<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;

/** Authenticated metadata reads; this transport never sends a model request. */
final class OpenRouterBillingCurlTransport implements BillingHttpTransport
{
    public function get(string $path, array $query, string $secret, ?string $organization = null): array
    {
        if ($organization !== null || $secret === '' || preg_match('/[\r\n]/', $secret) === 1) {
            throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'OpenRouter billing credential is invalid');
        }
        $url = match ($path) {
            '/api/v1/key' => $query === [] ? 'https://openrouter.ai/api/v1/key' : null,
            '/api/v1/generation' => array_keys($query) === ['id']
                && is_string($query['id']) && strlen($query['id']) <= 128
                && preg_match('/\Agen-[0-9A-Za-z-]+\z/D', $query['id']) === 1
                    ? 'https://openrouter.ai/api/v1/generation?id=' . rawurlencode($query['id']) : null,
            default => null,
        };
        if ($url === null) {
            throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'Unsupported OpenRouter billing read');
        }
        $curl = curl_init($url);
        if ($curl === false) throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'Billing transport is unavailable');
        $buffer = new BoundedResponseBuffer(LosslessJsonDecoder::MAX_BYTES);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $buffer->append($chunk),
        ]);
        $completed = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($buffer->exceeded()) $buffer->body();
        if ($completed === false) throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'Billing transport is unavailable');
        return ['status' => $status, 'body' => $buffer->body()];
    }
}
