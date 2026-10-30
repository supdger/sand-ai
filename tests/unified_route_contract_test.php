<?php

declare(strict_types=1);

namespace Webman {
    final class Route
    {
        /** @var list<array{method:string,path:string,controller:string,action:string}> */
        public static array $routes = [];
        public static bool $defaultDisabled = false;
        private static array $prefixes = [];

        public static function get(string $path, array $handler): self { return self::add('GET', $path, $handler); }
        public static function post(string $path, array $handler): self { return self::add('POST', $path, $handler); }
        public static function delete(string $path, array $handler): self { return self::add('DELETE', $path, $handler); }
        public static function group(string $prefix, callable $routes): self
        {
            self::$prefixes[] = $prefix;
            try {
                $routes();
            } finally {
                array_pop(self::$prefixes);
            }
            return new self();
        }
        public static function disableDefaultRoute(string $controller): void { self::$defaultDisabled = true; }
        public function middleware(array $middleware): self { return $this; }

        private static function add(string $method, string $path, array $handler): self
        {
            $path = implode('', self::$prefixes) . $path;
            if (count($handler) !== 2 || !is_string($handler[0]) || !is_string($handler[1])) {
                throw new \RuntimeException("Invalid route handler: {$method} {$path}");
            }
            self::$routes[] = ['method' => $method, 'path' => $path, 'controller' => $handler[0], 'action' => $handler[1]];
            return new self();
        }
    }
}

namespace {
    use Webman\Route;

    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_file($root . '/plugin/sand-ai/config/route.php')) {
        throw new RuntimeException('Provide a SandAI package source directory');
    }
    require $root . '/plugin/sand-ai/config/route.php';
    if (!Route::$defaultDisabled) throw new RuntimeException('Default route not disabled');

    $seen = [];
    $external = $admin = 0;
    foreach (Route::$routes as $route) {
        $key = $route['method'] . ' ' . $route['path'];
        if (isset($seen[$key])) throw new RuntimeException("Duplicate route: {$key}");
        $seen[$key] = true;
        $controller = $route['controller'];
        if (str_starts_with($route['path'], '/api/sand-ai/')) {
            if (!str_starts_with($controller, 'app\\Api\\Controller\\')) {
                throw new RuntimeException("External route has plugin controller: {$key}");
            }
            $path = $root . '/' . str_replace('\\', '/', $controller) . '.php';
            $external++;
        } elseif (str_starts_with($route['path'], '/app/sand-ai/admin/')) {
            if (!str_starts_with($controller, 'plugin\\SandAi\\app\\admin\\controller\\')) {
                throw new RuntimeException("Admin route has application controller: {$key}");
            }
            $suffix = substr($controller, strlen('plugin\\SandAi\\'));
            $path = $root . '/plugin/sand-ai/' . str_replace('\\', '/', $suffix) . '.php';
            $admin++;
        } else {
            throw new RuntimeException("Unexpected SandAI route: {$key}");
        }
        if (!is_file($path)) throw new RuntimeException("Missing route controller: {$key}");
        $source = (string) file_get_contents($path);
        if (!preg_match('/\bfunction\s+' . preg_quote($route['action'], '/') . '\s*\(/', $source)) {
            throw new RuntimeException("Missing controller action: {$key}");
        }
    }
    if ($external < 10 || $admin < 10) throw new RuntimeException('Expected external and admin route families');
    if (is_dir($root . '/plugin/sand-ai/app/api') || is_dir($root . '/plugin/sand-ai/app/domain')) {
        throw new RuntimeException('Legacy plugin API runtime still present');
    }
    $setup = (string) file_get_contents($root . '/plugin/sand-ai/app/admin/controller/SetupController.php');
    if (!str_contains($setup, 'use app\\Application\\Capability\\CapabilityProfileGateway;')) {
        throw new RuntimeException('Admin setup does not consume application service');
    }
    printf("Unified routes passed: external=%d admin=%d total=%d\n", $external, $admin, count($seen));
}
