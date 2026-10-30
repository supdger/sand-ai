<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

use app\Api\Support\ApiProblem;

/** The loopback wire configuration is shared by deployment evidence and the OCR send. */
final class LocalOcrExecutionConfig
{
    /** @return array{base_url:string,max_file_bytes:int,timeout_ms:int,response_max_bytes:int} */
    public static function configuration(): array
    {
        $input = (array) config('sand_ai_ocr.local_paddle_ocr', []);
        $baseUrl = rtrim((string) ($input['base_url'] ?? ''), '/');
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? trim(strtolower((string) ($parts['host'] ?? '')), '[]') : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http'
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ApiProblem('SAND_AI_OCR_UNAVAILABLE', 'The local OCR service URL must be a loopback HTTP address');
        }
        $timeout = max(1000, (int) ($input['timeout_ms'] ?? 60000));
        $responseBytes = (int) ($input['response_max_bytes'] ?? 8388608);
        $fileBytes = (int) ($input['max_file_bytes'] ?? 52428800);
        if ($timeout > 300000 || $responseBytes < 1024 || $responseBytes > 16777216 || $fileBytes < 1) {
            throw new ApiProblem('SAND_AI_OCR_UNAVAILABLE', 'The local OCR connection bounds are invalid');
        }
        return ['base_url' => $baseUrl, 'max_file_bytes' => $fileBytes, 'timeout_ms' => $timeout,
            'response_max_bytes' => $responseBytes];
    }

    /** Private deployment evidence, never a public endpoint or runtime path. */
    public static function fingerprint(): array
    {
        $key = (string) env('SAND_AI_CONFIG_KEY', '');
        if (strlen($key) < 32) throw new ApiProblem('SAND_AI_PROVIDER_CONFIG_INVALID', 'Provider execution fingerprint key is unavailable');
        return ['version' => 'local_ocr_execution_config@1', 'hmac_sha256' => hash_hmac('sha256',
            json_encode(['driver_code' => 'local_paddle_ocr', 'wire' => self::configuration()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            hash_hmac('sha256', 'sand-ai-local-ocr-execution-fingerprint@1', $key, true))];
    }
}
