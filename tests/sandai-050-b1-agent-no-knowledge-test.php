<?php

declare(strict_types=1);

/**
 * Isolated 0.5.0 agent-without-knowledge-base tests: no database, no SandIAM
 * plugin, no network. Covers the nullable knowledge_base_code contract —
 * published without a base, the frozen binding stores an all-null knowledge
 * triple and the model caller accepts a null retrieval closure so the agent
 * answers from the model only. DB-backed publish/execution paths are covered
 * by repository review.
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Identity/Contract/IdentityContext.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/AgentToolRegistry.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptions.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/ChatCompletionOptionsPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/RequestPayloadFingerprint.php';
require_once dirname(__DIR__) . '/app/Application/Usage/UsageAttribution.php';
require_once dirname(__DIR__) . '/app/Application/Gateway/RequestIdempotencyKey.php';
require_once dirname(__DIR__) . '/app/Application/Tool/ToolFingerprint.php';
require_once dirname(__DIR__) . '/app/Application/Agent/AgentReadonlyToolSelection.php';
require_once dirname(__DIR__) . '/app/Application/Agent/AgentConversationGateway.php';
require_once dirname(__DIR__) . '/app/Application/Agent/AgentPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Agent/AgentDefinitionPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Channel/ChannelConversationPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Channel/ChannelConversationGateway.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/NeuronAgentCaller.php';
// The repository carries no vendor tree; the real SDK wins when it is
// autoloadable, otherwise the minimal stub surface stands in for it.
if (!interface_exists(\NeuronAI\Providers\AIProviderInterface::class)) {
    require_once __DIR__ . '/stubs/NeuronSdk.php';
}
require_once dirname(__DIR__) . '/app/Application/Tool/StrictJsonObjectDecoder.php';
require_once dirname(__DIR__) . '/app/Application/Tool/ToolSchemaPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Agent/AgentReadonlyToolProtocol.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/NeuronAuthorizedReadonlyTool.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/NeuronAuthorizedRetrievalTool.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/NeuronTextWireMapper.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Agent/NeuronControlledProvider.php';
require_once dirname(__DIR__) . '/app/Infrastructure/Provider/AgentToolChatRequest.php';

use app\Api\Support\ApiProblem;
use app\Application\Agent\AgentConversationGateway;
use app\Application\Agent\AgentDefinitionPolicy;
use app\Application\Agent\AgentPolicy;
use app\Application\Channel\ChannelConversationGateway;
use app\Application\Channel\ChannelConversationPolicy;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Agent\NeuronAgentCaller;
use app\Infrastructure\Agent\NeuronAuthorizedReadonlyTool;
use app\Infrastructure\Agent\NeuronAuthorizedRetrievalTool;
use app\Infrastructure\Agent\NeuronControlledProvider;
use app\Infrastructure\Provider\AgentToolChatRequest;

$passed = 0;
$failed = 0;

function ok(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$name}\n");
}

/** Catches an ApiProblem and returns its code, or null when nothing was thrown. */
function problemCode(callable $callable): ?string
{
    try {
        $callable();
        return null;
    } catch (ApiProblem $problem) {
        return $problem->errorCode;
    }
}

$draftInput = static fn (?string $knowledgeCode): array => [
    'code' => 'agent_nokb',
    'name' => 'Agent without knowledge',
    'model' => 'glm-4.6',
    'instructions' => 'Answer briefly.',
    'tools' => ['retrieval.search'],
    'review_required' => false,
    'knowledge_base_code' => $knowledgeCode,
    'context_turn_limit' => 2,
];

// A draft accepts a null knowledge base code and keeps it null.
$draft = AgentDefinitionPolicy::draft($draftInput(null));
ok($draft['knowledge_base_code'] === null, 'draft: null knowledge code accepted');
ok(array_key_exists('knowledge_base_code', $draft), 'draft: null code key preserved');

// A draft still accepts and normalizes a selected knowledge base.
$boundDraft = AgentDefinitionPolicy::draft($draftInput('kb_alias'));
ok($boundDraft['knowledge_base_code'] === 'kb_alias', 'draft: knowledge code kept');

// An invalid code is still rejected.
ok(problemCode(static fn () => AgentDefinitionPolicy::draft($draftInput('KB-UP')))
    === 'SAND_AI_VALIDATION_ERROR', 'draft: invalid code rejected');

// A published version row without a knowledge base yields a valid config.
$versionRow = static function (array $config, ?string $knowledgeCode, string $code, int $versionNo): array {
    return [
        'version_no' => $versionNo,
        'model' => $config['model'],
        'instructions' => $config['instructions'],
        'tools' => json_encode($config['tools'], JSON_THROW_ON_ERROR),
        'review_required' => $config['review_required'] ? 't' : 'f',
        'max_output_tokens' => $config['max_output_tokens'],
        'knowledge_base_code' => $knowledgeCode,
        'context_turn_limit' => $config['context_turn_limit'],
        'readonly_tools' => '[]',
        'snapshot_sha256' => AgentDefinitionPolicy::snapshot($code, $versionNo, $config),
    ];
};
$nullConfig = AgentDefinitionPolicy::config($draft);
$publishedNull = AgentDefinitionPolicy::publishedConfig('agent_nokb', $versionRow($nullConfig, null, 'agent_nokb', 1));
ok($publishedNull['knowledge_base_code'] === null, 'publishedConfig: null code published');
ok($publishedNull['model'] === 'glm-4.6', 'publishedConfig: model kept without knowledge');

// A published version row with a knowledge base keeps the existing contract.
$boundConfig = AgentDefinitionPolicy::config($boundDraft);
$publishedBound = AgentDefinitionPolicy::publishedConfig('agent_nokb', $versionRow($boundConfig, 'kb_alias', 'agent_nokb', 1));
ok($publishedBound['knowledge_base_code'] === 'kb_alias', 'publishedConfig: bound code kept');

// A tampered snapshot is still rejected.
$tampered = $versionRow($nullConfig, null, 'agent_nokb', 1);
$tampered['snapshot_sha256'] = str_repeat('0', 64);
ok(problemCode(static fn () => AgentDefinitionPolicy::publishedConfig('agent_nokb', $tampered))
    === 'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'publishedConfig: tampered snapshot rejected');

// Runtime input normalization keeps a null knowledge code.
$normalized = AgentPolicy::normalizeInput([
    'model' => 'glm-4.6',
    'instructions' => 'Answer briefly.',
    'query' => 'hello',
    'tools' => ['retrieval.search'],
    'knowledge_base_code' => null,
]);
ok($normalized['knowledge_base_code'] === null, 'normalizeInput: null code kept');
ok(problemCode(static fn () => AgentPolicy::normalizeInput([
    'model' => 'glm-4.6', 'instructions' => 'x', 'query' => 'q', 'knowledge_base_code' => 'bad!',
])) === 'SAND_AI_VALIDATION_ERROR', 'normalizeInput: invalid code rejected');

// The model caller accepts a null retrieval closure: agents without a
// knowledge base complete directly through the model with no retrieval tool.
$retrieveParameter = (new ReflectionMethod(NeuronAgentCaller::class, 'complete'))->getParameters()[6];
ok($retrieveParameter->getName() === 'retrieve' && $retrieveParameter->allowsNull(),
    'caller: retrieval closure is nullable');

// The frozen agent binding of a no-knowledge publish carries an all-null
// knowledge triple and passes the channel snapshot check.
$nullAgent = [
    'definition_id' => 12,
    'version_id' => 34,
    'code' => 'agent_nokb',
    'snapshot_sha256' => str_repeat('a', 64),
    'knowledge_publication_id' => null,
    'knowledge_version_id' => null,
    'knowledge_scope_sha256' => null,
];
$boundAgent = [
    ...$nullAgent,
    'knowledge_publication_id' => 7,
    'knowledge_version_id' => 8,
    'knowledge_scope_sha256' => str_repeat('b', 64),
];
$snapshotAssert = new ReflectionMethod(ChannelConversationGateway::class, 'assertAgentSnapshot');
$snapshotAssert->setAccessible(true);
$gateway = new ChannelConversationGateway();
ok(problemCode(static fn () => $snapshotAssert->invoke($gateway, $nullAgent)) === null,
    'snapshot: all-null knowledge triple accepted');
ok(problemCode(static fn () => $snapshotAssert->invoke($gateway, $boundAgent)) === null,
    'snapshot: bound knowledge triple accepted');
ok(problemCode(static fn () => $snapshotAssert->invoke($gateway,
        [...$nullAgent, 'knowledge_version_id' => 8]))
    === 'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'snapshot: partial null triple rejected');
ok(problemCode(static fn () => $snapshotAssert->invoke($gateway,
        [...$nullAgent, 'version_id' => null]))
    === 'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'snapshot: null version rejected');

// The frozen-binding SQL predicate switches to IS NULL for no-knowledge
// agents; json null projects to SQL NULL which equality never matches.
$predicate = new ReflectionMethod(ChannelConversationGateway::class, 'knowledgePredicate');
$predicate->setAccessible(true);
[$nullFragment, $nullParameters] = $predicate->invoke(null, $nullAgent, 'input');
ok(substr_count($nullFragment, ' IS NULL') === 3 && $nullParameters === [],
    'predicate: null binding uses IS NULL');
ok(str_contains($nullFragment, "input#>>'{_agent_definition,knowledge_publication_id}'"),
    'predicate: null fragment covers publication id');
[$boundFragment, $boundParameters] = $predicate->invoke(null, $boundAgent, 't.input');
ok(substr_count($boundFragment, '=?') === 3
    && $boundParameters === ['7', '8', str_repeat('b', 64)],
    'predicate: bound binding uses equality parameters');
ok(str_contains($boundFragment, "t.input#>>'{_agent_definition,knowledge_publication_id}'"),
    'predicate: bound fragment uses prefixed column');

// Conversation scope hashes a binding with a null knowledge triple.
$attribution = new UsageAttribution(7, 11, 22, 33,
    ['organization_ref' => '11', 'application_ref' => '22', 'workload_client_ref' => '33'],
    'agent', 'sand_ai.agent.run');
$conversations = new AgentConversationGateway();
$scope = $conversations->scope($attribution, $nullAgent, 'conv-1');
ok(preg_match('/^[a-f0-9]{64}$/D', $scope) === 1, 'scope: null binding hashes');
ok($scope !== $conversations->scope($attribution, $boundAgent, 'conv-1'),
    'scope: null and bound bindings differ');
ok(problemCode(static fn () => $conversations->scope($attribution,
        ['definition_id' => 12, 'version_id' => 34], 'conv-1'))
    === 'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'scope: incomplete binding rejected');

// Channel history accepts a prior turn frozen with the same all-null binding.
$channel = [
    'channel_binding_id' => 5,
    'code' => 'chatops',
    'revision' => 3,
    'agent_definition_id' => 12,
    'conversation_scope' => str_repeat('c', 64),
];
$identityScope = str_repeat('d', 64);
$historyRow = [
    'id' => 41,
    'state' => 'succeeded',
    'eligible_turns' => 1,
    'input' => json_encode([
        '_channel' => $channel,
        '_agent_definition' => $nullAgent,
        '_trusted_identity_scope' => $identityScope,
        'query' => 'previous question',
    ], JSON_THROW_ON_ERROR),
    'result' => json_encode([
        'output' => ['content' => 'previous answer'],
        'source_provenance_version' => 2,
        'review_required' => false,
        'source_references' => [],
    ], JSON_THROW_ON_ERROR),
];
$history = ChannelConversationPolicy::history([$historyRow], $channel, $nullAgent, $identityScope, 2);
ok(count($history['messages']) === 2 && $history['source_references'] === [],
    'history: no-knowledge turn reused');

// A prior turn whose binding suddenly carries knowledge ids is still rejected.
$driftedRow = $historyRow;
$driftedRow['input'] = json_encode([
    '_channel' => $channel,
    '_agent_definition' => $boundAgent,
    '_trusted_identity_scope' => $identityScope,
    'query' => 'previous question',
], JSON_THROW_ON_ERROR);
ok(problemCode(static fn () => ChannelConversationPolicy::history(
        [$driftedRow], $channel, $nullAgent, $identityScope, 2))
    === 'SAND_AI_SCOPE_SNAPSHOT_INVALID', 'history: drifted knowledge binding rejected');

// Source-level guards against regressions of the removed hard requirements.
$repositoryRoot = dirname(__DIR__);
$definitionSource = (string) file_get_contents($repositoryRoot . '/app/Application/Agent/AgentDefinitionGateway.php');
ok(!str_contains($definitionSource, 'requires a knowledge base')
    && !str_contains($definitionSource, 'has no knowledge base'),
    'gateway: publish no longer requires a knowledge base');
$runtimeSource = (string) file_get_contents($repositoryRoot . '/app/Application/Agent/AgentGateway.php');
ok(!str_contains($runtimeSource, 'Published agent has no knowledge base')
    && str_contains($runtimeSource, '$retrievalWired'),
    'gateway: runtime admits agents without knowledge');

// The panel no longer blocks enabling while keeping the model-only hint.
$panelSource = (string) file_get_contents(
    $repositoryRoot . '/sandadmin-artd/src/views/plugin/sand-ai/agent/modules/AgentDefinitionPanel.vue');
ok(!str_contains($panelSource, '启用前需要先选一个知识库'),
    'panel: enable guard removed');
ok(str_contains($panelSource, '不选也能用，只靠模型自己回答'),
    'panel: model-only hint kept');

// The protocol request accepts an empty tool list as a pure text request and
// keeps the retrieval-first contract for wired tools.
$makeTool = static function (string $class, array $properties): object {
    $reflection = new ReflectionClass($class);
    $tool = $reflection->newInstanceWithoutConstructor();
    foreach ($properties as $name => $value) {
        $reflection->getProperty($name)->setValue($tool, $value);
    }
    return $tool;
};
$readonlySchema = [
    'type' => 'object',
    'properties' => ['q' => ['type' => 'string']],
    'required' => ['q'],
    'additionalProperties' => false,
];
$readonlyBinding = ['snapshot_sha256' => str_repeat('e', 64), 'input_schema' => $readonlySchema];
$readonlyName = NeuronAuthorizedReadonlyTool::wireName($readonlyBinding);
$readonly = $makeTool(NeuronAuthorizedReadonlyTool::class, [
    'name' => $readonlyName, 'description' => 'Readonly demo',
    'parameters' => ['parameters' => $readonlySchema],
]);
$retrieval = $makeTool(NeuronAuthorizedRetrievalTool::class, [
    'name' => 'retrieval_search', 'description' => 'Search.',
    'parameters' => ['parameters' => [
        'type' => 'object', 'properties' => ['query' => ['type' => 'string']],
        'required' => ['query'],
    ]],
]);

$toolFreeRequest = new AgentToolChatRequest([]);
ok($toolFreeRequest->tools === [] && $toolFreeRequest->sdkTools === [],
    'request: empty tool list accepted');
ok($toolFreeRequest->retrievalWired === false && $toolFreeRequest->countReadonlyTools() === 0,
    'request: empty tool list is retrieval-free');
$freeFingerprint = $toolFreeRequest->fingerprint();
ok($freeFingerprint['version'] === 3 && $freeFingerprint['tools'] === []
    && !isset($freeFingerprint['tool_choice'], $freeFingerprint['execution_limits']),
    'request: empty tool fingerprint stays distinct');
$textWire = [['role' => 'system', 'content' => '指令'], ['role' => 'user', 'content' => '你好']];
ok(problemCode(static fn () => $toolFreeRequest->assertMessages($textWire)) === null,
    'request: empty tools accept text messages');
ok(problemCode(static fn () => $toolFreeRequest->assertOutcome(
    ['content' => '回答', 'response_kind' => 'text', 'tool_calls' => []], $textWire)) === null,
    'request: empty tools accept a text outcome');
ok(problemCode(static fn () => $toolFreeRequest->assertOutcome(
    ['content' => null, 'response_kind' => 'tool_calls',
        'tool_calls' => [['id' => 'call_1', 'name' => 'retrieval_search', 'arguments' => ['query' => 'x']]]],
    $textWire)) === 'SAND_AI_AGENT_PROTOCOL_INVALID',
    'request: empty tools still reject tool calls');

// A readonly-only list is a retrieval-free request, never a retrieval list.
$readonlyRequest = new AgentToolChatRequest([$readonly]);
ok($readonlyRequest->retrievalWired === false && $readonlyRequest->countReadonlyTools() === 1
    && ($readonlyRequest->tools[0]['function']['name'] ?? null) === $readonlyName,
    'request: readonly-only list stays retrieval-free');
$readonlyFingerprint = $readonlyRequest->fingerprint();
ok($readonlyFingerprint['version'] === 3 && ($readonlyFingerprint['tool_choice'] ?? null) === 'auto'
    && isset($readonlyFingerprint['execution_limits']),
    'request: readonly-only fingerprint carries limits');

// The wired contract is unchanged: retrieval first, named retrieval_search,
// domain bounds rewritten, readonly tools behind it.
$wiredRequest = new AgentToolChatRequest([$retrieval, $readonly]);
ok($wiredRequest->retrievalWired && $wiredRequest->countReadonlyTools() === 1,
    'request: wired list keeps retrieval first');
$wiredSchema = $wiredRequest->tools[0]['function']['parameters'];
ok($wiredSchema['additionalProperties'] === false && $wiredSchema['properties']['query']['minLength'] === 1
    && $wiredSchema['properties']['query']['maxLength'] === 500,
    'request: retrieval schema bounds kept');
ok($wiredRequest->fingerprint()['version'] === 2
    && ($wiredRequest->fingerprint()['tool_choice'] ?? null) === 'auto',
    'request: wired fingerprint version kept');

ok(problemCode(static fn () => new AgentToolChatRequest([$readonly, $retrieval]))
    === 'SAND_AI_AGENT_PROTOCOL_INVALID', 'request: retrieval behind readonly rejected');
ok(problemCode(static fn () => new AgentToolChatRequest([
        $makeTool(NeuronAuthorizedRetrievalTool::class, [
            'name' => 'retrieval_fake', 'description' => 'x',
            'parameters' => ['parameters' => $readonlySchema],
        ]),
    ])) === 'SAND_AI_AGENT_PROTOCOL_INVALID', 'request: wired tool must be retrieval_search');
ok(problemCode(static fn () => new AgentToolChatRequest([$readonly, $readonly, $readonly, $readonly]))
    === 'SAND_AI_AGENT_PROTOCOL_INVALID', 'request: more than three tools rejected');
ok(problemCode(static fn () => new AgentToolChatRequest([], stream: true)->streamArgumentLimit('retrieval_search'))
    === 'SAND_AI_AGENT_PROTOCOL_INVALID', 'request: retrieval-free stream rejects tool arguments');

// The controlled provider completes a pure text round with no tool registered.
$seenWire = null;
$seenRequest = null;
$textDispatch = static function (int $round, array $messages, AgentToolChatRequest $request) use (&$seenWire, &$seenRequest): array {
    $seenWire = $messages;
    $seenRequest = $request;
    return [
        'content' => '模型直接回答', 'response_kind' => 'text', 'tool_calls' => [],
        'usage' => ['usage_state' => 'measured', 'input_tokens' => 3, 'output_tokens' => 5],
        'invocation_id' => 9,
    ];
};
$provider = new NeuronControlledProvider('glm-4.6', $textDispatch);
$provider->systemPrompt(new \NeuronAI\Chat\Messages\SystemMessage('指令'));
$response = $provider->setTools([])->chat(new \NeuronAI\Chat\Messages\UserMessage('你好'));
ok($response->message() instanceof \NeuronAI\Chat\Messages\AssistantMessage
    && $response->message()->getContent() === '模型直接回答',
    'provider: empty tools return a text answer');
ok($seenWire === $textWire && $seenRequest->sdkTools === [],
    'provider: empty tools wire carries instructions only');
ok($response->metadata('sandai_round_number') === 1 && count($provider->rounds()) === 1,
    'provider: empty tools round is accounted');

// A retrieval-free readonly round still executes through the same accounted path.
$rounds = 0;
$readonlyDispatch = static function (int $round, array $messages, AgentToolChatRequest $request) use (&$rounds, $readonlyName): array {
    $rounds = $round;
    if ($round === 1) {
        return [
            'content' => null, 'response_kind' => 'tool_calls',
            'tool_calls' => [['id' => 'call_1', 'name' => $readonlyName, 'arguments' => ['q' => 'x']]],
            'usage' => ['usage_state' => 'measured', 'input_tokens' => 1, 'output_tokens' => 1],
            'invocation_id' => 10,
        ];
    }
    return [
        'content' => '最终答案', 'response_kind' => 'text', 'tool_calls' => [],
        'usage' => ['usage_state' => 'measured', 'input_tokens' => 2, 'output_tokens' => 2],
        'invocation_id' => 11,
    ];
};
$readonlyProvider = new NeuronControlledProvider('glm-4.6', $readonlyDispatch);
$readonlyProvider->setTools([$readonly]);
$first = $readonlyProvider->chat(new \NeuronAI\Chat\Messages\UserMessage('你好'));
ok($first->message() instanceof \NeuronAI\Chat\Messages\ToolCallMessage
    && $first->message()->getToolCalls()[0]->getName() === $readonlyName,
    'provider: readonly round resolves the managed call');
$call = $first->message()->getToolCalls()[0];
$call->setResult('查询结果');
$second = $readonlyProvider->chat(
    new \NeuronAI\Chat\Messages\UserMessage('你好'), $first->message(),
    new \NeuronAI\Chat\Messages\ToolResultMessage([$call]),
);
ok($second->message() instanceof \NeuronAI\Chat\Messages\AssistantMessage
    && $second->message()->getContent() === '最终答案' && $rounds === 2,
    'provider: readonly tool result completes the answer');

// The caller still wires only readonly tools when retrieval is absent and only
// requires a retrieval execution when the tool exists.
$callerSource = (string) file_get_contents($repositoryRoot . '/app/Infrastructure/Agent/NeuronAgentCaller.php');
ok(str_contains($callerSource, '$tool===null?$readonlyTools:[$tool,...$readonlyTools]'),
    'caller: null retrieval wires readonly tools only');
ok(str_contains($callerSource, '$tool!==null && $tool->executions()===[]'),
    'caller: retrieval execution required only when wired');
$requestSource = (string) file_get_contents($repositoryRoot . '/app/Infrastructure/Provider/AgentToolChatRequest.php');
ok(str_contains($requestSource, "['_agent_definition'] ?? null) === null")
    && str_contains($requestSource, "['knowledge_base_code'] ?? null) !== null"),
    'request: scope mirrors the frozen wiring rule');

// The trial dialog renders the returned error for any terminal submit state
// and keeps polling for in-flight states.
$trialSource = (string) file_get_contents(
    $repositoryRoot . '/sandadmin-artd/src/views/plugin/sand-ai/agent/modules/AgentTrialDialog.vue');
ok(str_contains($trialSource, 'isAgentTrialTerminal(result.state)'),
    'trial: terminal submit state renders the error');
ok(str_contains($trialSource, 'trial-answer--error'),
    'trial: failed turn keeps the error display');
ok(str_contains($trialSource, "turn.status === 'thinking' && typeof result.task_id === 'number'"),
    'trial: in-flight submit state keeps polling');

echo "sandai-050-b1-agent-no-knowledge passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
