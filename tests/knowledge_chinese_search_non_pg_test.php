<?php

declare(strict_types=1);

$appRoot = dirname(__DIR__) . '/app/';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (!str_starts_with($class, 'app\\')) return;
    $path = $appRoot . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use app\Application\Knowledge\KnowledgeSearchExpression;

$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Knowledge Chinese search check ' . ($checks + 1) . ' failed');
    ++$checks;
};

$terms = KnowledgeSearchExpression::chineseBigrams('劳动合同解除');
$assert($terms === ['劳动', '动合', '合同', '同解', '解除']);
$search = KnowledgeSearchExpression::build('劳动合同解除');
$assert(count($search['score_binds']) === 8 && count($search['match_binds']) === 7);
$assert(substr_count($search['score_sql'], '?') === count($search['score_binds']));
$assert(substr_count($search['match_sql'], '?') === count($search['match_binds']));
$assert(in_array('解除', $search['match_binds'], true));
$assert(str_contains($search['score_sql'], '0.160000') && str_contains($search['score_sql'], 'THEN 1 ELSE 0'));
$assert(KnowledgeSearchExpression::chineseBigrams('English words only') === []);
$long = KnowledgeSearchExpression::build(str_repeat('一二三四五六七八九十', 8));
$assert(count($long['match_binds']) <= 10);
$escaped = KnowledgeSearchExpression::build('税率%_调整');
$assert($escaped['score_binds'][2] === '税率\\%\\_调整');
$assert($escaped['match_binds'][1] === '税率\\%\\_调整');
$assert(!str_contains($escaped['match_sql'], '税率'));
echo 'knowledge-chinese-search ' . $checks . '/' . $checks . PHP_EOL;
