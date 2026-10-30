<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestPayloadFingerprint;
use app\Infrastructure\Persistence\Model\TaskRun;
use Composer\InstalledVersions;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use think\facade\Db;

/** Local text partitioning only. Neither token measurement nor financial capacity proof. */
final class MeetingLocalPlanning
{
    public const DRIVER = 'neuron_delimiter_splitter@1';
    public const SDK_VERSION = '4.0.2';
    public const MIGRATION = '202610040127_meeting_local_planning';
    public const MAX_LENGTH = 12000;

    public static function enabled(array $capacity): bool
    {
        return ($capacity['planning_driver'] ?? null) === self::DRIVER;
    }

    public static function metadata(): array
    {
        return ['driver' => self::DRIVER, 'sdk_package' => 'neuron-core/neuron-ai',
            'sdk_version' => self::SDK_VERSION, 'max_length' => self::MAX_LENGTH,
            'word_overlap' => 0, 'min_length' => 0, 'token_count' => null,
            'token_count_state' => 'not_measured', 'counter_request_count' => 0];
    }

    /** Strict registration variant; actual generation capacity comes from the independent route proof. */
    public static function capacity(array $capacity, array $route, int $output, ?int $inputCeiling): array
    {
        $keys = ['planning_driver', 'context_window_tokens', 'input_token_limit', 'max_tokenizer_requests',
            'capacity_source_url', 'capacity_source_sha256'];
        if (array_diff(array_keys($capacity), $keys) !== [] || array_diff($keys, array_keys($capacity)) !== []
            || !self::enabled($capacity) || ($capacity['max_tokenizer_requests'] ?? null) !== 0
            || !is_int($capacity['context_window_tokens'] ?? null) || $capacity['context_window_tokens'] < 2
            || $capacity['context_window_tokens'] > 1048576 || !is_int($capacity['input_token_limit'] ?? null)
            || $capacity['input_token_limit'] < 1 || $capacity['input_token_limit'] > $capacity['context_window_tokens'] - $output
            || ($inputCeiling !== null && $capacity['input_token_limit'] > $inputCeiling)
            || !is_string($capacity['capacity_source_url'] ?? null)
            || strlen($capacity['capacity_source_url']) > 2048
            || filter_var($capacity['capacity_source_url'], FILTER_VALIDATE_URL) === false
            || !is_string($capacity['capacity_source_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $capacity['capacity_source_sha256']) !== 1) self::invalid();
        $url = parse_url($capacity['capacity_source_url']);
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) self::invalid();
        self::assertSdk();
        return $capacity;
    }

    /** SDK partition with exact positional reconstruction, no trimming, overlap or loss. */
    public static function partition(array $parts, array $lines): array
    {
        self::assertSdk();
        if (!array_is_list($parts) || !array_is_list($lines) || $parts === [] || count($parts) !== count($lines)
            || count($parts) > 512) self::invalid();
        $delimiter = "\x1e";
        // The delimiter is absent from every rendered part. Doubling terminates
        // within the bounded input size and never changes original text.
        while (array_filter($lines, static fn($line): bool => is_string($line) && str_contains($line, $delimiter)) !== []) {
            $delimiter .= "\x1e";
            if (strlen($delimiter) > 128) self::invalid();
        }
        foreach ($lines as $line) {
            if (!is_string($line) || $line === '' || preg_match('//u', $line) !== 1
                || mb_strlen($line, 'UTF-8') + strlen($delimiter) > self::MAX_LENGTH) self::invalid();
        }
        // Bound includes a separator margin: the later newline rendering is no larger.
        $splitter = new DelimiterTextSplitter(self::MAX_LENGTH - strlen($delimiter), $delimiter, 0, 0);
        $documents = $splitter->splitDocument(new Document(implode($delimiter, $lines)));
        $cursor = 0; $batches = [];
        foreach ($documents as $document) {
            $batch = [];
            foreach (explode($delimiter, $document->getContent()) as $line) {
                if (!isset($lines[$cursor]) || $line !== $lines[$cursor]) self::invalid();
                $batch[] = $parts[$cursor++];
            }
            if ($batch === []) self::invalid();
            $batches[] = $batch;
        }
        if ($cursor !== count($parts)) self::invalid();
        return $batches;
    }

    public static function evidence(array $messages): array
    {
        self::assertSdk();
        self::assertMessages($messages);
        return [...self::metadata(), 'messages_sha256' => RequestPayloadFingerprint::fingerprint($messages, [])];
    }

    public static function verify(array $evidence, array $messages): void
    {
        if (!RequestPayloadFingerprint::same($evidence, self::evidence($messages), [])) self::invalid();
    }

    /** Recheck bounded SDK payload. This makes no assertion about exact model token fit. */
    public static function assertMessages(array $messages): void
    {
        if (!array_is_list($messages) || count($messages) !== 2) self::invalid();
        $bytes = 0;
        foreach ($messages as $index => $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content']) !== []
                || ($message['role'] ?? null) !== ($index === 0 ? 'system' : 'user') || !is_string($message['content'] ?? null)
                || preg_match('//u', $message['content']) !== 1) self::invalid();
            $bytes += strlen($message['content']);
        }
        if ($bytes > 262144) self::invalid();
    }

    /** Zero is a checked absence of actual counter intents, not a zero fee claim. */
    public static function assertNoCounterRequests(TaskRun $task): void
    {
        self::assertSchemaReady();
        $rows = Db::connect('pgsql')->query('SELECT task_id FROM sand_ai_meeting_token_count_request WHERE task_id=? LIMIT 1', [(int) $task->id]);
        if ($rows !== []) self::invalid();
    }

    public static function assertSchemaReady(): void
    {
        $path = base_path('plugin/sand-ai/migrations/main-app/' . self::MIGRATION . '.pgsql');
        $sha = is_file($path) ? hash_file('sha256', $path) : false;
        $rows = Db::connect('pgsql')->query('SELECT checksum FROM sand_ai_schema_migration WHERE version=?', [self::MIGRATION]);
        if (!is_string($sha) || count($rows) !== 1 || !hash_equals($sha, (string) $rows[0]['checksum'])) self::invalid();
    }

    private static function assertSdk(): void
    {
        if (!InstalledVersions::isInstalled('neuron-core/neuron-ai')
            || !version_compare((string) InstalledVersions::getVersion('neuron-core/neuron-ai'), self::SDK_VERSION, '>=')
            || !class_exists(DelimiterTextSplitter::class)) self::invalid();
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_COVERAGE_INCOMPLETE', '本地会议分段规划或来源校验不可用，请保留当前任务及费用记录');
    }
}
