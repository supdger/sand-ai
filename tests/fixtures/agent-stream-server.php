<?php

declare(strict_types=1);

/**
 * Isolated SSE endpoint for the pinned agent-stream transport tests. Run as
 * `php -S 127.0.0.1:<port> tests/fixtures/agent-stream-server.php`.
 *
 * POST bodies are captured into the file named by SANDAI_STREAM_CAPTURE so
 * the test asserts the exact wire body; the `mode` query parameter selects
 * which recorded exchange is replayed.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $capture = getenv('SANDAI_STREAM_CAPTURE');
    if (is_string($capture) && $capture !== '') {
        file_put_contents($capture, (string) file_get_contents('php://input'));
    }
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$eventId = 'chatcmpl-sandai-test';
$choice = static fn (array|object $delta, ?string $finish = null): array => $finish === null
    ? ['index' => 0, 'delta' => $delta]
    : ['index' => 0, 'delta' => $delta, 'finish_reason' => $finish];
$frame = static fn (array $choice): array => ['id' => $eventId, 'choices' => [$choice]];

$mode = $_GET['mode'] ?? 'text';
$frames = match ($mode) {
    // A provider sneaking a tool call into a tool-free request.
    'tool_calls' => [
        $frame($choice(['role' => 'assistant'])),
        $frame($choice(['tool_calls' => [[
            'index' => 0, 'id' => 'call_1', 'type' => 'function',
            'function' => ['name' => 'managed_' . str_repeat('0', 32), 'arguments' => '{}'],
        ]]])),
    ],
    // A tool-free request must not finish on the tool_calls reason.
    'finish_tool_calls' => [
        $frame($choice(['role' => 'assistant'])),
        $frame($choice(['content' => '半截回答'])),
        $frame($choice(new \stdClass(), 'tool_calls')),
    ],
    // The recorded pure-text round trip: role, content slices, stop, usage.
    default => [
        $frame($choice(['role' => 'assistant'])),
        $frame($choice(['content' => '你好'])),
        $frame($choice(['content' => '，世界'])),
        $frame($choice(new \stdClass(), 'stop')),
        ['id' => $eventId, 'choices' => [], 'usage' => [
            'prompt_tokens' => 3, 'completion_tokens' => 5, 'total_tokens' => 8,
        ]],
    ],
};

foreach ($frames as $frame) {
    echo 'data: ' . json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
}
echo "data: [DONE]\n\n";
flush();
