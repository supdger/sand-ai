<?php

declare(strict_types=1);

namespace app\Mcp\Identity;

use app\Api\Support\ApiProblem;
use app\Api\Support\IdentityContextProblem;
use app\Identity\Contract\IdentityContext;
use app\Identity\Contract\IdentityContextException;
use app\Identity\Infrastructure\HostSandIamIdentityContextProvider;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;

/**
 * Allocate inside one MCP HTTP handler. Protocol sessions never own workload identity.
 * Discovery checks current service actions only; tool callbacks still use the existing
 * public Controllers and their Gateway resource authorization, budget and usage paths.
 */
final class McpInvocationContext
{
    private readonly string $signedContext;
    private readonly string $sourceIp;
    private readonly string $requestId;
    private readonly HostSandIamIdentityContextProvider $contexts;

    /** @var array<string,list<string>> */
    private array $toolActions = [];
    private ?IdentityContext $verifiedScope = null;
    private bool $authenticated = false;

    public function __construct(Request $request)
    {
        $authorization = trim((string) $request->header('authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')
            || trim(substr($authorization, 7)) === ''
            || strlen($authorization) > 65543) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_AUTHENTICATION_FAILED'));
        }
        $this->signedContext = trim(substr($authorization, 7));
        $this->sourceIp = $request->getRemoteIp();
        $this->requestId = RequestAuditId::ensure($request);
        $this->contexts = new HostSandIamIdentityContextProvider();
    }

    /** @param array<string,list<string>> $toolActions Server-owned registration, never MCP arguments or signed claims. */
    public function authenticate(array $toolActions): void
    {
        if ($this->authenticated || $this->toolActions !== []) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'MCP request authorization has already been initialized');
        }
        if ($toolActions === [] || count($toolActions) > 256) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'MCP service actions are not configured');
        }
        foreach ($toolActions as $name => $actions) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $name) !== 1
                || !is_array($actions) || !array_is_list($actions) || $actions === []
                || count($actions) > 32) {
                throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'MCP service action mapping is invalid');
            }
            $seen = [];
            foreach ($actions as $action) {
                if (!is_string($action) || preg_match('/\Asand_ai\.[a-z][a-z0-9_.]{1,95}\z/D', $action) !== 1
                    || isset($seen[$action])) {
                    throw new ApiProblem('SAND_AI_CONFIGURATION_NOT_PUBLISHED', 'MCP service action mapping is invalid');
                }
                $seen[$action] = true;
            }
        }
        $this->toolActions = $toolActions;
        $permitted = false;
        foreach ($this->toolActions as $actions) {
            if ($this->currentActionsAllowed($actions)) $permitted = true;
        }
        if (!$permitted || $this->verifiedScope === null) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN'));
        }
        $this->authenticated = true;
    }

    /** Re-check live IAM action grants before exposing this tool's metadata. No invocation quota is allocated here. */
    public function allowed(string $toolName): bool
    {
        $this->requireInitialized();
        $actions = $this->toolActions[$toolName] ?? null;
        return $actions !== null && $this->currentActionsAllowed($actions);
    }

    /** Action admission only. Resource authorization must occur in the existing business Controller/Gateway. */
    public function requireTool(string $name): void
    {
        if (!$this->allowed($name)) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN'));
        }
    }

    /** An opaque protocol-session ownership key; neither raw context nor administrative login belongs in the session. */
    public function sessionScope(): string
    {
        $this->requireInitialized();
        $permitted = false;
        foreach ($this->toolActions as $actions) {
            if ($this->currentActionsAllowed($actions)) {
                $permitted = true;
                break;
            }
        }
        if (!$permitted || $this->verifiedScope === null) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_SERVICE_ACTION_FORBIDDEN'));
        }
        $identity = $this->verifiedScope;
        return hash('sha256', json_encode([
            'version' => 1,
            'organization_id' => $identity->organizationId,
            'application_id' => $identity->applicationId,
            'environment_id' => $identity->environmentId,
            'workload_client_id' => $identity->workloadClientId,
            'audience' => $identity->audience,
            'context_sha256' => hash('sha256', $this->signedContext),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $actions */
    private function currentActionsAllowed(array $actions): bool
    {
        foreach ($actions as $action) {
            try {
                $identity = $this->contexts->requireSignedContext(
                    $this->signedContext, $action, $this->sourceIp, $this->requestId,
                );
                $this->assertSameScope($identity);
            } catch (IdentityContextException $exception) {
                // An absent action hides only its tool. Invalid/revoked/expired identity
                // and unavailable IAM fail the whole request instead of an empty-success list.
                if ($exception->errorCode === 'SAND_AI_SERVICE_ACTION_FORBIDDEN') return false;
                throw IdentityContextProblem::from($exception);
            }
        }
        return true;
    }

    private function assertSameScope(IdentityContext $identity): void
    {
        if ($this->verifiedScope === null) {
            $this->verifiedScope = $identity;
            return;
        }
        $expected = $this->verifiedScope;
        if ($identity->organizationId !== $expected->organizationId
            || $identity->applicationId !== $expected->applicationId
            || $identity->environmentId !== $expected->environmentId
            || $identity->workloadClientId !== $expected->workloadClientId
            || $identity->audience !== $expected->audience
            || $identity->expiresAt !== $expected->expiresAt) {
            throw new IdentityContextException('SAND_AI_INVOCATION_SCOPE_FORBIDDEN');
        }
    }

    private function requireInitialized(): void
    {
        if (!$this->authenticated) {
            throw IdentityContextProblem::from(new IdentityContextException('SAND_AI_IDENTITY_CONTEXT_UNAVAILABLE'));
        }
    }

    /** Keep secret request snapshots out of diagnostics and accidental serialization. */
    public function __debugInfo(): array
    {
        return ['authenticated' => $this->authenticated, 'request_id' => $this->requestId];
    }

    public function __serialize(): array
    {
        throw new \LogicException('MCP workload request identity cannot be serialized');
    }
}
