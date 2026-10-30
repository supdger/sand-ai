<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Tool\ToolConnectionSecretCipher;
use Dotenv\Dotenv;
use Illuminate\Database\ConnectionInterface;
use support\Db;

/** Explicit first-use operation; never invoked by config loading or a business request. */
final class InternalSecurityInitialization
{
    private const GROUPS = [
        'provider_config' => ['SAND_AI_CONFIG_KEY'],
        'idempotency' => ['SAND_AI_IDEMPOTENCY_KEY'],
        'queued_identity' => ['SAND_AI_IAM_CONTEXT_CURRENT_KEY_ID', 'SAND_AI_IAM_CONTEXT_CURRENT_KEY'],
        'outcome_receipt' => ['SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY_ID', 'SAND_AI_OUTCOME_RECEIPT_CURRENT_KEY'],
    ];
    // These existing tables retain config encryption/fingerprints, idempotency,
    // queued identity, or sealed receipts. Any history prevents regeneration.
    private const HISTORY_TABLES = [
        'sand_ai_provider', 'sand_ai_model_deployment', 'sand_ai_config_revision',
        'sand_ai_task_run', 'sand_ai_invocation_outcome_receipt',
        'sand_ai_tool_connection_revision', 'sand_ai_workflow_run',
    ];
    private const TOOL_NAMES = [
        'SAND_AI_TOOL_CONNECTION_CURRENT_KEY_ID',
        'SAND_AI_TOOL_CONNECTION_CURRENT_KEY',
        'SAND_AI_TOOL_CONNECTION_IDEMPOTENCY_HMAC_KEY',
    ];
    private const TOOL_HISTORY_TABLES = [
        'sand_ai_tool_connection', 'sand_ai_tool_connection_revision',
        'sand_ai_tool_run', 'sand_ai_tool_outcome_receipt',
        'sand_ai_tool_mcp_snapshot', 'sand_ai_tool_protocol_request',
    ];

    /** @return array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} */
    public static function status(): array
    {
        return self::inspect(self::environment());
    }

    /** Existing four-category status remains unchanged; tool readiness is reported separately. */
    public static function toolStatus(): array
    {
        return self::inspectTools(self::toolSettings());
    }

    /** Initialize the missing tool connection AEAD and HMAC bundle only on a history-free tool domain. */
    public function initializeTools(): array
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) throw self::problem('PLATFORM_UNAVAILABLE');
        $state = self::toolStatus();
        if ($state['invalid_groups'] !== []) throw self::problem('RESTORE_REQUIRED');
        if ($state['ready']) return ['ready' => true, 'changed' => false, 'recovery_action' => 'none'];
        $file = run_path('.env');
        $this->assertDirectory(dirname($file));
        $lock = $this->openPrivate(dirname($file) . '/.sand-ai-security.lock', false, true);
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) throw self::problem('BUSY');
            $before = $this->readPrivate($file);
            $parsed = $this->parse($before['bytes']);
            foreach ([...self::TOOL_NAMES, 'SAND_AI_TOOL_CONNECTION_PREVIOUS_KEYS_JSON'] as $name) {
                if (preg_match_all('/^(?:export[ \t]+)?' . $name . '[ \t]*=/m', $before['bytes']) > 1) {
                    throw self::problem('RESTORE_REQUIRED');
                }
            }
            $settings = self::toolSettings();
            $checked = self::inspectTools($settings);
            if ($checked['invalid_groups'] !== []) throw self::problem('RESTORE_REQUIRED');
            // A changed process environment must not turn this first-use operation into rotation.
            if ($checked['ready']) return ['ready' => true, 'changed' => false, 'recovery_action' => 'none'];
            foreach (self::TOOL_NAMES as $name) {
                if (array_key_exists($name, $parsed)) throw self::problem('RELOAD_OR_RESTORE_REQUIRED');
            }
            if (isset($parsed['SAND_AI_TOOL_CONNECTION_PREVIOUS_KEYS_JSON'])
                && $parsed['SAND_AI_TOOL_CONNECTION_PREVIOUS_KEYS_JSON'] !== '') {
                throw self::problem('RESTORE_REQUIRED');
            }
            return Db::connection('pgsql')->transaction(function (ConnectionInterface $connection) use ($file, $before, $settings): array {
                // This must be the first SQL in this transaction. If the CLI
                // unexpectedly inherits an active snapshot, PostgreSQL rejects
                // the isolation change and initialization fails without writing.
                $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $connection->statement('SET LOCAL lock_timeout = \'3s\'');
                $tables = [...self::TOOL_HISTORY_TABLES, 'sand_ai_tool_operation'];
                // Acquire every writer-blocking lock before the first history
                // read so a late-table commit cannot escape an early snapshot.
                foreach ($tables as $table) {
                    $connection->statement('LOCK TABLE "' . $table . '" IN SHARE MODE');
                }
                foreach ($tables as $table) {
                    $history = $connection->table($table)->useWritePdo();
                    if ($table === 'sand_ai_tool_operation') $history->where('operation', 'create_connection');
                    if ($history->exists()) throw self::problem('HISTORY_RESTORE_REQUIRED');
                }
                $values = [
                    self::TOOL_NAMES[0] => 'initial_tools_' . bin2hex(random_bytes(8)),
                    self::TOOL_NAMES[1] => base64_encode(random_bytes(32)),
                    self::TOOL_NAMES[2] => base64_encode(random_bytes(32)),
                ];
                $generated = [...$settings, 'current_key_id' => $values[self::TOOL_NAMES[0]],
                    'current_key' => $values[self::TOOL_NAMES[1]], 'idempotency_hmac_key' => $values[self::TOOL_NAMES[2]]];
                if (!self::inspectTools($generated)['ready']) throw self::problem('RESTORE_REQUIRED');
                $this->assertUnchanged($file, $before);
                // Separate first-use checkpoint; never overwrite the original four-category backup.
                $this->saveBackup($file . '.sand-ai-tool-security-original', $before['bytes']);
                $newline = str_contains($before['bytes'], "\r\n") ? "\r\n" : "\n";
                $bytes = $before['bytes'];
                if ($bytes !== '' && !str_ends_with($bytes, "\n") && !str_ends_with($bytes, "\r")) $bytes .= $newline;
                foreach ($values as $name => $value) $bytes .= $name . '=' . $value . $newline;
                $this->replace($file, $bytes, $before);
                return ['ready' => true, 'changed' => true, 'recovery_action' => 'reload_host'];
            });
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw self::problem('INITIALIZATION_FAILED');
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,string> */
    private static function toolSettings(): array
    {
        return [
            'current_key_id' => (string) env(self::TOOL_NAMES[0], ''),
            'current_key' => (string) env(self::TOOL_NAMES[1], ''),
            'idempotency_hmac_key' => (string) env(self::TOOL_NAMES[2], ''),
            'previous_keys_json' => (string) env('SAND_AI_TOOL_CONNECTION_PREVIOUS_KEYS_JSON', ''),
            'legacy_config_key' => (string) env('SAND_AI_CONFIG_KEY', ''),
        ];
    }

    /** @param array<string,string> $settings @return array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} */
    private static function inspectTools(array $settings): array
    {
        $empty = ($settings['current_key_id'] ?? '') === '' && ($settings['current_key'] ?? '') === ''
            && ($settings['idempotency_hmac_key'] ?? '') === '' && ($settings['previous_keys_json'] ?? '') === '';
        if ($empty) {
            return ['ready' => false, 'missing_groups' => ['tool_connection', 'tool_connection_idempotency'],
                'invalid_groups' => [], 'recovery_action' => 'initialize_tools_or_restore'];
        }
        // Partial material must be restored as one bundle, never completed with unrelated new keys.
        try {
            new ToolConnectionSecretCipher($settings);
        } catch (\Throwable) {
            return ['ready' => false, 'missing_groups' => [],
                'invalid_groups' => ['tool_connection', 'tool_connection_idempotency'],
                'recovery_action' => 'restore_original_material'];
        }
        return ['ready' => true, 'missing_groups' => [], 'invalid_groups' => [], 'recovery_action' => 'none'];
    }

    /** @return array{ready:bool,changed:bool,recovery_action:string} */
    public function initialize(): array
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) {
            throw self::problem('PLATFORM_UNAVAILABLE');
        }
        $state = self::status();
        if ($state['invalid_groups'] !== []) {
            throw self::problem('RESTORE_REQUIRED');
        }
        if ($state['ready']) {
            return ['ready' => true, 'changed' => false, 'recovery_action' => 'none'];
        }
        $file = run_path('.env');
        $this->assertDirectory(dirname($file));
        $lockPath = dirname($file) . '/.sand-ai-security.lock';
        $lock = $this->openPrivate($lockPath, false, true);
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                throw self::problem('BUSY');
            }
            $before = $this->readPrivate($file);
            $parsed = $this->parse($before['bytes']);
            $environment = self::environment();
            $missing = self::inspect($environment)['missing_groups'];
            if (self::inspect($environment)['invalid_groups'] !== []) {
                throw self::problem('RESTORE_REQUIRED');
            }
            // A declared value (including empty) is operator-owned. A new CLI
            // process must load valid declarations; never append a duplicate.
            foreach ($missing as $group) {
                foreach (self::GROUPS[$group] as $name) {
                    if (array_key_exists($name, $parsed)) {
                        throw self::problem('RELOAD_OR_RESTORE_REQUIRED');
                    }
                }
            }
            return Db::connection('pgsql')->transaction(function (ConnectionInterface $connection) use ($file, $before, $environment, $missing): array {
                // Block writes to the exact existing business tables until the
                // first-use file commit. No database rows or ledger are changed.
                $connection->statement('SET LOCAL lock_timeout = \'3s\'');
                foreach (self::HISTORY_TABLES as $table) {
                    $connection->statement('LOCK TABLE "' . $table . '" IN SHARE MODE');
                    if ($connection->table($table)->useWritePdo()->exists()) {
                        throw self::problem('HISTORY_RESTORE_REQUIRED');
                    }
                }
                $values = [];
                foreach ($missing as $group) {
                    $names = self::GROUPS[$group];
                    if (count($names) === 1) {
                        $values[$names[0]] = bin2hex(random_bytes(32));
                    } else {
                        $values[$names[0]] = 'initial_' . bin2hex(random_bytes(8));
                        $values[$names[1]] = base64_encode(random_bytes(32));
                    }
                }
                if (!self::inspect(array_replace($environment, $values))['ready']) {
                    throw self::problem('RESTORE_REQUIRED');
                }
                $this->assertUnchanged($file, $before);
                $this->saveBackup($file . '.sand-ai-security-original', $before['bytes']);
                $newline = str_contains($before['bytes'], "\r\n") ? "\r\n" : "\n";
                $bytes = $before['bytes'];
                if ($bytes !== '' && !str_ends_with($bytes, "\n") && !str_ends_with($bytes, "\r")) {
                    $bytes .= $newline;
                }
                foreach ($values as $name => $value) {
                    $bytes .= $name . '=' . $value . $newline;
                }
                $this->replace($file, $bytes, $before);
                return ['ready' => true, 'changed' => true, 'recovery_action' => 'reload_host'];
            });
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            // No exception message, driver detail or secret enters CLI/API output.
            throw self::problem('INITIALIZATION_FAILED');
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Explicit operator action: preserve bytes, tighten only an owned installer file. */
    public function protectConfiguration(): bool
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')) {
            throw self::problem('PLATFORM_UNAVAILABLE');
        }
        $file = run_path('.env');
        $this->assertDirectory(dirname($file));
        $lock = $this->openPrivate(dirname($file) . '/.sand-ai-security.lock', false, true);
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                throw self::problem('BUSY');
            }
            $before = $this->readPrivate($file, true);
            $info = @lstat($file);
            if (!is_array($info)) {
                throw self::problem('PRIVATE_STORAGE_REQUIRED');
            }
            if (($info['mode'] & 0777) === 0600) {
                $this->assertUnchanged($file, $before);
                return false;
            }
            $this->saveBackup($file . '.sand-ai-security-original', $before['bytes']);
            $this->replace($file, $before['bytes'], $before, true);
            return true;
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (\Throwable) {
            throw self::problem('INITIALIZATION_FAILED');
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,string> */
    private static function environment(): array
    {
        $values = [];
        foreach (self::GROUPS as $names) {
            foreach ($names as $name) {
                $values[$name] = (string) env($name, '');
            }
        }
        foreach (['SAND_AI_IDEMPOTENCY_KEY_ID', 'SAND_AI_IDEMPOTENCY_KEYRING',
            'SAND_AI_IAM_CONTEXT_PREVIOUS_KEYS_JSON', 'SAND_AI_OUTCOME_RECEIPT_PREVIOUS_KEYS_JSON'] as $name) {
            $values[$name] = (string) env($name, $name === 'SAND_AI_IDEMPOTENCY_KEY_ID' ? 'active' : '');
        }
        return $values;
    }

    /** @param array<string,string> $values @return array{ready:bool,missing_groups:list<string>,invalid_groups:list<string>,recovery_action:string} */
    private static function inspect(array $values): array
    {
        $missing = [];
        $invalid = [];
        foreach (self::GROUPS as $group => $names) {
            $first = $values[$names[0]] ?? '';
            $second = count($names) === 2 ? ($values[$names[1]] ?? '') : null;
            if ($first === '' && ($second === null || $second === '')) {
                $missing[] = $group;
            } elseif ($second === null ? strlen($first) < 32 :
                preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $first) !== 1
                || !is_string($decoded = base64_decode($second, true)) || strlen($decoded) !== 32) {
                $invalid[] = $group;
            }
        }
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $values['SAND_AI_IDEMPOTENCY_KEY_ID'] ?? 'active') !== 1) {
            $invalid[] = 'idempotency';
        }
        foreach (['idempotency' => 'SAND_AI_IDEMPOTENCY_KEYRING',
            'queued_identity' => 'SAND_AI_IAM_CONTEXT_PREVIOUS_KEYS_JSON',
            'outcome_receipt' => 'SAND_AI_OUTCOME_RECEIPT_PREVIOUS_KEYS_JSON'] as $group => $name) {
            $json = $values[$name] ?? '';
            if ($json === '') {
                continue;
            }
            try {
                $ring = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $ring = null;
            }
            $valid = is_array($ring) && ($group === 'idempotency' || !array_is_list($ring));
            if (is_array($ring)) {
                foreach ($ring as $id => $key) {
                    if (!is_string($id) || !is_string($key)) {
                        $valid = false;
                        break;
                    }
                    if ($group === 'idempotency') {
                        $valid = $valid && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $id) === 1 && strlen($key) >= 32;
                    } else {
                        $decoded = base64_decode($key, true);
                        $currentId = $values[self::GROUPS[$group][0]] ?? '';
                        $valid = $valid && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $id) === 1
                            && $id !== $currentId && is_string($decoded) && strlen($decoded) === 32;
                    }
                }
            }
            if (!$valid || (is_array($ring) && $ring !== [] && in_array($group, $missing, true))) {
                $invalid[] = $group;
            }
        }
        $invalid = array_values(array_unique($invalid));
        $ready = $missing === [] && $invalid === [];
        return ['ready' => $ready, 'missing_groups' => $missing, 'invalid_groups' => $invalid,
            'recovery_action' => $invalid !== [] ? 'restore_original_material' : ($ready ? 'none' : 'initialize_or_restore')];
    }

    private function assertDirectory(string $directory): void
    {
        $cursor = $directory;
        while ($cursor !== dirname($cursor)) {
            if (is_link($cursor) || !is_dir($cursor)) {
                throw self::problem('PRIVATE_STORAGE_REQUIRED');
            }
            $cursor = dirname($cursor);
        }
        $info = @lstat($directory);
        if (!is_array($info) || $info['uid'] !== posix_geteuid() || ($info['mode'] & 0022) !== 0) {
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
    }

    /** @return resource */
    private function openPrivate(string $path, bool $exclusive, bool $allowCreate = false, bool $installerPermissions = false): mixed
    {
        if (is_link($path)) {
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
        if (!$exclusive && !file_exists($path) && !$allowCreate) {
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
        $mode = $exclusive || !file_exists($path) ? 'x+b' : 'r+b';
        $oldMask = umask(0077);
        try {
            $handle = @fopen($path, $mode);
        } finally {
            umask($oldMask);
        }
        if (!is_resource($handle)) {
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
        $info = @fstat($handle);
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (!is_array($info) || !is_array($named) || is_link($path)
            || ($info['mode'] & 0170000) !== 0100000
            || !in_array($info['mode'] & 07777, $installerPermissions ? [0600, 0644] : [0600], true)
            || $info['nlink'] !== 1
            || $info['uid'] !== posix_geteuid() || $info['dev'] !== $named['dev'] || $info['ino'] !== $named['ino']) {
            fclose($handle);
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
        return $handle;
    }

    /** @return array{bytes:string,dev:int,ino:int,sha256:string} */
    private function readPrivate(string $file, bool $installerPermissions = false): array
    {
        // The environment file must already exist; initialization never creates a host.
        if (!is_file($file) || is_link($file) || @filesize($file) > 1048576) {
            throw self::problem('PRIVATE_STORAGE_REQUIRED');
        }
        $handle = $this->openPrivate($file, false, false, $installerPermissions);
        try {
            $info = @fstat($handle);
            $bytes = @stream_get_contents($handle, 1048577);
            if (!is_array($info) || !is_string($bytes) || strlen($bytes) > 1048576) {
                throw self::problem('PRIVATE_STORAGE_REQUIRED');
            }
            return ['bytes' => $bytes, 'dev' => $info['dev'], 'ino' => $info['ino'], 'sha256' => hash('sha256', $bytes)];
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,string|null> */
    private function parse(string $bytes): array
    {
        try {
            $parsed = Dotenv::parse($bytes);
        } catch (\Throwable) {
            throw self::problem('RESTORE_REQUIRED');
        }
        foreach (self::GROUPS as $names) {
            foreach ($names as $name) {
                if (preg_match_all('/^(?:export[ \t]+)?' . $name . '[ \t]*=/m', $bytes) > 1) {
                    throw self::problem('RESTORE_REQUIRED');
                }
            }
        }
        return $parsed;
    }

    /** @param array{bytes:string,dev:int,ino:int,sha256:string} $before */
    private function assertUnchanged(string $file, array $before, bool $installerPermissions = false): void
    {
        $now = $this->readPrivate($file, $installerPermissions);
        if ($now['dev'] !== $before['dev'] || $now['ino'] !== $before['ino'] || !hash_equals($before['sha256'], $now['sha256'])) {
            throw self::problem('CONFIGURATION_CHANGED');
        }
    }

    private function saveBackup(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) {
            $backup = $this->readPrivate($path);
            if (!hash_equals(hash('sha256', $bytes), $backup['sha256'])) {
                throw self::problem('BACKUP_CONFLICT');
            }
            return;
        }
        $handle = $this->openPrivate($path, true);
        $info = @fstat($handle);
        try {
            $this->write($handle, $bytes);
        } catch (\Throwable $failure) {
            clearstatcache(true, $path);
            $named = @lstat($path);
            if (is_array($info) && is_array($named) && !is_link($path)
                && $info['dev'] === $named['dev'] && $info['ino'] === $named['ino']) {
                @unlink($path);
            }
            throw $failure;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function write(mixed $handle, string $bytes): void
    {
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw self::problem('INITIALIZATION_FAILED');
            }
            $offset += $written;
        }
        if (!@fflush($handle) || !@fsync($handle)) {
            throw self::problem('INITIALIZATION_FAILED');
        }
    }

    /** @param array{bytes:string,dev:int,ino:int,sha256:string} $before */
    private function replace(string $file, string $bytes, array $before, bool $installerPermissions = false): void
    {
        $temporary = dirname($file) . '/.sand-ai-security-' . bin2hex(random_bytes(12));
        $handle = $this->openPrivate($temporary, true);
        try {
            $this->write($handle, $bytes);
            $this->assertUnchanged($file, $before, $installerPermissions);
            if (!@rename($temporary, $file)) {
                throw self::problem('INITIALIZATION_FAILED');
            }
            $saved = $this->readPrivate($file);
            if (!hash_equals(hash('sha256', $bytes), $saved['sha256'])) {
                throw self::problem('CONFIGURATION_CHANGED');
            }
        } finally {
            fclose($handle);
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function problem(string $kind): ApiProblem
    {
        return new ApiProblem('SAND_AI_SECURITY_' . $kind, match ($kind) {
            'PLATFORM_UNAVAILABLE' => '请在支持私有文件权限校验的宿主CLI执行初始化；当前平台未验证',
            'PRIVATE_STORAGE_REQUIRED' => '请由宿主操作员核对所有者、链接及私有权限；正常安装器0644配置先执行 protect-config，再 initialize；不要修改其它实例',
            'BUSY' => '另一个安全初始化正在执行，请待其结束后先检查状态',
            'HISTORY_RESTORE_REQUIRED' => '已有业务数据依赖原内部材料，禁止生成替代材料；请从该实例原备份恢复后检查状态',
            'RESTORE_REQUIRED' => '内部材料不完整或格式无效，请恢复原配置；不得更换已有密钥或仅补另一半',
            'RELOAD_OR_RESTORE_REQUIRED' => '配置中已有声明，禁止重复追加；请核对原配置并用新CLI进程检查，必要时恢复原备份',
            'CONFIGURATION_CHANGED' => '配置在初始化期间发生变化，已拒绝替换；请确认变更后重新检查状态',
            'BACKUP_CONFLICT' => '安全初始化原配置备份已有不同内容，请保留备份并由宿主操作员核对；不得覆盖',
            default => '初始化未确认完成，请先检查状态与原配置备份；不要重复生成或输出内部材料',
        });
    }
}
