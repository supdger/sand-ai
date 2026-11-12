<?php

declare(strict_types=1);

/**
 * 知识库名称：空名称和超长名称要拒绝，合法名称原样保留。
 *
 * Usage: php tests/knowledge-rename-name-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Knowledge/KnowledgeBasePolicy.php';

use app\Api\Support\ApiProblem;
use app\Application\Knowledge\KnowledgeBasePolicy;

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

function rejected(string $name): bool
{
    try {
        KnowledgeBasePolicy::normalizeName($name);
        return false;
    } catch (ApiProblem $problem) {
        return $problem->errorCode === 'SAND_AI_KNOWLEDGE_VALIDATION_ERROR'
            && str_contains($problem->getMessage(), '名称不能为空');
    }
}

ok(KnowledgeBasePolicy::normalizeName('  演示知识库  ') === '演示知识库', 'trims a usable name');
ok(rejected(''), 'blank name is rejected');
ok(rejected('   '), 'whitespace name is rejected');
ok(rejected(str_repeat('名', 129)), 'name over 128 characters is rejected');
ok(KnowledgeBasePolicy::normalizeName(str_repeat('名', 128)) === str_repeat('名', 128), 'name of 128 characters is kept');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
