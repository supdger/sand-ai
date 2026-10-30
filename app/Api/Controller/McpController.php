<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Identity\Infrastructure\RequestAuditId;
use app\Mcp\Identity\McpInvocationContext;
use app\Mcp\Identity\McpSessionScope;
use app\Mcp\McpHttpBridge;
use app\Mcp\McpToolArgumentHandler;
use app\Mcp\SandAiMcpTools;
use Composer\InstalledVersions;
use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Wire\CachePolicy;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Log\NullLogger;
use support\Request;
use support\Response;

/** A request-local MCP server exposing the same authorized SandAI business services. */
final class McpController
{
    public function handle(Request $request): Response
    {
        $requestId = RequestAuditId::ensure($request);
        // This service never returns a queued/cached result through a GET event stream.
        if (!in_array($request->method(), ['POST', 'DELETE'], true)
            || trim((string) $request->header('last-event-id', '')) !== '') {
            return new Response(405, ['Allow' => 'POST, DELETE', 'Cache-Control' => 'no-store']);
        }
        $session = null;
        try {
            $this->requireSdk();
            $maxBody = (int) config('sand_ai_mcp_server.max_body_bytes', 1048576);
            if ($maxBody < 1024 || $maxBody > 2097152) {
                throw new ApiProblem('SAND_AI_MCP_CONFIGURATION_INVALID', 'MCP service bounds are invalid');
            }
            if (strlen($request->rawBody()) > $maxBody) {
                return $this->error('SAND_AI_MCP_REQUEST_TOO_LARGE', false, $requestId, 413);
            }
            $identity = new McpInvocationContext($request);
            $identity->authenticate(SandAiMcpTools::actions());
            $session = new McpSessionScope($identity);
            $header = trim((string) $request->header('mcp-session-id', ''));
            $session->lock($header === '' ? null : $header);
            $logger = new NullLogger(); // The SDK may otherwise log tool input or response bodies.
            $registry = new Registry(logger: $logger);
            $referenceHandler = new ReferenceHandler();
            $version = config('plugin.sand-ai.app.version');
            if (!is_string($version) || $version === '') {
                throw new ApiProblem('SAND_AI_MCP_CONFIGURATION_INVALID', 'MCP service version is invalid');
            }
            $builder = Server::builder()
                ->setServerInfo('SandAI', $version)
                ->setLogger($logger)
                ->setRegistry($registry)
                ->setReferenceHandler($referenceHandler)
                ->addRequestHandler(new McpToolArgumentHandler(
                    $request->rawBody(), new CallToolHandler($registry, $referenceHandler, $logger),
                ))
                ->setProtocolVersion(ProtocolVersion::V2025_11_25)
                ->setModernVersions([ProtocolVersion::V2026_07_28])
                ->setCachePolicy(CachePolicy::none())
                ->withoutInputRequiredShim()
                ->setSession(sessionStore: $session->store(), gcProbability: 1, gcDivisor: 100);
            (new SandAiMcpTools($request, $identity))->register($builder);
            $factory = new HttpFactory();
            $allowedHosts = config('sand_ai_mcp_server.allowed_hosts', ['localhost', '127.0.0.1', '[::1]']);
            $allowedOrigins = config('sand_ai_mcp_server.allowed_origins', []);
            if (!is_array($allowedHosts) || $allowedHosts === [] || !is_array($allowedOrigins)) {
                throw new ApiProblem('SAND_AI_MCP_CONFIGURATION_INVALID', 'MCP service origin policy is invalid');
            }
            $transport = new StreamableHttpTransport(
                McpHttpBridge::request($request),
                responseFactory: $factory,
                streamFactory: $factory,
                logger: $logger,
                middleware: [
                    new CorsMiddleware(allowedOrigins: $allowedOrigins, allowedMethods: ['POST', 'DELETE']),
                    new DnsRebindingProtectionMiddleware($allowedHosts, $factory, $factory),
                ],
                maxBodyBytes: $maxBody,
            );
            return McpHttpBridge::response($builder->build()->run($transport));
        } catch (ApiProblem $problem) {
            $status = match ($problem->errorCode) {
                'SAND_AI_AUTHENTICATION_FAILED', 'SAND_AI_IDENTITY_CONTEXT_EXPIRED', 'SAND_AI_CREDENTIAL_REVOKED' => 401,
                'SAND_AI_SERVICE_ACTION_FORBIDDEN', 'SAND_AI_INVOCATION_SCOPE_FORBIDDEN',
                'SAND_AI_SERVICE_NETWORK_FORBIDDEN', 'SAND_AI_DATA_CLASS_FORBIDDEN' => 403,
                'SAND_AI_MCP_SESSION_BUSY' => 409,
                'SAND_AI_MCP_SESSION_INVALID', 'SAND_AI_VALIDATION_ERROR' => 400,
                default => 503,
            };
            return $this->error($problem->errorCode, $problem->retryable, $requestId, $status);
        } catch (\Throwable) {
            return $this->error('SAND_AI_MCP_SERVICE_UNAVAILABLE', true, $requestId, 503);
        } finally {
            if ($session !== null) $session->release();
        }
    }

    private function requireSdk(): void
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('mcp/sdk')
            || !version_compare((string) InstalledVersions::getVersion('mcp/sdk'), '0.8.1', '>=')
            || !class_exists(Server::class) || !class_exists(HttpFactory::class)) {
            throw new ApiProblem('SAND_AI_MCP_SDK_UNAVAILABLE', 'MCP service dependency is unavailable');
        }
    }

    private function error(string $code, bool $retryable, string $requestId, int $status): Response
    {
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private'];
        if ($status === 401) $headers['WWW-Authenticate'] = 'Bearer error="invalid_token"';
        return new Response($status, $headers, json_encode([
            'error_code' => $code, 'retryable' => $retryable, 'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR));
    }
}
