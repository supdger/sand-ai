<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $appRoot . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeAnswerPolicy;
use app\Application\Knowledge\KnowledgeConversationPolicy;
use app\Identity\Contract\IdentityContext;

$checks = 0;
$assert = static function (bool $ok) use (&$checks): void {
    if (!$ok) throw new RuntimeException('Knowledge conversation policy failed at ' . ($checks + 1));
    ++$checks;
};
$identity = new IdentityContext('11', '22', 33, '44', 'sand-ai', ['sand_ai.knowledge.query', 'sand_ai.chat.complete']);
$scope = KnowledgeConversationPolicy::ownerScope($identity);
$assert((bool) preg_match('/^[a-f0-9]{64}$/', $scope));
$publication = ['id' => 50, 'active_version_id' => 60, 'source_scope_sha256' => str_repeat('a', 64), 'data_class' => 'internal'];
$conversation = [
    'environment_id' => 33, 'owner_scope' => $scope, 'publication_id' => 50,
    'knowledge_base_version_id' => 60, 'source_scope_sha256' => str_repeat('a', 64),
    'data_class' => 'internal', 'state' => 'active',
];
KnowledgeConversationPolicy::assertBound($conversation, $publication, $identity);
$assert(true);
$reject = static function (array $row, array $pub, IdentityContext $who) use ($assert): void {
    try {
        KnowledgeConversationPolicy::assertBound($row, $pub, $who);
        throw new RuntimeException('Conversation crossed an ownership boundary');
    } catch (ApiProblem $problem) {
        $assert($problem->errorCode === 'SAND_AI_RESOURCE_NOT_FOUND');
    }
};
$reject($conversation, $publication, new IdentityContext('11', '22', 33, '45', 'sand-ai', []));
$reject($conversation, $publication, new IdentityContext('12', '22', 33, '44', 'sand-ai', []));
$reject($conversation, [...$publication, 'active_version_id' => 61], $identity);
$reject([...$conversation, 'state' => 'closed'], $publication, $identity);
$closed = [...$conversation, 'state' => 'closed'];
KnowledgeConversationPolicy::assertReadable($closed, $publication, $identity);
$assert(true);
try {
    KnowledgeConversationPolicy::assertReadable($closed, $publication, new IdentityContext('11', '22', 33, '45', 'sand-ai', []));
    throw new RuntimeException('Closed conversation crossed an ownership boundary');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_RESOURCE_NOT_FOUND');
}
try {
    KnowledgeConversationPolicy::assertReadable($closed, [...$publication, 'active_version_id' => 61], $identity);
    throw new RuntimeException('Closed conversation crossed a publication version boundary');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_RESOURCE_NOT_FOUND');
}
$history = KnowledgeConversationPolicy::history([
    ['query' => '旧问题', 'answer' => '旧回答', 'state' => 'answered'],
    ['query' => '无来源', 'answer' => null, 'state' => 'no_sources'],
    ['query' => '本轮', 'answer' => '可用回答', 'state' => 'answered'],
]);
$assert(count($history) === 2 && $history[1]['query'] === '本轮');
$messages = KnowledgeAnswerPolicy::messages('继续问', [['source_block_id' => 31, 'excerpt' => '当前来源']], $history);
$payload = json_decode($messages[1]['content'], true, 8, JSON_THROW_ON_ERROR);
$assert($payload['recent_conversation'] === $history && $payload['sources'][0]['source_block_id'] === 31);
$older = [];
for ($sequence = 1; $sequence <= 13; $sequence++) {
    $older[] = ['sequence_no' => $sequence, 'query' => '问题' . $sequence,
        'answer' => '旧回答' . $sequence, 'state' => 'answered'];
}
$context = KnowledgeConversationPolicy::context($older);
$assert($context['coverage']['has_omitted_earlier_answered'] === true);
$assert($context['coverage']['earlier_question_sequences'] === range(2, 9));
$assert($context['coverage']['recent_answered_sequences'] === range(10, 13));
$assert(count($context['earlier_questions']) === 8 && count($context['history']) === 4);
$messages = KnowledgeAnswerPolicy::messages('继续问', [['source_block_id' => 31, 'excerpt' => '当前来源']],
    $context['history'], $context['earlier_questions']);
$payload = json_decode($messages[1]['content'], true, 8, JSON_THROW_ON_ERROR);
$assert($payload['earlier_question_digest'][0]['sequence_no'] === 2
    && $payload['recent_conversation'][0]['query'] === '问题10');
$assert(!str_contains($messages[1]['content'], '旧回答2'));
$short = KnowledgeConversationPolicy::context(array_slice($older, 0, 2));
$assert($short['coverage']['has_omitted_earlier_answered'] === false
    && $short['coverage']['earlier_question_sequences'] === []);
$withGap = KnowledgeConversationPolicy::context([
    ['sequence_no' => 1, 'query' => '首问', 'answer' => '首答', 'state' => 'answered'],
    ['sequence_no' => 2, 'query' => '无来源', 'answer' => null, 'state' => 'no_sources'],
    ['sequence_no' => 3, 'query' => '续问', 'answer' => '续答', 'state' => 'answered'],
]);
$assert($withGap['coverage']['recent_answered_sequences'] === [1, 3]);
try {
    KnowledgeAnswerPolicy::messages('继续问', [['source_block_id' => 31, 'excerpt' => '当前来源']],
        [], array_fill(0, 9, ['sequence_no' => 1, 'query' => '旧问题']));
    throw new RuntimeException('Oversized earlier question digest was accepted');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_KNOWLEDGE_VALIDATION_ERROR');
}
$states = [
    [['state' => 'pending', 'claim_active' => 1], 'preparing'],
    [['state' => 'pending', 'claim_active' => 0], 'recovery_required'],
    [['state' => 'pending', 'claim_active' => 1, 'invocation_state' => 'running', 'dispatch_state' => 'dispatching'], 'provider_pending'],
    [['state' => 'pending', 'claim_active' => 0, 'invocation_state' => 'running', 'dispatch_state' => 'dispatching'], 'outcome_unknown'],
    [['state' => 'pending', 'claim_active' => 1, 'invocation_state' => 'running', 'dispatch_state' => 'ambiguous'], 'outcome_unknown'],
    [['state' => 'pending', 'claim_active' => 1, 'invocation_state' => 'succeeded', 'dispatch_state' => 'succeeded'], 'finalizing'],
    [['state' => 'pending', 'claim_active' => 0, 'invocation_state' => 'succeeded', 'dispatch_state' => 'succeeded'], 'recovery_required'],
    [['state' => 'answered'], 'completed'],
    [['state' => 'needs_review'], 'completed'],
    [['state' => 'failed'], 'failed'],
];
foreach ($states as [$turn, $expected]) {
    $assert(KnowledgeConversationPolicy::executionState($turn) === $expected);
}
echo 'knowledge-conversation-policy ' . $checks . '/' . $checks . PHP_EOL;
