<?php

declare(strict_types=1);

namespace app\Mcp;

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use support\Request;
use support\Response;

/** Pure HTTP value conversion; protocol handling remains entirely in mcp/sdk. */
final class McpHttpBridge
{
    public static function request(Request $request): ServerRequestInterface
    {
        $headers = $request->header();
        $headers = is_array($headers) ? $headers : [];
        return new ServerRequest(
            $request->method(),
            'https://' . ($request->host() ?? 'localhost') . $request->uri(),
            $headers,
            $request->rawBody(),
            '1.1',
            ['REMOTE_ADDR' => $request->getRemoteIp()],
        );
    }

    public static function response(ResponseInterface $response): Response
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $headers['Cache-Control'] = 'no-store, private';
        $headers['Pragma'] = 'no-cache';
        $headers['X-Content-Type-Options'] = 'nosniff';
        return new Response($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
