<?php

declare(strict_types=1);

/**
 * 删除守卫：已启用的渠道和工作流不能直接删，停用后可以删。
 *
 * Usage: php tests/sandai-050-repair-delete-guard-test.php
 */

require_once dirname(__DIR__) . '/app/Api/Support/ApiProblem.php';
require_once dirname(__DIR__) . '/app/Application/Channel/ChannelBindingPolicy.php';
require_once dirname(__DIR__) . '/app/Application/Composition/CompositionDefinitionGateway.php';

use app\Application\Channel\ChannelBindingPolicy;
use app\Application\Composition\CompositionDefinitionGateway;

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

ok(ChannelBindingPolicy::retireRefusal(1) === '先停用才能删', 'enabled channel refuses delete');
ok(ChannelBindingPolicy::retireRefusal(2) === null, 'disabled channel can be deleted');
ok(ChannelBindingPolicy::retireRefusal(0) === '这条渠道现在不能删', 'unknown channel status refuses delete');
ok(CompositionDefinitionGateway::retireBlocked('published') === '先停用才能删', 'published workflow refuses delete');
ok(CompositionDefinitionGateway::retireBlocked('disabled') === null, 'disabled workflow can be deleted');
ok(CompositionDefinitionGateway::retireBlocked('draft') === null, 'draft workflow can be deleted');

echo "passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
