<?php

declare(strict_types=1);

namespace app\Application\Tool;

interface StandardReadonlyHttpTransport
{
    /**
     * @param array<string,string> $headers Constructed only from the managed connection revision.
     * @param array<string,mixed> $envelope Standard HTTP envelope or internally constructed MCP JSON-RPC message.
     * @param callable():void $onDispatch Marks the durable dispatch boundary before network I/O. Standard HTTP also records Usage there; MCP records one immutable aggregate after its protocol exchange sequence.
     */
    public function request(VerifiedToolEndpoint $endpoint, array $headers, array $envelope, int $timeoutMs, int $responseMaxBytes, callable $onDispatch): ToolHttpResponse;

}
