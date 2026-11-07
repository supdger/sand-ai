<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Plugin classes are resolved by the host at runtime; this standalone test
// registers the same directory convention for the plugin namespaces.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'plugin\\')) {
        return;
    }
    $rest = substr($class, strlen('plugin\\'));
    $pos = strpos($rest, '\\');
    if ($pos === false) {
        return;
    }
    $top = substr($rest, 0, $pos);
    $dir = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $top));
    $file = dirname(__DIR__) . '/plugin/' . $dir . '/' . str_replace('\\', '/', substr($rest, $pos + 1)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\OnboardingRequestSecrets;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\ProviderGuideCatalog;
use app\Application\Onboarding\ProviderTestConnectionRequest;
use plugin\SandAi\app\admin\middleware\OnboardingSensitiveRequestMiddleware;
use Webman\Http\Request;
use Webman\Http\Response;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Provider presets/test-connection check failed: ' . $label);
    }
    ++$checks;
};

// ==== A: preset catalogue completeness ====

$expected = [
    'openai' => 'https://api.openai.com/v1',
    'deepseek' => 'https://api.deepseek.com',
    'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    'zhipu' => 'https://open.bigmodel.cn/api/paas/v4',
    'kimi' => 'https://api.moonshot.cn/v1',
    'custom_openai_compatible' => null,
];

$guides = ProviderGuideCatalog::all(false);
$byCode = [];
foreach ($guides as $guide) {
    $byCode[(string) $guide['provider_code']] = $guide;
}
$assert(count($guides) === count($byCode), 'provider codes are unique');
foreach ($expected as $code => $baseUrl) {
    $guide = $byCode[$code] ?? null;
    $assert(is_array($guide), 'preset exists: ' . $code);
    $assert(($guide['adapter'] ?? null) === 'openai_compatible', 'preset adapter: ' . $code);
    $assert(($guide['default_base_url'] ?? null) === $baseUrl, 'preset base url: ' . $code);
    $assert(is_string($guide['name'] ?? null) && $guide['name'] !== '', 'preset name: ' . $code);
}
foreach (['openai', 'deepseek', 'qwen', 'zhipu', 'kimi'] as $code) {
    $guide = $byCode[$code];
    $recommended = $guide['recommended_models'] ?? null;
    $assert(is_array($recommended) && $recommended !== [], 'recommended_models present: ' . $code);
    $recommendedFlags = 0;
    foreach ($recommended as $model) {
        $assert(is_string($model['remote_model'] ?? null) && $model['remote_model'] !== '', 'recommended model id: ' . $code);
        $assert(is_bool($model['recommended'] ?? null), 'recommended flag bool: ' . $code);
        $recommendedFlags += ($model['recommended'] ?? false) ? 1 : 0;
    }
    $assert($recommendedFlags >= 1, 'at least one recommended model: ' . $code);
    $assert(is_string($guide['api_key_url'] ?? null) && $guide['api_key_url'] !== '', 'api_key_url: ' . $code);
    $assert(is_string($guide['api_key_help'] ?? null) && $guide['api_key_help'] !== '', 'api_key_help: ' . $code);
    $assert(is_string($guide['data_egress_notice'] ?? null) && $guide['data_egress_notice'] !== '', 'data_egress_notice: ' . $code);
    $fields = array_column($guide['fields'] ?? [], 'name');
    $assert(in_array('base_url', $fields, true) && in_array('api_key', $fields, true), 'fields include base_url/api_key: ' . $code);
}
$assert(!isset($byCode['fake']), 'fake preset hidden outside debug');
$debugCodes = array_column(ProviderGuideCatalog::all(true), 'provider_code');
$assert(in_array('fake', $debugCodes, true), 'fake preset visible in debug');

// ==== B: request form resolution ====

$problem = static function (callable $call): ?ApiProblem {
    try {
        $call();
    } catch (ApiProblem $problem) {
        return $problem;
    }
    return null;
};

$source = ProviderTestConnectionRequest::resolve([], ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-x']);
$assert($source['provider_id'] === null && is_array($source['connection']), 'connection form resolves');

$source = ProviderTestConnectionRequest::resolve(['provider_id' => 5], null);
$assert($source['connection'] === null && $source['provider_id'] === 5, 'provider_id form resolves');

$source = ProviderTestConnectionRequest::resolve(['provider_id' => '7'], null);
$assert($source['provider_id'] === 7, 'numeric string provider_id resolves');

$invalid = $problem(static fn () => ProviderTestConnectionRequest::resolve([], null));
$assert($invalid?->errorCode === 'SAND_AI_VALIDATION_ERROR', 'empty request rejected');

$invalid = $problem(static fn () => ProviderTestConnectionRequest::resolve(['provider_id' => 0], null));
$assert($invalid?->errorCode === 'SAND_AI_VALIDATION_ERROR', 'non-positive provider_id rejected');

$invalid = $problem(static fn () => ProviderTestConnectionRequest::resolve(['provider_id' => 'abc'], null));
$assert($invalid?->errorCode === 'SAND_AI_VALIDATION_ERROR', 'non-numeric provider_id rejected');

$invalid = $problem(static fn () => ProviderTestConnectionRequest::resolve(
    ['provider_id' => 5],
    ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-x'],
));
$assert($invalid?->errorCode === 'SAND_AI_VALIDATION_ERROR', 'connection and provider_id are mutually exclusive');

$invalid = $problem(static fn () => ProviderTestConnectionRequest::resolve(['name' => 'x'], null));
$assert($invalid?->errorCode === 'SAND_AI_VALIDATION_ERROR', 'unsupported fields rejected');

// ==== C: connection policy input validation ===

$publicResolver = new class implements HostResolver {
    /** @return list<string> */
    public function resolve(string $host): array
    {
        return ['203.0.113.10'];
    }
};
$privateResolver = new class implements HostResolver {
    /** @return list<string> */
    public function resolve(string $host): array
    {
        return ['10.0.0.5'];
    }
};

$invalid = $problem(static fn () => (new ProviderConnectionPolicy($publicResolver))->normalize(
    ['base_url' => 'http://api.deepseek.com', 'api_key' => 'sk-x'],
));
$assert($invalid?->errorCode === 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'non-https base url rejected');

$invalid = $problem(static fn () => (new ProviderConnectionPolicy($publicResolver))->normalize(
    ['base_url' => 'not-a-url', 'api_key' => 'sk-x'],
));
$assert($invalid?->errorCode === 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'malformed base url rejected');

$invalid = $problem(static fn () => (new ProviderConnectionPolicy($publicResolver))->normalize(
    ['base_url' => 'https://localhost', 'api_key' => 'sk-x'],
));
$assert($invalid?->errorCode === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN', 'localhost rejected');

$invalid = $problem(static fn () => (new ProviderConnectionPolicy($privateResolver))->normalize(
    ['base_url' => 'https://internal.example.com', 'api_key' => 'sk-x'],
));
$assert($invalid?->errorCode === 'SAND_AI_ONBOARDING_CONNECTION_FORBIDDEN', 'internal resolved address rejected');

$invalid = $problem(static fn () => (new ProviderConnectionPolicy($publicResolver))->normalize(
    ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-x', 'payload' => 'x'],
));
$assert($invalid?->errorCode === 'SAND_AI_ONBOARDING_CONNECTION_INVALID', 'extra connection fields rejected');

$normalized = (new ProviderConnectionPolicy($publicResolver))->normalize(
    ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-x'],
);
$assert($normalized['api_key'] === 'sk-x' && $normalized['endpoint']->baseUrl === 'https://api.deepseek.com', 'valid connection normalizes');

// ==== D: middleware vault whitelist for test-connection ===

$buffer = static function (array $payload): string {
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return 'POST ' . OnboardingSensitiveRequestMiddleware::TEST_CONNECTION_PATH . " HTTP/1.1\r\n"
        . "Host: sandbox.test\r\n"
        . "Content-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
};

$middleware = new OnboardingSensitiveRequestMiddleware();

// (1) `{connection}` on the whitelisted path is vaulted, never left in post or
//     the raw body a later middleware/controller could serialize.
$request = new Request($buffer(['connection' => ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-test-value']]));
$seen = null;
$response = $middleware->process($request, static function (Request $request) use (&$seen): Response {
    $seen = [
        'post' => $request->post(),
        'raw' => $request->rawBody(),
        'vault' => OnboardingRequestSecrets::take($request),
    ];

    return new Response(200, [], 'ok');
});
$assert($response->getStatusCode() === 200, 'connection form reaches the handler');
$assert(is_array($seen['post']) && $seen['post'] === [], 'connection stripped from post');
$assert(is_string($seen['raw']) && !str_contains($seen['raw'], 'sk-test-value'), 'api key absent from raw body');
$assert(is_array($seen['vault']) && ($seen['vault']['api_key'] ?? null) === 'sk-test-value', 'connection vaulted');

// (2) `{provider_id}` passes through with no vault material.
$request = new Request($buffer(['provider_id' => 5]));
$seen = null;
$response = $middleware->process($request, static function (Request $request) use (&$seen): Response {
    $seen = ['post' => $request->post(), 'vault' => OnboardingRequestSecrets::take($request)];

    return new Response(200, [], 'ok');
});
$assert($response->getStatusCode() === 200, 'provider_id form reaches the handler');
$assert($seen['post'] === ['provider_id' => 5] && $seen['vault'] === null, 'provider_id passes untouched');

// (3) `{connection, provider_id}` keeps both legs separated for the
//     controller-side mutual-exclusion check; the key is still vaulted.
$request = new Request($buffer(['connection' => ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-secret-2'], 'provider_id' => 3]));
$seen = null;
$response = $middleware->process($request, static function (Request $request) use (&$seen): Response {
    $seen = [
        'post' => $request->post(),
        'raw' => $request->rawBody(),
        'vault' => OnboardingRequestSecrets::take($request),
    ];

    return new Response(200, [], 'ok');
});
$assert($response->getStatusCode() === 200, 'combined form reaches the handler');
$assert($seen['post'] === ['provider_id' => 3], 'provider_id visible to controller');
$assert(is_string($seen['raw']) && !str_contains($seen['raw'], 'sk-secret-2'), 'combined form keeps key out of raw body');
$assert(($seen['vault']['api_key'] ?? null) === 'sk-secret-2', 'combined form connection vaulted');

// (4) Unsupported fields on the whitelisted path are rejected before the
//     handler, with the key scrubbed from every surviving surface.
$request = new Request($buffer(['connection' => ['base_url' => 'https://api.deepseek.com', 'api_key' => 'sk-secret-3'], 'extra' => 'x']));
$handlerRan = false;
$response = $middleware->process($request, static function () use (&$handlerRan): Response {
    $handlerRan = true;

    return new Response(200, [], 'ok');
});
$assert(!$handlerRan, 'unsupported fields rejected before handler');
// The response shell is HTTP 200 with a business code of 400.
$assert($response->getStatusCode() === 200, 'unsupported fields stay in the response shell');
$body = (string) $response->rawBody();
$assert(str_contains($body, '"code":400'), 'business code 400 surfaced');
$assert(str_contains($body, 'SAND_AI_VALIDATION_ERROR'), 'validation error code surfaced');
$assert(!str_contains($body, 'sk-secret-3'), 'rejection does not echo the api key');

echo "Provider presets/test-connection test passed ({$checks} checks)\n";
