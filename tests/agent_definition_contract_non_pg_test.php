<?php

declare(strict_types=1);

foreach (['Api/Support/ApiProblem', 'Infrastructure/Agent/AgentToolRegistry', 'Application/Agent/AgentDefinitionPolicy', 'Application/Gateway/RequestPayloadFingerprint', 'Application/Gateway/ChatCompletionOptions', 'Application/Gateway/ChatCompletionOptionsPolicy', 'Application/Agent/AgentPolicy', 'Application/Agent/AgentGateway'] as $path) {
    $class = 'app\\' . str_replace('/', '\\', $path);
    if (!class_exists($class)) require dirname(__DIR__) . '/app/' . $path . '.php';
}

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentDefinitionPolicy;
use app\Application\Agent\AgentGateway;

$draft = AgentDefinitionPolicy::draft(['code' => 'support', 'name' => 'Support', 'model' => 'chat-default', 'instructions' => 'Use the evidence', 'knowledge_base_code' => 'support_faq']);
$config = AgentDefinitionPolicy::config($draft);
$version = [...$config, 'version_no' => '2', 'tools' => json_encode($config['tools']), 'review_required' => 't', 'snapshot_sha256' => AgentDefinitionPolicy::snapshot('support', 2, $config)];
if (AgentDefinitionPolicy::publishedConfig('support', $version) !== $config) throw new RuntimeException('Published version does not round trip');
$legacyConfig = $config;
unset($legacyConfig['knowledge_base_code']);
$legacy = [...$version, 'knowledge_base_code' => null, 'snapshot_sha256' => AgentDefinitionPolicy::snapshot('support', 2, $legacyConfig)];
if (AgentDefinitionPolicy::publishedConfig('support', $legacy)['knowledge_base_code'] !== null) throw new RuntimeException('Legacy snapshot lost');
try { AgentDefinitionPolicy::draft(['code' => 'support', 'name' => 'Support', 'model' => 'chat-default', 'instructions' => 'Use the evidence', 'knowledge_base_code' => '../other']); throw new RuntimeException('Invalid knowledge code accepted'); }
catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_VALIDATION_ERROR') throw $problem; }
$bad = [...$version, 'instructions' => 'Tampered'];
try { AgentDefinitionPolicy::publishedConfig('support', $bad); throw new RuntimeException('Tampered version accepted'); }
catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_SCOPE_SNAPSHOT_INVALID') throw $problem; }
foreach ([['query' => 'ok', 'model' => 'override'], ['query' => '  ']] as $input) {
    try { AgentDefinitionPolicy::runInput($input); throw new RuntimeException('Invalid public input accepted'); }
    catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_VALIDATION_ERROR') throw $problem; }
}
$input = [...$config, 'query' => 'Question'];
$left = [...$input, '_agent_definition' => ['definition_id' => 1, 'version_id' => 2, 'code' => 'support', 'snapshot_sha256' => $version['snapshot_sha256']]];
$right = [...$input, '_agent_definition' => ['definition_id' => 1, 'version_id' => 3, 'code' => 'support', 'snapshot_sha256' => $version['snapshot_sha256']]];
try { AgentGateway::assertReplayInput($left, $right); throw new RuntimeException('Cross-version replay accepted'); }
catch (ApiProblem $problem) { if ($problem->errorCode !== 'SAND_AI_IDEMPOTENCY_CONFLICT') throw $problem; }
$reordered = [...$input, '_agent_definition' => ['snapshot_sha256' => $version['snapshot_sha256'], 'code' => 'support', 'version_id' => 2, 'definition_id' => 1]];
AgentGateway::assertReplayInput($left, $reordered);
echo "agent-definition-contract 7/7\n";
