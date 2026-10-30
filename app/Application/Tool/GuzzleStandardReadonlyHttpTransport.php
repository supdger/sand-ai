<?php

declare(strict_types=1);

namespace app\Application\Tool;

use app\Api\Support\ApiProblem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;

/** Fixed POST/JSON, direct DNS-pinned transport. Redirects, proxy inheritance and retries are disabled. */
final class GuzzleStandardReadonlyHttpTransport implements StandardReadonlyHttpTransport
{
    public function request(VerifiedToolEndpoint $endpoint, array $headers, array $envelope, int $timeoutMs, int $responseMaxBytes, callable $onDispatch): ToolHttpResponse
    {
        if (!defined('CURLOPT_RESOLVE') || !function_exists('curl_exec') || !function_exists('curl_init')
            || $timeoutMs < 100 || $timeoutMs > 30000 || $responseMaxBytes < 1024 || $responseMaxBytes > 1048576) {
            throw new ApiProblem('SAND_AI_TOOL_CONFIGURATION_DRIFTED', 'Published tool transport bounds are invalid');
        }
        $sink = new BoundedToolResponseStream($responseMaxBytes);
        $onDispatch();
        $started = hrtime(true);
        $tooLarge = false;
        try {
            $response = (new Client(['handler' => new CurlHandler()]))->request('POST', $endpoint->url(), [
                'headers' => $headers + ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => 'SandAI-Readonly-Tool/1.0'],
                'json' => $envelope,
                'sink' => $sink,
                'timeout' => $timeoutMs / 1000,
                'connect_timeout' => min(5, $timeoutMs / 1000),
                'allow_redirects' => false,
                'proxy' => '',
                'http_errors' => false,
                'curl' => [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry()]],
                'on_headers' => static function ($response) use ($responseMaxBytes, &$tooLarge): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (!ctype_digit($length) || (int) $length > $responseMaxBytes)) {
                        $tooLarge = true;
                        throw new \RuntimeException('Tool response exceeds configured limit');
                    }
                },
            ]);
        } catch (GuzzleException $exception) {
            if ($tooLarge || $sink->exceeded()) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_TOO_LARGE', 'External tool response exceeded its published limit');
            $context = $exception instanceof RequestException ? $exception->getHandlerContext() : [];
            if (($context['errno'] ?? null) === 28) throw new ApiProblem('SAND_AI_TOOL_TIMEOUT', 'External tool request timed out');
            throw new ApiProblem('SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'External tool request result is uncertain');
        } catch (\Throwable) {
            if ($tooLarge || $sink->exceeded()) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_TOO_LARGE', 'External tool response exceeded its published limit');
            throw new ApiProblem('SAND_AI_TOOL_DISPATCH_AMBIGUOUS', 'External tool request result is uncertain');
        }
        if ($sink->exceeded()) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_TOO_LARGE', 'External tool response exceeded its published limit');
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > $responseMaxBytes) throw new ApiProblem('SAND_AI_TOOL_RESPONSE_TOO_LARGE', 'External tool response exceeded its published limit');
        }
        return new ToolHttpResponse(
            $response->getStatusCode(),
            $body,
            max(0, (int) ((hrtime(true) - $started) / 1_000_000)),
            $response->getHeaders(),
        );
    }
}
