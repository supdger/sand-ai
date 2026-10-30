<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $root . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use app\Application\Channel\FeishuDeliveryCipher;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Feishu delivery cipher failed at ' . ($checks + 1));
    ++$checks;
};
$key = base64_encode(random_bytes(32));
$cipher = new FeishuDeliveryCipher(['current_key_id' => 'first', 'current_key' => $key, 'previous_keys_json' => '']);
$sealed = $cipher->seal('oc_123456', 91, 7, 'service');
$assert($cipher->open($sealed, 91, 7, 'service') === 'oc_123456');
$assert(!str_contains(json_encode($sealed, JSON_THROW_ON_ERROR), 'oc_123456'));
try { $cipher->open($sealed, 92, 7, 'service'); $assert(false); }
catch (\app\Api\Support\ApiProblem) { $assert(true); }
try { $cipher->open($sealed, 91, 7, 'other'); $assert(false); }
catch (\app\Api\Support\ApiProblem) { $assert(true); }
$rotated = new FeishuDeliveryCipher(['current_key_id' => 'second', 'current_key' => base64_encode(random_bytes(32)),
    'previous_keys_json' => json_encode(['first' => $key], JSON_THROW_ON_ERROR)]);
$assert($rotated->open($sealed, 91, 7, 'service') === 'oc_123456');
$assert(FeishuDeliveryCipher::uuid(91) === FeishuDeliveryCipher::uuid(91));
$assert(FeishuDeliveryCipher::uuid(91) !== FeishuDeliveryCipher::uuid(92));
$assert(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-a[a-f0-9]{3}-[a-f0-9]{12}$/D', FeishuDeliveryCipher::uuid(91)) === 1);
print "feishu-delivery-cipher $checks/$checks\n";
