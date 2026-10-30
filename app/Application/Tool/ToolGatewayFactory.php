<?php

declare(strict_types=1);

namespace app\Application\Tool;

/**
 * Production composition always uses the strict public HTTPS policy. The
 * acceptance fixture is discovered by class name, never by request input or
 * an application environment switch.
 */
final class ToolGatewayFactory
{
    public static function admin(): ToolAdminGateway
    {
        $fixture = 'tests\\SandAi\\Fixtures\\ToolAcceptanceGatewayFactory';
        if (class_exists($fixture)) {
            /** @var ToolAdminGateway $gateway */
            $gateway = $fixture::admin();
            return $gateway;
        }

        return new ToolAdminGateway();
    }

    public static function run(): ToolRunGateway
    {
        $fixture = 'tests\\SandAi\\Fixtures\\ToolAcceptanceGatewayFactory';
        if (class_exists($fixture)) {
            /** @var ToolRunGateway $gateway */
            $gateway = $fixture::run();
            return $gateway;
        }

        return new ToolRunGateway();
    }

    public static function mcpAdmin(): McpToolAdminGateway
    {
        $fixture = 'tests\\SandAi\\Fixtures\\ToolAcceptanceGatewayFactory';
        if (class_exists($fixture) && method_exists($fixture, 'mcpAdmin')) {
            /** @var McpToolAdminGateway $gateway */
            $gateway = $fixture::mcpAdmin();
            return $gateway;
        }
        return new McpToolAdminGateway();
    }
}
