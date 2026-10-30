<?php

declare(strict_types=1);

foreach (['Api/Support/ApiProblem', 'Infrastructure/Agent/AgentToolRegistry', 'Application/Gateway/RequestPayloadFingerprint', 'Application/Gateway/ChatCompletionOptions', 'Application/Gateway/ChatCompletionOptionsPolicy', 'Application/Agent/AgentPolicy', 'Application/Agent/AgentGateway', 'Application/Channel/ChannelBindingPolicy'] as $path) {
    $class = 'app\\' . str_replace('/', '\\', $path);
    if (!class_exists($class)) require dirname(__DIR__) . '/app/' . $path . '.php';
}

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentGateway;
use app\Application\Channel\ChannelBindingPolicy;

$config = ChannelBindingPolicy::configuration(['code' => 'wecom_help', 'name' => '客服', 'agent_definition_id' => 7, 'application_id' => '9', 'workload_client_id' => '11']);
if ($config['agent_definition_id'] !== 7) throw new RuntimeException('Channel configuration changed');
$message = ChannelBindingPolicy::message(['message_id' => 'm-1', 'conversation_id' => 'c-1', 'sender_id' => 'u-1', 'query' => '问题'], 20);
$otherChannel = ChannelBindingPolicy::message(['message_id' => 'm-1', 'conversation_id' => 'c-1', 'sender_id' => 'u-1', 'query' => '问题'], 21);
$otherSender = ChannelBindingPolicy::message(['message_id' => 'm-1', 'conversation_id' => 'c-1', 'sender_id' => 'u-2', 'query' => '问题'], 20);
if ($message['conversation_scope'] === $otherChannel['conversation_scope'] || $message['conversation_scope'] === $otherSender['conversation_scope']) throw new RuntimeException('Conversations are not isolated');
if ($message['message_scope'] === $otherChannel['message_scope']) throw new RuntimeException('Message keys are not isolated');
foreach ([['message_id' => 'm', 'conversation_id' => 'c', 'sender_id' => 'u', 'query' => 'q', 'model' => 'override'], ['message_id' => '', 'conversation_id' => 'c', 'sender_id' => 'u', 'query' => 'q']] as $bad) {
    try { ChannelBindingPolicy::message($bad, 20); throw new RuntimeException('Bad channel message accepted'); }
    catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_VALIDATION_ERROR') throw $problem; }
}
$business = ['model' => 'chat-default', 'instructions' => 'Use evidence', 'query' => '问题', 'tools' => ['retrieval.search'], 'review_required' => true, 'max_output_tokens' => 256];
$binding = ['channel_binding_id' => 20, 'code' => 'wecom_help', 'revision' => 1, 'agent_definition_id' => 7, 'conversation_scope' => $message['conversation_scope'], 'message_scope' => $message['message_scope']];
$left = [...$business, '_channel' => $binding];
$reordered = [...$business, '_channel' => array_reverse($binding, true)];
AgentGateway::assertReplayInput($left, $reordered);
$changed = [...$business, '_channel' => [...$binding, 'conversation_scope' => $otherSender['conversation_scope']]];
try { AgentGateway::assertReplayInput($left, $changed); throw new RuntimeException('Cross-conversation replay accepted'); }
catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_IDEMPOTENCY_CONFLICT') throw $problem; }
echo "channel-binding-contract 6/6\n";
