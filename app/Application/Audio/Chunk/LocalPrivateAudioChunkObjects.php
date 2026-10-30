<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use app\Api\Support\ApiProblem;

/** Local, single shared filesystem backend. All reads/writes/deletes use the same stable lock stripes. */
final readonly class LocalPrivateAudioChunkObjects
{
    public string $root;
    private string $instanceFingerprint;

    public function __construct(string $privateRoot, public string $storeCode, private int $timeoutSeconds = 120)
    {
        $root = realpath($privateRoot);
        if (PHP_OS_FAMILY === 'Windows' || $root === false || !is_dir($root) || !is_writable($root) || is_link($privateRoot) || (fileperms($root) & 0077) !== 0
            || preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/D', $storeCode) !== 1
            || $timeoutSeconds < 1 || $timeoutSeconds > 300) self::unavailable();
        if (function_exists('public_path')) {
            $public = realpath(public_path());
            if (is_string($public) && ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR))) self::unavailable();
        }
        // Root must be separately provisioned as private; constructor never creates public paths.
        $this->root = $root;
        $this->initializeIdentity();
        $this->instanceFingerprint = $this->readRootFingerprint();
    }

    /** @template T @param \Closure():T $operation @return T */
    public function locked(string $reference, \Closure $operation): mixed
    {
        $this->rootFingerprint();
        $this->assertRef($reference);
        $lockPath = $this->root . '/.audio-lock-' . substr(hash('sha256', $reference), 0, 2);
        if (is_link($lockPath)) self::unavailable();
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) self::unavailable();
        $started = hrtime(true);
        try {
            if (!chmod($lockPath, 0600)) self::unavailable();
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) - $started > 2_000_000_000) self::unavailable();
                usleep(20000);
            }
            $this->rootFingerprint();
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called only while locked(). Existing full content is reusable; mismatched bytes are never overwritten. */
    public function put(string $reference, string $inputPath, int $bytes, string $sha256): void
    {
        $path = $this->path($reference);
        $this->assertEvidence($bytes, $sha256);
        if (is_file($path)) {
            $this->verify($reference, $bytes, $sha256);
            return;
        }
        $source = fopen($inputPath, 'rb');
        if ($source === false) self::unavailable();
        $output = null;
        $created = false;
        $complete = false;
        $started = hrtime(true);
        try {
            $stat = fstat($source);
            if (!is_array($stat) || $stat['size'] !== $bytes) self::unavailable();
            $output = fopen($path, 'x+b');
            if ($output === false) self::unavailable();
            $created = true;
            if (!chmod($path, 0600)) self::unavailable();
            $hash = hash_init('sha256');
            $remaining = $bytes;
            while ($remaining > 0) {
                if (hrtime(true) - $started > $this->timeoutSeconds * 1_000_000_000) self::unavailable();
                $part = fread($source, min(65536, $remaining));
                if (!is_string($part) || $part === '') self::unavailable();
                hash_update($hash, $part);
                $offset = 0;
                while ($offset < strlen($part)) {
                    $written = fwrite($output, substr($part, $offset));
                    if (!is_int($written) || $written < 1) self::unavailable();
                    $offset += $written;
                }
                $remaining -= strlen($part);
            }
            if (fread($source, 1) !== '' || !hash_equals($sha256, hash_final($hash))
                || !fflush($output) || !fsync($output)) self::unavailable();
            $this->syncDirectory();
            $complete = true;
        } finally {
            fclose($source);
            if (is_resource($output)) fclose($output);
            if ($created && !$complete) @unlink($path);
        }
    }

    /** The callback must finish using the path before it returns. Paths never become API fields. */
    public function withVerifiedPath(string $reference, int $bytes, string $sha256, \Closure $operation): mixed
    {
        $this->verify($reference, $bytes, $sha256);
        return $operation($this->path($reference));
    }

    public function delete(string $reference): void
    {
        $path = $this->path($reference);
        if (file_exists($path) && !unlink($path)) self::unavailable();
        $this->syncDirectory();
    }

    private function verify(string $reference, int $bytes, string $sha256): void
    {
        $this->assertEvidence($bytes, $sha256);
        $path = $this->path($reference);
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) !== $bytes || !hash_equals($sha256, (string) hash_file('sha256', $path))) self::unavailable();
    }

    private function path(string $reference): string
    {
        $this->rootFingerprint();
        $this->assertRef($reference);
        $path = $this->root . '/' . $reference . '.wav';
        if (is_link($path)) self::unavailable();
        return $path;
    }

    private function assertRef(string $reference): void
    {
        if (preg_match('/\Asaac_[a-f0-9]{32}\z/D', $reference) !== 1) self::unavailable();
    }

    private function assertEvidence(int $bytes, string $sha256): void
    {
        if ($bytes < 46 || $bytes > 44 + AudioChunkPlan::MAX_SAMPLES * 2 || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) self::unavailable();
    }

    private function syncDirectory(): void
    {
        $directory = fopen($this->root, 'r');
        if ($directory === false) self::unavailable();
        try { if (!fsync($directory)) self::unavailable(); }
        finally { fclose($directory); }
    }

    /** Stable local storage identity, distinct from an operator's reusable store code. */
    public function rootFingerprint(): string
    {
        $current = $this->readRootFingerprint();
        if (!hash_equals($this->instanceFingerprint, $current)) self::unavailable();
        return $current;
    }

    private function initializeIdentity(): void
    {
        $path = $this->root . '/.sand-ai-chunk-instance';
        if (is_link($path)) self::unavailable();
        $file = fopen($path, 'c+b');
        if ($file === false) self::unavailable();
        $started = hrtime(true);
        try {
            while (!flock($file, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) - $started > 2_000_000_000) self::unavailable();
                usleep(20000);
            }
            $stat = fstat($file);
            if (!is_array($stat)) self::unavailable();
            if ($stat['size'] === 0) {
                if (!chmod($path, 0600) || fwrite($file, bin2hex(random_bytes(32))) !== 64
                    || !fflush($file) || !fsync($file)) self::unavailable();
                $this->syncDirectory();
            }
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    private function readRootFingerprint(): string
    {
        $path = $this->root . '/.sand-ai-chunk-instance';
        clearstatcache(true, $this->root);
        clearstatcache(true, $path);
        $stat = lstat($this->root);
        if (!is_array($stat) || !is_dir($this->root) || is_link($this->root) || is_link($path)
            || !is_file($path) || filesize($path) !== 64 || (fileperms($path) & 0077) !== 0
            || ($stat['mode'] & 0077) !== 0) self::unavailable();
        $identity = file_get_contents($path, false, null, 0, 65);
        $host = gethostname();
        if (!is_string($host) || $host === '' || !is_string($identity)
            || preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1) self::unavailable();
        return hash('sha256', json_encode(['local_single_host/v1', $host, $this->root,
            (string) $stat['dev'], (string) $stat['ino'], $identity], JSON_THROW_ON_ERROR));
    }

    private static function unavailable(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_CHUNK_STORAGE_UNAVAILABLE', 'Private audio chunk storage is unavailable', true);
    }
}
