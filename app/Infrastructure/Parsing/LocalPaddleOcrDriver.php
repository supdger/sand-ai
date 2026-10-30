<?php

declare(strict_types=1);

namespace app\Infrastructure\Parsing;

/**
 * Calls the locally bound PaddleOCR sidecar with a temporary private file.
 * The sidecar contract deliberately contains only extracted blocks and never
 * storage object locations, credentials, or a public download URL.
 */
final class LocalPaddleOcrDriver implements ParseDriver
{
    public function code(): string
    {
        return 'local_paddle_ocr';
    }

    public function version(): string
    {
        return 'http-sidecar';
    }

    public function parse(ParseInput $input): ParseResult
    {
        if (!in_array(strtolower($input->extension), ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            throw new ParseException('SAND_AI_PARSE_UNSUPPORTED', 'The local OCR driver does not support this file type');
        }
        if (!function_exists('curl_init')) {
            throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'The local OCR HTTP client is unavailable');
        }
        if (!is_file($input->path) || !is_readable($input->path)) {
            throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR input file is unavailable');
        }

        $configuration = LocalOcrExecutionConfig::configuration();
        $size = filesize($input->path);
        if ($size === false || $size > $configuration['max_file_bytes']) {
            throw new ParseException('SAND_AI_FILE_INVALID', 'The OCR input exceeds the local OCR size limit');
        }
        $baseUrl = $configuration['base_url'];
        $timeoutMs = $configuration['timeout_ms'];
        $responseMaxBytes = $configuration['response_max_bytes'];

        $handle = curl_init($baseUrl . '/v1/ocr');
        if ($handle === false) {
            throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'The local OCR client could not initialize');
        }
        $body = '';
        $tooLarge = false;
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($input->path, $input->mediaType, 'private-upload.' . strtolower($input->extension)),
                ],
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body, &$tooLarge, $responseMaxBytes): int {
                    $bytes = strlen($chunk);
                    if ($bytes > $responseMaxBytes - strlen($body)) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return $bytes;
                },
            ]);
            // Pin localhost to loopback rather than trusting host-file/DNS overrides.
            $parts = parse_url($baseUrl);
            if (strtolower((string) $parts['host']) === 'localhost') {
                curl_setopt($handle, CURLOPT_RESOLVE, ['localhost:' . (int) ($parts['port'] ?? 80) . ':127.0.0.1']);
            }
            if ($input->onOcrDispatch === null) {
                throw new ParseException('SAND_AI_OCR_USAGE_UNAVAILABLE', 'OCR dispatch metering boundary is unavailable');
            }
            if ($configuration !== LocalOcrExecutionConfig::configuration()) {
                throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'The OCR wire configuration changed before dispatch');
            }
            ($input->onOcrDispatch)('dispatch_started', null);
            try {
                $completed = curl_exec($handle);
            } catch (\Throwable $exception) {
                ($input->onOcrDispatch)('uncertain', null);
                throw new ParseException('SAND_AI_OCR_DISPATCH_AMBIGUOUS', 'OCR request result is uncertain', $exception);
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($completed !== false && $status >= 100 && $status <= 599) {
                ($input->onOcrDispatch)('response_known', $status);
            } else {
                ($input->onOcrDispatch)('uncertain', null);
                throw new ParseException(
                    'SAND_AI_OCR_DISPATCH_AMBIGUOUS',
                    $tooLarge ? 'OCR response exceeded its limit; review the original attempt before retrying'
                        : 'OCR request result is uncertain; review the original attempt before retrying',
                );
            }
            if ($tooLarge) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR response exceeds the configured size limit');
            }
            if ($completed === false || $status === 0 || $status >= 500) {
                throw new ParseException('SAND_AI_OCR_UNAVAILABLE', 'The local OCR service is unavailable');
            }
            if ($status < 200 || $status >= 300) {
                throw new ParseException('SAND_AI_PARSE_FAILED', 'The local OCR service rejected the file');
            }
        } finally {
            curl_close($handle);
        }

        return (new LocalPaddleOcrResponse())->normalize(json_decode((string) $body, true), $this->code(), $this->version());
    }

}
