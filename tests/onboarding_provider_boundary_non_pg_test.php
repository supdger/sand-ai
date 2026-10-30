<?php

declare(strict_types=1);

$vendor = getenv('SANDPACKAGE_TEST_VENDOR');
if (!is_string($vendor) || !is_file($vendor)) {
    throw new RuntimeException('Set SANDPACKAGE_TEST_VENDOR');
}
require $vendor;

$packageRoot = dirname(__DIR__);
$sourceRoot = getenv('SAND_AI_TEST_SOURCE_ROOT') ?: $packageRoot;
spl_autoload_register(static function (string $class) use ($packageRoot, $sourceRoot): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    foreach ([$packageRoot, $sourceRoot] as $root) {
        $path = $root . '/app/' . $relative;
        if (is_file($path)) {
            require $path;
            return;
        }
    }
}, true, true);

use app\Api\Support\ApiProblem;
use app\Application\Onboarding\HostResolver;
use app\Application\Onboarding\OnboardingHttpResponse;
use app\Application\Onboarding\OnboardingHttpTransport;
use app\Application\Onboarding\OpenAiCompatibleOnboardingClient;
use app\Application\Onboarding\ProviderConnectionPolicy;
use app\Application\Onboarding\VerifiedEndpoint;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Onboarding provider boundary failed at check ' . ($checks + 1));
    }
    ++$checks;
};
$expectProblem = static function (callable $run, string $code) use ($assert): ApiProblem {
    try {
        $run();
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === $code);
        return $problem;
    }
    throw new RuntimeException('Expected onboarding problem ' . $code);
};
$resolver = new class implements HostResolver {
    public function resolve(string $host): array
    {
        return ['1.1.1.1'];
    }
};
$transport = new class implements OnboardingHttpTransport {
    public int $calls = 0;
    public OnboardingHttpResponse $response;

    public function request(string $method, VerifiedEndpoint $endpoint, string $path, array $headers, ?array $json, int $timeoutMs): OnboardingHttpResponse
    {
        ++$this->calls;
        return $this->response;
    }
};
$client = new OpenAiCompatibleOnboardingClient(new ProviderConnectionPolicy($resolver), $transport);
$connection = ['base_url' => 'https://provider.example/v1', 'api_key' => 'test-key'];

$expectProblem(static fn () => $client->discover(['base_url' => $connection['base_url']]), 'SAND_AI_ONBOARDING_CONNECTION_INVALID');
$assert($transport->calls === 0);

$transport->response = new OnboardingHttpResponse(200, '{"data":[]}');
$assert($client->discover($connection) === []);
$assert($transport->calls === 1);

$transport->response = new OnboardingHttpResponse(200, '{"data":[{"id":"chat-1","owned_by":"vendor"}]}');
$models = $client->discover($connection);
$assert(count($models) === 1 && $models[0]['remote_model'] === 'chat-1');

$transport->response = new OnboardingHttpResponse(401, '{"error":"private-upstream-detail"}');
$problem = $expectProblem(static fn () => $client->discover($connection), 'SAND_AI_PROVIDER_REJECTED');
$assert(!str_contains($problem->getMessage(), 'private-upstream-detail'));

$transport->response = new OnboardingHttpResponse(429, '{}');
$expectProblem(static fn () => $client->discover($connection), 'SAND_AI_PROVIDER_RATE_LIMITED');

$transport->response = new OnboardingHttpResponse(200, '{"choices":[{"message":{"content":null}}]}');
$expectProblem(static fn () => $client->verify($connection, 'embedding-only'), 'SAND_AI_PROVIDER_FAILURE');

$transport->response = new OnboardingHttpResponse(200, '{"data":[{"id":"bad model"}]}');
$expectProblem(static fn () => $client->discover($connection), 'SAND_AI_PROVIDER_FAILURE');

echo 'onboarding-provider-boundary ' . $checks . '/' . $checks . PHP_EOL;
