<?php

declare(strict_types=1);

/**
 * Isolated 0.5.0 knowledge-list tests: no database, no SandIAM plugin, no
 * network. Covers the 最近更新 projection semantics — the product-facing
 * timestamp is the maximum of the knowledge-base row update, the current
 * draft version creation and the published version publish time. The SQL
 * projection itself is covered by repository review.
 *
 * Usage: php tests/sandai-050-b1-knowledge-list-test.php
 */

require_once dirname(__DIR__) . '/app/Application/Knowledge/KnowledgeBaseGateway.php';

use app\Application\Knowledge\KnowledgeBaseGateway;

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

$method = new ReflectionMethod(KnowledgeBaseGateway::class, 'recentUpdateTime');
$method->setAccessible(true);
$recent = static fn (array $row): ?string => $method->invoke(null, $row);

// Draft creation is the newest signal while a draft is open.
ok($recent([
    'update_time' => '2026-11-01 09:00:00',
    'draft_create_time' => '2026-11-10 08:30:00',
    'active_published_time' => '2026-11-05 10:00:00',
]) === '2026-11-10 08:30:00', 'recent update: draft creation wins');

// A fresh publish beats an older draft and the base row save.
ok($recent([
    'update_time' => '2026-11-10 09:00:00',
    'draft_create_time' => '2026-11-09 08:30:00',
    'active_published_time' => '2026-11-10 09:00:00',
]) === '2026-11-10 09:00:00', 'recent update: publish time ties base update');

// Base row mutation (publish/disable) is the newest signal without drafts.
ok($recent([
    'update_time' => '2026-11-11 12:00:00',
    'draft_create_time' => null,
    'active_published_time' => '2026-11-01 09:00:00',
]) === '2026-11-11 12:00:00', 'recent update: base update wins without draft');

// Never-published draft-only base still reports its creation time.
ok($recent([
    'update_time' => '2026-11-01 09:00:00',
    'draft_create_time' => '2026-11-02 09:00:00',
    'active_published_time' => null,
]) === '2026-11-02 09:00:00', 'recent update: draft-only base uses draft time');

// Missing optional columns degrade to the base update time.
ok($recent(['update_time' => '2026-11-03 09:00:00']) === '2026-11-03 09:00:00', 'recent update: missing draft/publish falls back');
ok($recent(['update_time' => '2026-11-03 09:00:00', 'draft_create_time' => '', 'active_published_time' => null]) === '2026-11-03 09:00:00', 'recent update: blank draft ignored');
ok($recent([]) === null, 'recent update: empty row yields null');
ok($recent(['update_time' => null, 'draft_create_time' => null, 'active_published_time' => null]) === null, 'recent update: all null yields null');

// Drivers may hand back DateTimeInterface values; they normalise to the same string shape.
ok($recent([
    'update_time' => new DateTimeImmutable('2026-11-04 10:00:00'),
    'draft_create_time' => '2026-11-05 10:00:00',
]) === '2026-11-05 10:00:00', 'recent update: DateTime values normalised');

echo "sandai-050-b1-knowledge-list passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
