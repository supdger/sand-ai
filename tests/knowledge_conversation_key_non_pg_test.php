<?php

declare(strict_types=1);

namespace support {
    final class Request
    {
        public function __construct(private readonly string $key) {}
        public function header(string $name, string $default = ''): string { return $name === 'idempotency-key' ? $this->key : $default; }
    }
}

namespace {
    $appRoot = dirname(__DIR__) . '/app/';
    spl_autoload_register(static function (string $class) use ($appRoot): void {
        if (!str_starts_with($class, 'app\\')) return;
        $path = $appRoot . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    });

    use app\Application\Gateway\RequestIdempotencyKey;
    use app\Identity\Contract\IdentityContext;
    use support\Request;

    $identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', []);
    $request = new Request('same-client-key');
    $one = RequestIdempotencyKey::requiredForResource($request, 'knowledge-turn', $identity, str_repeat('a', 32));
    $two = RequestIdempotencyKey::requiredForResource($request, 'knowledge-turn', $identity, str_repeat('b', 32));
    if (strlen($one) > 96 || !hash_equals($one, RequestIdempotencyKey::requiredForResource($request, 'knowledge-turn', $identity, str_repeat('a', 32)))
        || hash_equals($one, $two)) {
        throw new \RuntimeException('Conversation idempotency key is not bounded and resource-scoped');
    }
    echo 'knowledge-conversation-key 3/3' . PHP_EOL;
}
