<?php

declare(strict_types=1);

namespace app\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Preserve JSON object shapes lost by the SDK's associative body decode.
 *
 * @implements RequestHandlerInterface<\Mcp\Schema\Result\CallToolResult|\Mcp\Schema\Result\InputRequiredResult>
 */
final class McpToolArgumentHandler implements RequestHandlerInterface
{
    /** @var array<int,mixed> Current HTTP frames only; never persisted. */
    private array $frames;

    public function __construct(string $body, private readonly CallToolHandler $handler)
    {
        try {
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            $this->frames = is_array($decoded) ? $decoded : [$decoded];
        } catch (\JsonException) {
            // Parse-error handling belongs to the SDK, before this handler.
            $this->frames = [];
        }
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if (!$request instanceof CallToolRequest) {
            return Error::forInvalidParams('Tool arguments are unavailable.', $request->getId());
        }
        foreach ($this->frames as $index => $frame) {
            if (!$frame instanceof \stdClass || ($frame->method ?? null) !== 'tools/call'
                || ($frame->id ?? null) !== $request->getId()) {
                continue;
            }
            // Consume in original order, including repeated IDs in an SDK-handled batch.
            unset($this->frames[$index]);
            $params = $frame->params ?? null;
            if (!$params instanceof \stdClass || ($params->name ?? null) !== $request->name) {
                return Error::forInvalidParams('Tool arguments do not match the original request.', $request->getId());
            }
            $arguments = property_exists($params, 'arguments') ? $params->arguments : new \stdClass();
            if (!$arguments instanceof \stdClass) {
                return Error::forInvalidParams('Tool arguments must be a JSON object.', $request->getId());
            }
            $decodedArguments = json_decode(json_encode($arguments, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
            if ($decodedArguments !== $request->arguments) {
                return Error::forInvalidParams('Tool arguments do not match the original request.', $request->getId());
            }
            $preserved = (array) $arguments;
            // Callbacks take an array envelope; nested business objects remain stdClass.
            if (($preserved['input_body'] ?? null) instanceof \stdClass) {
                $preserved['input_body'] = (array) $preserved['input_body'];
            }
            $message = $request->jsonSerialize();
            $message['params']['arguments'] = $preserved;
            // The SDK owns validation, callback invocation and response formatting.
            return $this->handler->handle(CallToolRequest::fromArray($message), $session);
        }
        return Error::forInvalidParams('Tool arguments do not match the original request.', $request->getId());
    }

    public function __debugInfo(): array
    {
        return [];
    }

    public function __serialize(): array
    {
        throw new \LogicException('MCP tool arguments are request-local.');
    }
}
