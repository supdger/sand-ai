<?php

declare(strict_types=1);

if (!class_exists(\app\Api\Support\ApiProblem::class)) require dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
if (!class_exists(\app\Application\Channel\ChannelConversationPolicy::class)) require dirname(__DIR__) . '/app/Application/Channel/ChannelConversationPolicy.php';

use app\Api\Support\ApiProblem;
use app\Application\Channel\ChannelConversationPolicy;

$scope = str_repeat('a', 64);
$identity = str_repeat('b', 64);
$channel = ['channel_binding_id' => 7, 'code' => 'support', 'revision' => 2, 'agent_definition_id' => 3, 'conversation_scope' => $scope];
$agent = ['definition_id' => 3, 'version_id' => 9, 'code' => 'support', 'snapshot_sha256' => str_repeat('c', 64),
    'knowledge_publication_id' => 12, 'knowledge_version_id' => 21, 'knowledge_scope_sha256' => str_repeat('f', 64)];
$source = ['source_block_id' => 10, 'file_id' => 4, 'locator_type' => 'page', 'locator' => ['page' => 1]];
$row = static fn (string $query, string $answer, array $sources): array => [
    'state' => 'succeeded',
    'input' => ['query' => $query, '_channel' => $channel, '_agent_definition' => $agent, '_trusted_identity_scope' => $identity],
    'result' => ['output' => ['content' => $answer], 'turn_source_references' => $sources,
        'source_references' => array_merge($sources, [['source_block_id' => 99, 'file_id' => 8, 'locator_type' => 'page', 'locator' => ['page' => 2]]])],
];
$rows = [$row('第二问', '第二答', []), $row('第一问', '第一答', [$source])];
$history = ChannelConversationPolicy::history($rows, $channel, $agent, $identity);
if (array_column($history['messages'], 'content') !== ['第一问', '第一答', '第二问', '第二答']
    || $history['source_references'] !== [$source] || count($history['source_groups']) !== 2) {
    throw new RuntimeException('Conversation history was not assembled in order');
}

$reject = static function (array $historyRows, array $currentChannel, array $currentAgent, string $currentIdentity, string $expectedCode): void {
    try {
        ChannelConversationPolicy::history($historyRows, $currentChannel, $currentAgent, $currentIdentity);
    } catch (ApiProblem $problem) {
        if ($problem->errorCode === $expectedCode) return;
        throw $problem;
    }
    throw new RuntimeException('Unsafe conversation history was accepted');
};
$reject($rows, [...$channel, 'conversation_scope' => str_repeat('d', 64)], $agent, $identity, 'SAND_AI_SCOPE_SNAPSHOT_INVALID');
$reject($rows, $channel, [...$agent, 'version_id' => 10], $identity, 'SAND_AI_SCOPE_SNAPSHOT_INVALID');
$reject($rows, $channel, [...$agent, 'knowledge_version_id' => 22], $identity, 'SAND_AI_SCOPE_SNAPSHOT_INVALID');
$reject($rows, $channel, $agent, str_repeat('e', 64), 'SAND_AI_SCOPE_SNAPSHOT_INVALID');
$reject([$row('问题', str_repeat('答', 12001), [])], $channel, $agent, $identity, 'SAND_AI_CONVERSATION_CONTEXT_LIMIT');
$reject([$row('问题', '回答', [['source_block_id' => 10, 'file_id' => 4]])], $channel, $agent, $identity, 'SAND_AI_SCOPE_SNAPSHOT_INVALID');
echo "channel-conversation-policy 7/7\n";
