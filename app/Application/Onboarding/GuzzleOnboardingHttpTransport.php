<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Provider\BoundedProviderResponseStream;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;

/**
 * Transport is deliberately small: URL safety is decided before this class is
 * reached and redirects remain disabled so a validated public endpoint cannot
 * redirect an onboarding request to an internal network address.
 */
final class GuzzleOnboardingHttpTransport implements OnboardingHttpTransport
{
    private const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private readonly ClientInterface $client = new Client(['handler' => new CurlHandler()]),
    ) {
    }

    public function request(string $method, VerifiedEndpoint $endpoint, string $path, array $headers, ?array $json, int $timeoutMs): OnboardingHttpResponse
    {
        if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE')
            || !($this->client instanceof Client) || !($this->client->getConfig('handler') instanceof CurlHandler)) {
            throw new ApiProblem('SAND_AI_ONBOARDING_CONNECTION_UNVERIFIABLE', '当前运行环境无法安全固定服务商连接地址');
        }
        $responseTooLarge = false;
        $responseSink = new BoundedProviderResponseStream(self::MAX_RESPONSE_BYTES);
        try {
            try {
                $response = $this->client->request($method, $endpoint->baseUrl . $path, [
                    'headers' => $headers,
                    'json' => $json,
                    'timeout' => max(1, min($timeoutMs / 1000, 120)),
                    'connect_timeout' => min(5, max(1, $timeoutMs / 1000)),
                    'allow_redirects' => false,
                    'verify' => true,
                    // Do not inherit HTTP(S)_PROXY: CURLOPT_RESOLVE must govern
                    // the actual direct connection that the policy validated.
                    'proxy' => '',
                    'http_errors' => false,
                    'decode_content' => true,
                    'sink' => $responseSink,
                    'on_headers' => static function ($response) use (&$responseTooLarge): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (!ctype_digit($length) || (int) $length > self::MAX_RESPONSE_BYTES)) {
                            $responseTooLarge = true;
                            throw new \RuntimeException('Onboarding response exceeds the permitted size');
                        }
                    },
                    'curl' => [CURLOPT_RESOLVE => [$endpoint->curlResolveEntry()]],
                ]);
            } catch (GuzzleException $exception) {
                if ($responseTooLarge || $responseSink->limitExceeded()) {
                    throw new ApiProblem('SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE', '服务商响应超过首次开通允许的大小');
                }
                if (($exception instanceof RequestException || $exception instanceof ConnectException)
                    && ($exception->getHandlerContext()['errno'] ?? null) === 28) {
                    throw new ApiProblem(
                        'SAND_AI_PROVIDER_UNAVAILABLE',
                        $method === 'POST'
                            ? '模型验证超时，请求可能已发送并产生费用；请检查服务商记录后决定是否再次验证'
                            : '发现模型连接超时；请检查网络和服务地址后重试，也可手动填写服务商提供的模型标识',
                        true,
                    );
                }
                throw new ApiProblem('SAND_AI_PROVIDER_UNAVAILABLE', '服务商连接暂时不可用，请检查网络和服务地址后重试', true);
            }

            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (!ctype_digit($length) || (int) $length > self::MAX_RESPONSE_BYTES)) {
                throw new ApiProblem('SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE', '服务商响应超过首次开通允许的大小');
            }
            $stream = $response->getBody();
            $body = '';
            while (!$stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                    throw new ApiProblem('SAND_AI_ONBOARDING_RESPONSE_TOO_LARGE', '服务商响应超过首次开通允许的大小');
                }
            }

            return new OnboardingHttpResponse($response->getStatusCode(), $body);
        } finally {
            $responseSink->close();
        }
    }
}
