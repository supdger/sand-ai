<?php

declare(strict_types=1);

namespace app\Infrastructure\Provider;

use app\Api\Support\ApiProblem;

/** One strict interpretation of every field consumed by the inference adapter. */
final class OpenAiCompatibleExecutionConfigPolicy
{
    public const DEFAULT_RESPONSE_MAX_BYTES = 1048576;
    public const MAX_RESPONSE_MAX_BYTES = 8388608;
    /** @var list<string> */
    private const EXTRA_HEADER_ALLOWLIST = ['OpenAI-Organization', 'OpenAI-Project', 'X-Api-Key', 'X-Organization', 'X-Project'];

    /**
     * @param array<string,mixed> $config
     * @return array{base_url:string,api_key:string,extra_headers:array<string,string>,output_token_parameter:string,response_max_bytes:int,idempotency_mode:string,idempotency_header:?string}
     */
    public function normalize(array $config): array
    {
        $baseUrl = $this->requiredString($config, 'base_url', 2048);
        $apiKey = $this->requiredString($config, 'api_key', 1024);
        if (preg_match('/[\r\n]/', $apiKey) === 1) {
            throw $this->invalid();
        }
        $parts = parse_url($baseUrl);
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
        if ($parts === false || preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1
            || ($parts['scheme'] ?? null) !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || $host === 'metadata' || str_ends_with($host, '.metadata') || $host === 'metadata.google.internal'
            || !in_array(isset($parts['port']) ? $parts['port'] : 443, [443, 8443], true)) {
            throw $this->invalid();
        }
        $baseUrl = rtrim($baseUrl, '/');

        $headers = [];
        if (array_key_exists('extra_headers', $config)) {
            if (!is_array($config['extra_headers']) || count($config['extra_headers']) > 5) {
                throw $this->invalid();
            }
            foreach ($config['extra_headers'] as $name => $value) {
                if (!is_string($name) || !is_string($value) || !in_array($name, self::EXTRA_HEADER_ALLOWLIST, true)
                    || strlen($value) > 512 || preg_match('/[\r\n]/', $value) === 1) {
                    throw $this->invalid();
                }
                $headers[$name] = $value;
            }
            ksort($headers, SORT_STRING);
        }

        $outputTokenParameter = $this->optionalString($config, 'output_token_parameter', 'max_tokens');
        if (!in_array($outputTokenParameter, ['max_tokens', 'max_completion_tokens'], true)) {
            throw $this->invalid();
        }
        $responseMaxBytes = $config['response_max_bytes'] ?? self::DEFAULT_RESPONSE_MAX_BYTES;
        if (array_key_exists('response_max_bytes', $config) && !is_int($config['response_max_bytes'])) {
            throw $this->invalid();
        }
        if ($responseMaxBytes < 1024 || $responseMaxBytes > self::MAX_RESPONSE_MAX_BYTES) {
            throw $this->invalid();
        }
        $idempotencyMode = $this->optionalString($config, 'idempotency_mode', 'none');
        if (!in_array($idempotencyMode, ['none', 'provider_key'], true)) {
            throw $this->invalid();
        }
        $idempotencyHeader = null;
        if ($idempotencyMode === 'provider_key') {
            $idempotencyHeader = $this->optionalString($config, 'idempotency_header', 'Idempotency-Key');
            if (!in_array($idempotencyHeader, ['Idempotency-Key', 'X-Idempotency-Key'], true)) {
                throw $this->invalid();
            }
        } elseif (array_key_exists('idempotency_header', $config) && $config['idempotency_header'] !== null) {
            throw $this->invalid();
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'extra_headers' => $headers,
            'output_token_parameter' => $outputTokenParameter,
            'response_max_bytes' => $responseMaxBytes,
            'idempotency_mode' => $idempotencyMode,
            'idempotency_header' => $idempotencyHeader,
        ];
    }

    /** @param array<string,mixed> $config */
    private function requiredString(array $config, string $field, int $maximum): string
    {
        if (!array_key_exists($field, $config) || !is_string($config[$field])) {
            throw $this->invalid();
        }
        $value = trim($config[$field]);
        if ($value === '' || strlen($value) > $maximum) {
            throw $this->invalid();
        }
        return $value;
    }

    /** @param array<string,mixed> $config */
    private function optionalString(array $config, string $field, string $default): string
    {
        if (!array_key_exists($field, $config)) {
            return $default;
        }
        if (!is_string($config[$field])) {
            throw $this->invalid();
        }
        return $config[$field];
    }

    private function invalid(): ApiProblem
    {
        return new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider execution configuration is invalid');
    }
}
