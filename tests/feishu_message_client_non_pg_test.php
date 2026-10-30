<?php

declare(strict_types=1);

if (!class_exists(\app\Api\Support\ApiProblem::class)) require dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
if (!class_exists(\app\Application\Channel\ChannelApiBase::class)) require dirname(__DIR__) . '/app/Application/Channel/ChannelApiBase.php';
if (!class_exists(\app\Application\Channel\FeishuMessageClient::class)) require dirname(__DIR__) . '/app/Application/Channel/FeishuMessageClient.php';

use app\Api\Support\ApiProblem;
use app\Application\Channel\FeishuMessageClient;

$calls = [];
$client = new FeishuMessageClient(static function (string $url, array $headers, array $body) use (&$calls): array {
    $calls[] = [$url, $headers, $body];
    if (count($calls) === 1) return ['status' => 200, 'body' => ['code' => 0, 'tenant_access_token' => 't-example']];
    return ['status' => 200, 'body' => ['code' => 0, 'data' => ['message_id' => 'om-123']]];
});
$uuid = '11111111-2222-4333-8444-555555555555';
$sent = $client->sendText('cli-app', 'server-secret', 'oc-chat', '您好，已收到', $uuid);
if ($sent !== ['message_id' => 'om-123'] || count($calls) !== 2
    || $calls[0][2] !== ['app_id' => 'cli-app', 'app_secret' => 'server-secret']
    || $calls[1][1] !== ['Authorization' => 'Bearer t-example']
    || $calls[1][2]['receive_id'] !== 'oc-chat'
    || $calls[1][2]['uuid'] !== $uuid
    || json_decode($calls[1][2]['content'], true) !== ['text' => '您好，已收到']) {
    throw new RuntimeException('Feishu text send contract changed');
}
$expect = static function (FeishuMessageClient $subject, string $code): void {
    try {
        $subject->sendText('cli-app', 'server-secret', 'oc-chat', '回复', '11111111-2222-4333-8444-555555555555');
    } catch (ApiProblem $problem) {
        if ($problem->errorCode === $code) return;
        throw $problem;
    }
    throw new RuntimeException('Feishu failure was accepted');
};
$expect(new FeishuMessageClient(static fn (): array => ['status' => 401, 'body' => ['code' => 999]]), 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE');
$expect(new FeishuMessageClient(static fn (): array => ['status' => 200, 'body' => ['code' => 0, 'tenant_access_token' => "bad\nheader"]]), 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE');
$sendFailCount = 0;
$expect(new FeishuMessageClient(static function () use (&$sendFailCount): array {
    if (++$sendFailCount === 1) return ['status' => 200, 'body' => ['code' => 0, 'tenant_access_token' => 't-example']];
    return ['status' => 429, 'body' => ['code' => 230020, 'msg' => 'rate limit']];
}), 'SAND_AI_CHANNEL_DELIVERY_UNAVAILABLE');
try {
    $client->sendText('cli-app', 'server-secret', 'oc-chat', '回复', 'bad-uuid');
    throw new RuntimeException('Invalid UUID was accepted');
} catch (ApiProblem $problem) {
    if ($problem->errorCode !== 'SAND_AI_CHANNEL_DELIVERY_INVALID') throw $problem;
}
echo "feishu-message-client 5/5\n";
