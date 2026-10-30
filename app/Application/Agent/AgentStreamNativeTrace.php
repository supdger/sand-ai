<?php

declare(strict_types=1);

namespace app\Application\Agent;

use app\Application\Task\TaskExecutionLease;
use app\Infrastructure\Persistence\Model\TaskRun;

/** Opt-in operator-private metadata from untouched SDK chunks; production default is off. */
final class AgentStreamNativeTrace
{
    private const KEYS = ['native_chunk_no', 'text_byte_start', 'text_byte_end', 'mask_match_crossed_native_boundary'];
    private mixed $handle = null;
    private bool $disabled = false;
    private int $records = 0;
    private int $bytes = 0;
    private int $lastChunk = 0;
    private int $lastEnd = 0;

    private function __construct(private readonly string $directory, private readonly string $path) {}

    public static function forRound(TaskRun $task, TaskExecutionLease $lease, int $round): ?\Closure
    {
        if (getenv('SAND_AI_AGENT_STREAM_NATIVE_TRACE') !== '1'
            || getenv('SAND_AI_AGENT_STREAM_NATIVE_TRACE_ENVIRONMENT_ID') !== (string) $task->environment_id
            || !function_exists('posix_geteuid')
            || (int) $task->id !== $lease->taskId || $round < 1 || $round > 3) return null;
        $directory = runtime_path('sand-ai-native-stream-private');
        $writer = new self($directory, $directory . '/task-' . $lease->taskId . '-round-' . $round . '-fence-' . $lease->fence . '.jsonl');
        return static function (array $metadata) use ($writer): void { $writer->append($metadata); };
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) fclose($this->handle);
    }

    private function append(array $metadata): void
    {
        if ($this->disabled) return;
        $locked = false;
        try {
            if (!self::validMetadata($metadata)) throw new \RuntimeException();
            if (!is_resource($this->handle)) $this->open();
            if (!flock($this->handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException();
            $locked = true;
            $this->assertDirectory();
            $info = $this->assertHandle();
            if ($this->records === 0 && $this->bytes === 0 && $info['size'] > 0) $this->loadExisting();
            $info = $this->assertHandle();
            $line = json_encode($metadata, JSON_THROW_ON_ERROR) . "\n";
            if ($info['size'] !== $this->bytes || $this->records >= 4096 || $this->bytes + strlen($line) > 1048576
                || $metadata['native_chunk_no'] <= $this->lastChunk || $metadata['text_byte_start'] !== $this->lastEnd) {
                throw new \RuntimeException();
            }
            if (fseek($this->handle, 0, SEEK_END) !== 0 || fwrite($this->handle, $line) !== strlen($line)
                || !fflush($this->handle)) throw new \RuntimeException();
            $this->records++;
            $this->bytes += strlen($line);
            $this->lastChunk = $metadata['native_chunk_no'];
            $this->lastEnd = $metadata['text_byte_end'];
        } catch (\Throwable) {
            // Optional diagnostics never alter a stream, paid receipt, ordering or redispatch.
            $this->disabled = true;
        } finally {
            if ($locked) flock($this->handle, LOCK_UN);
            if ($this->disabled && is_resource($this->handle)) {
                fclose($this->handle);
                $this->handle = null;
            }
        }
    }

    private function open(): void
    {
        $this->assertParentChain();
        clearstatcache(true, $this->directory);
        if (@lstat($this->directory) === false && !mkdir($this->directory, 0700)) throw new \RuntimeException();
        $this->assertDirectory();
        clearstatcache(true, $this->path);
        $named = @lstat($this->path);
        $mode = $named === false ? 'x+b' : 'r+b';
        if ($named !== false && (($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1
            || $named['uid'] !== posix_geteuid() || ($named['mode'] & 07777) !== 0600)) throw new \RuntimeException();
        $oldMask = umask(0077);
        try { $this->handle = fopen($this->path, $mode); }
        finally { umask($oldMask); }
        if (!is_resource($this->handle)) throw new \RuntimeException();
        $this->assertHandle();
    }

    private function assertParentChain(): void
    {
        for ($parent = dirname($this->directory); ; $parent = dirname($parent)) {
            clearstatcache(true, $parent);
            $info = @lstat($parent);
            if (!is_array($info) || ($info['mode'] & 0170000) !== 0040000) throw new \RuntimeException();
            if ($parent === dirname($parent)) break;
        }
    }

    private function assertDirectory(): void
    {
        $this->assertParentChain();
        clearstatcache(true, $this->directory);
        $info = @lstat($this->directory);
        if (!is_array($info) || ($info['mode'] & 0170000) !== 0040000
            || $info['uid'] !== posix_geteuid() || ($info['mode'] & 07777) !== 0700) throw new \RuntimeException();
    }

    private function assertHandle(): array
    {
        $info = fstat($this->handle);
        clearstatcache(true, $this->path);
        $named = @lstat($this->path);
        if (!is_array($info) || !is_array($named) || ($info['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000 || ($info['mode'] & 07777) !== 0600
            || ($named['mode'] & 07777) !== 0600 || $info['nlink'] !== 1 || $named['nlink'] !== 1
            || $info['uid'] !== posix_geteuid() || $named['uid'] !== posix_geteuid()
            || $info['dev'] !== $named['dev'] || $info['ino'] !== $named['ino']
            || $info['size'] > 1048576) throw new \RuntimeException();
        return $info;
    }

    private function loadExisting(): void
    {
        if (fseek($this->handle, 0) !== 0) throw new \RuntimeException();
        $bytes = stream_get_contents($this->handle, 1048577);
        if (!is_string($bytes) || strlen($bytes) > 1048576 || !str_ends_with($bytes, "\n")) throw new \RuntimeException();
        foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
            $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($row) || !self::validMetadata($row) || ++$this->records > 4096
                || $row['native_chunk_no'] <= $this->lastChunk || $row['text_byte_start'] !== $this->lastEnd) throw new \RuntimeException();
            $this->lastChunk = $row['native_chunk_no'];
            $this->lastEnd = $row['text_byte_end'];
        }
        $this->bytes = strlen($bytes);
    }

    private static function validMetadata(array $metadata): bool
    {
        return array_keys($metadata) === self::KEYS
            && is_int($metadata['native_chunk_no']) && $metadata['native_chunk_no'] > 0
            && is_int($metadata['text_byte_start']) && $metadata['text_byte_start'] >= 0
            && is_int($metadata['text_byte_end']) && $metadata['text_byte_end'] > $metadata['text_byte_start']
            && is_bool($metadata['mask_match_crossed_native_boundary']);
    }
}
