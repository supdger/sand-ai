<?php

declare(strict_types=1);

namespace app\Infrastructure\Billing;

use app\Api\Support\ApiProblem;

final class OpenAiBillingCurlTransport implements BillingHttpTransport
{
    public function __construct(private ?OpenAiBillingEndpointResolver $endpoint = null)
    {
        $this->endpoint ??= new OfficialOpenAiBillingEndpointResolver();
    }

    public function get(string $path, array $query, string $secret, ?string $organization = null): array
    {
        if ($organization === null || preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $organization) !== 1) {
            throw new ApiProblem('SAND_AI_BILLING_CONFIG_INVALID', 'billing organization invalid');
        }
        $curl = curl_init($this->buildUrl($path, $query));
        if ($curl === false) {
            throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'billing transport unavailable');
        }
        $buffer = new BoundedResponseBuffer(LosslessJsonDecoder::MAX_BYTES);
        $responseOrganization = null;
        $organizationHeaderSeen = false;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'OpenAI-Organization: ' . $organization, 'Accept: application/json'],
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseOrganization, &$organizationHeaderSeen): int {
                if (stripos($header, 'HTTP/') === 0) {
                    $responseOrganization = null;
                    $organizationHeaderSeen = false;
                }
                if (stripos($header, 'openai-organization:') === 0) {
                    $value = trim(substr($header, strlen('openai-organization:')));
                    if ($organizationHeaderSeen || preg_match('/^org-[A-Za-z0-9_-]{1,120}$/D', $value) !== 1) {
                        $responseOrganization = null;
                    } else {
                        $responseOrganization = $value;
                    }
                    $organizationHeaderSeen = true;
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $buffer->append($chunk),
        ]);
        $completed = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($buffer->exceeded()) {
            $buffer->body();
        }
        if ($completed === false) {
            throw new ApiProblem('SAND_AI_BILLING_UNAVAILABLE', 'billing transport unavailable');
        }

        return ['status' => $status, 'body' => $buffer->body(), 'organization' => $responseOrganization];
    }

    public function buildUrl(string $path, array $query): string
    {
        if ($path !== '/v1/organization/costs') {
            throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'unsupported billing path');
        }
        $pairs = [];
        $allowedKeys = ['start_time', 'end_time', 'bucket_width', 'limit', 'page', 'group_by'];
        foreach ($query as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
                throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'unsupported billing query');
            }
            $values = is_array($value) ? $value : [$value];
            if ($values === []) {
                throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'unsupported billing query');
            }
            foreach ($values as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new ApiProblem('SAND_AI_BILLING_REQUEST_INVALID', 'unsupported billing query');
                }
                // OpenAI uses form/explode query arrays: repeated `group_by`, never `group_by[]`.
                $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
            }
        }

        return rtrim($this->endpoint->origin(), '/') . $path . '?' . implode('&', $pairs);
    }
}
