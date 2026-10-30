<?php

declare(strict_types=1);

namespace app\Application\Tool;

use NeuronAI\MCP\McpClient;

/** Schema-approved nullable arguments must survive the official MCP request path. */
final class NeuronReadonlyMcpClient extends McpClient
{
    public function callTool(string $toolName, array $arguments = []): array
    {
        return $this->request('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ]);
    }
}
