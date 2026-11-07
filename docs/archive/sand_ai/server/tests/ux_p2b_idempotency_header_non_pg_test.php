<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// UX P2B D1 contract checks that do not need PostgreSQL:
// 1) the aiTask submit call carries both X-Request-Id (audit trace) and the
//    Idempotency-Key required by AiTaskController / RequestIdempotencyKey::required;
// 2) every other frontend mutating callsite that hits a backend endpoint with a
//    mandatory Idempotency-Key still sends it (regression sweep for the same
//    defect class);
// 3) the API contract names Idempotency-Key for POST /ai-tasks.

$root = dirname(__DIR__);
$frontend = dirname($root) . '/sandadmin-artd/src/views/plugin/sand-ai';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('UX P2B idempotency-header check failed: ' . $label);
    }
    ++$checks;
};

// ==== A: the fixed aiTask.ts call ====

$aiTaskPath = $frontend . '/api/aiTask.ts';
$assert(is_file($aiTaskPath), 'aiTask api file exists');
$aiTask = (string) file_get_contents($aiTaskPath);
$assert(str_contains($aiTask, "path: '/ai-tasks'"), 'submit targets /ai-tasks');
$assert(str_contains($aiTask, "'X-Request-Id': requestId"), 'submit keeps the audit trace header');
$assert(str_contains($aiTask, "'Idempotency-Key': requestId"), 'submit sends the required Idempotency-Key');
$assert(str_contains($aiTask, "{ 'X-Request-Id': requestId, 'Idempotency-Key': requestId }"),
    'both headers share the same caller-retained key (agent.ts pattern)');

// ==== B: every required mutating callsite sends Idempotency-Key ====

/**
 * Frontend api methods whose backend endpoint hard-requires a non-empty
 * Idempotency-Key header (RequestIdempotencyKey::required/requiredForResource
 * or the admin key()/write()/mutate() guards). Each entry is checked by locating
 * the method/function definition and scanning its body for the header.
 *
 * Deliberately excluded (backend does not require the header for these):
 * file.ts upload/parse/destroy (X-Request-Id contract), retrieval.ts search,
 * skill.ts runPublished (body request_id), knowledgeConversation resume/cancel/
 * close, task.ts plain cancel/retry, legalAnalysis snapshot, adminTrial context.
 *
 * @var array<string, list<string>>
 */
$requiredCallers = [
    'aiTask.ts' => ['submit'],
    'chat.ts' => ['complete'],
    'audioTranscription.ts' => ['submit'],
    'speech.ts' => ['submit'],
    'audioReview.ts' => ['save'],
    'channelBinding.ts' => ['submitMessage', 'recoverReceipt', 'closeConversation'],
    'agent.ts' => ['submitPublished', 'submit'],
    'task.ts' => [
        'retryFileParseBeforeDispatch', 'correctMeetingOutput', 'continueAgent',
        'continueAuthorization', 'recoverReviewReceipt', 'submitMeetingSummary',
    ],
    'knowledgeConversation.ts' => ['create', 'answer'],
    'knowledgeQuery.ts' => ['readQuery'],
    'toolTrial.ts' => ['run'],
    'legalAnalysis.ts' => ['submit'],
    'materialProfile.ts' => ['submitPublished', 'submitSample'],
    'composition.ts' => ['save', 'publish', 'disable', 'submit', 'purgeInput', 'action'],
    'consumerSkill.ts' => ['post', 'purgeSuggestion'],
];

// composition.ts centralizes the header in `const headers = (key) => ({
// 'Idempotency-Key': key })`; its methods send `headers(key)` instead of a
// literal. The helper itself is asserted once below.
$headerToken = ['composition.ts' => 'headers('];

foreach ($requiredCallers as $file => $methods) {
    $path = $frontend . '/api/' . $file;
    $assert(is_file($path), $file . ' exists');
    $lines = explode("\n", (string) file_get_contents($path));
    $token = $headerToken[$file] ?? "'Idempotency-Key'";
    $definitionPattern = static fn (string $name): string
        => '/^\s{0,2}(?:async\s+)?(?:function\s+)?' . preg_quote($name, '/') . '\s*[<(]/';
    $boundaryPattern = '/^\s{0,2}(?:async\s+)?(?:function\s+)?[a-zA-Z_]+\s*[<(]/';
    foreach ($methods as $method) {
        $found = false;
        foreach ($lines as $index => $line) {
            // Method/function definitions only; dotted call sites (api.foo() )
            // can never match because the name must lead the line.
            if (preg_match($definitionPattern($method), $line) !== 1) {
                continue;
            }
            // The body ends at the next top-level/sibling definition, not at a
            // fixed window: multi-line signatures and request envelopes vary.
            $end = $index + 1;
            while ($end < count($lines) && preg_match($boundaryPattern, $lines[$end]) !== 1) {
                ++$end;
            }
            $body = implode("\n", array_slice($lines, $index, $end - $index));
            $assert(str_contains($body, $token),
                $file . '::' . $method . ' sends Idempotency-Key');
            $found = true;
            break;
        }
        $assert($found, $file . '::' . $method . ' definition located');
    }
}

$assert(str_contains((string) file_get_contents($frontend . '/api/composition.ts'),
    "'Idempotency-Key': key"), 'composition.ts headers() helper emits Idempotency-Key');

// ==== C: API contract names the required header for POST /ai-tasks ====

$contractPath = dirname($root) . '/docs/development/sand-ai-api-contract.md';
$assert(is_file($contractPath), 'api contract exists');
$contract = (string) file_get_contents($contractPath);
$aiTasksRow = null;
foreach (explode("\n", $contract) as $contractLine) {
    if (str_contains($contractLine, 'POST /api/sand-ai/v1/ai-tasks')) {
        $aiTasksRow = $contractLine;
        break;
    }
}
$assert($aiTasksRow !== null, 'contract lists POST /ai-tasks');
$assert(str_contains((string) $aiTasksRow, 'Idempotency-Key'), 'contract row requires Idempotency-Key');

fwrite(STDOUT, 'ux_p2b_idempotency_header_non_pg_test: ' . $checks . " checks passed\n");
