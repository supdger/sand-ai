<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'app\\')) return;
    $relative = str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($appRoot . $relative)) {
        require $appRoot . $relative;
    }
});

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeAnswerPolicy;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Knowledge answer policy failed at ' . ($checks + 1));
    ++$checks;
};
$input = KnowledgeAnswerPolicy::normalize(['query' => ' 问题 ', 'model' => ' sample ']);
$assert($input === ['query' => '问题', 'model' => 'sample', 'max_output_tokens' => 512]);
try {
    KnowledgeAnswerPolicy::normalize(['query' => '问题', 'model' => 'sample', 'environment_id' => 3]);
    throw new RuntimeException('Caller ownership input was accepted');
} catch (ApiProblem $problem) {
    $assert($problem->errorCode === 'SAND_AI_KNOWLEDGE_VALIDATION_ERROR');
}
$available = [
    ['source_block_id' => 31, 'excerpt' => '来源一', 'file_id' => 7],
    ['source_block_id' => 42, 'excerpt' => '来源二', 'file_id' => 8],
];
$messages = KnowledgeAnswerPolicy::messages('问题', $available);
$assert(count($messages) === 2 && $messages[0]['role'] === 'system');
$assert(str_contains($messages[1]['content'], '来源一') && !str_contains($messages[1]['content'], 'file_id'));
$answer = KnowledgeAnswerPolicy::result('{"citations":[42],"answer":"有依据的回答"}', $available);
$assert($answer['answer_state'] === 'answered' && $answer['review_required'] === true);
$assert(count($answer['citations']) === 1 && $answer['citations'][0]['source_block_id'] === 42);
$assert(KnowledgeAnswerPolicy::result('{"answer":"虚构","citations":[99]}', $available)['answer'] === null);
$assert(KnowledgeAnswerPolicy::result('{"answer":"重复","citations":[31,31]}', $available)['answer_state'] === 'needs_review');
$assert(KnowledgeAnswerPolicy::result('```json', $available)['citations'] === []);
echo 'knowledge-answer-policy ' . $checks . '/' . $checks . PHP_EOL;
