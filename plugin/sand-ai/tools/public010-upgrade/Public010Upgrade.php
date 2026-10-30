<?php

declare(strict_types=1);

final class SandAiPublic010Upgrade
{
    private const OLD_PACKAGE_SHA256 = '509fbb9ab18b112400d6979443fd00821e4bea293f033a0b130bc8ad8ac2909e';
    private const BACKUP_FORMAT = 'sand-ai-public010-backup/v1';

    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        $started = microtime(true);
        try {
            $options = self::options($arguments);
            $source = self::source();
            $backup = null;
            if ($options['backup_manifest'] !== null) {
                fwrite(STDERR, "读取并核对私有备份清单及三个备份文件。\n");
                $backup = self::backup($options['backup_manifest'], $source);
            }
            if ($options['stage'] === 'plan') {
                fwrite(STDOUT, self::json(self::plan($source, $backup)) . "\n");
                fwrite(STDERR, "离线计划已生成；未连接数据库。耗时 " . self::elapsed($started) . " 秒。\n");
                return 0;
            }
            if ($backup === null || $options['output'] === null) {
                throw new InvalidArgumentException('prepare 和 bridge 需要 --backup-manifest=<private JSON> 与 --output=<new private file>。');
            }
            $sql = $source['update_sql'];
            if ($options['stage'] === 'prepare') {
                fwrite(STDERR, "生成准备 SQL；仅写入新的私有文件。\n");
                $sql = self::prepare($source, $backup);
            } else {
                fwrite(STDERR, "生成与本包 update.sql 完全一致的桥接文件；仅写入新的私有文件。\n");
            }
            self::writeNewFile($options['output'], $sql);
            $next = $options['stage'] === 'prepare'
                ? '准备 SQL 已生成。数据库执行须另行授权，执行前保持旧消费者停止；该文件只记录升级意图。'
                : '桥接文件已生成。通过正式 Package 上传、安装与 SSE 升级当前候选包；不要手工执行此文件绕过 Package。';
            fwrite(STDERR, $next . " 耗时 " . self::elapsed($started) . " 秒。\n");
            return 0;
        } catch (InvalidArgumentException $error) {
            fwrite(STDERR, "未生成 SQL：" . $error->getMessage() . " 耗时 " . self::elapsed($started) . " 秒。\n");
            return 2;
        } catch (RuntimeException $error) {
            fwrite(STDERR, "操作失败：" . $error->getMessage() . " 保留备份与准备意图，修正后用同一候选重试。耗时 " . self::elapsed($started) . " 秒。\n");
            return 1;
        } catch (Throwable) {
            fwrite(STDERR, "操作失败：无法完成离线生成，未执行数据库 SQL。请核对私有文件权限与候选源码。耗时 " . self::elapsed($started) . " 秒。\n");
            return 1;
        }
    }

    /** @param list<string> $arguments
     *  @return array{stage:string,backup_manifest:?string,output:?string}
     */
    private static function options(array $arguments): array
    {
        $options = ['stage' => 'plan', 'backup_manifest' => null, 'output' => null];
        $seen = [];
        foreach ($arguments as $argument) {
            if ($argument === '--public010') {
                $key = 'public010';
                $value = true;
            } elseif (str_starts_with($argument, '--stage=')) {
                $key = 'stage';
                $value = substr($argument, 8);
            } elseif (str_starts_with($argument, '--backup-manifest=')) {
                $key = 'backup_manifest';
                $value = substr($argument, 18);
            } elseif (str_starts_with($argument, '--output=')) {
                $key = 'output';
                $value = substr($argument, 9);
            } else {
                throw new InvalidArgumentException('仅支持 --public010、--stage=plan|prepare|bridge、--backup-manifest 与 --output；不接受密码或连接参数。');
            }
            if (isset($seen[$key]) || $value === '') {
                throw new InvalidArgumentException('选项重复或缺少值；请每个选项只提供一次。');
            }
            $seen[$key] = true;
            if ($key !== 'public010') {
                $options[$key] = $value;
            }
        }
        if (!isset($seen['public010']) || !in_array($options['stage'], ['plan', 'prepare', 'bridge'], true)) {
            throw new InvalidArgumentException('使用 --public010 --stage=plan|prepare|bridge 选择操作。');
        }
        if ($options['stage'] === 'plan' && $options['output'] !== null) {
            throw new InvalidArgumentException('plan 只在标准输出返回安全 JSON；不要提供 --output。');
        }
        if ($options['stage'] !== 'plan' && ($options['backup_manifest'] === null || $options['output'] === null)) {
            throw new InvalidArgumentException('prepare 和 bridge 需要私有备份清单及一个尚不存在的输出文件。');
        }
        return $options;
    }

    /** @return array{version:string,update_sql:string,update_sha256:string,profile_sha256:string,tables:list<string>} */
    private static function source(): array
    {
        $root = dirname(__DIR__, 4);
        $info = self::sourceFile($root . '/info.ini');
        if (preg_match('/^version[ \t]*=[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*\r?$/m', $info, $version) !== 1) {
            throw new RuntimeException('候选 info.ini 缺少明确的目标版本。');
        }
        $sql = self::sourceFile($root . '/update.sql');
        $versionPattern = "/bridge_target_version[ \t]+text[ \t]*:=[ \t]*'([0-9]+\.[0-9]+\.[0-9]+)';/";
        if (preg_match_all($versionPattern, $sql, $bridgeVersions) !== 1 || $bridgeVersions[1][0] !== $version[1]) {
            throw new RuntimeException('候选 info.ini 与 update.sql 桥接目标版本不一致；请使用完整的同一候选。');
        }
        $pattern = "/bridge_profile[ \t]+text[ \t]*:=[ \t]*'([a-f0-9]{64})';/";
        if (preg_match_all($pattern, $sql, $profiles, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('候选 update.sql 缺少唯一的公开 0.1.0 桥接指纹声明。');
        }
        $profile = $profiles[1][0][0];
        $offset = $profiles[1][0][1];
        $profileSource = substr_replace($sql, str_repeat('0', 64), $offset, 64);
        if (!hash_equals($profile, hash('sha256', $profileSource))) {
            throw new RuntimeException('候选 update.sql 桥接指纹与源码不符；请使用完整的同一候选。');
        }
        $reference = self::sourceFile(__DIR__ . '/legacy-schema.sql');
        preg_match_all('/^CREATE TABLE IF NOT EXISTS (sand_ai_[a-z_]+) \(/m', $reference, $matches);
        $tables = $matches[1];
        if (count($tables) !== 14 || count(array_unique($tables)) !== 14) {
            throw new RuntimeException('本包旧结构参考不完整；公开 0.1.0 必须恰有 14 个表。');
        }
        return [
            'version' => $version[1],
            'update_sql' => $sql,
            'update_sha256' => hash('sha256', $sql),
            'profile_sha256' => $profile,
            'tables' => $tables,
        ];
    }

    private static function sourceFile(string $path): string
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('候选源码文件缺失或为符号链接。');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('无法读取完整候选源码文件。');
        }
        return $contents;
    }

    /** @param array{version:string,update_sha256:string,tables:list<string>} $source
     *  @return array{database:string,manifest_sha256:string,table_snapshot:list<stdClass>}
     */
    private static function backup(string $path, array $source): array
    {
        $file = self::openPrivateFile($path);
        try {
            if ($file['stat']['size'] > 1048576) {
                throw new RuntimeException('备份清单超过 1 MiB；请提供只含升级合同字段的清单。');
            }
            $contents = stream_get_contents($file['handle']);
            if (!is_string($contents)) {
                throw new RuntimeException('无法读取备份清单。');
            }
            self::unchangedFile($file);
        } finally {
            fclose($file['handle']);
        }
        try {
            $manifest = json_decode($contents, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('备份清单不是有效 JSON；请依据随包模板填写。');
        }
        self::keys($manifest, [
            'format', 'database', 'schema', 'old_package_sha256', 'target_version',
            'target_update_sha256', 'consumers_stopped', 'restore_rehearsed', 'artifacts', 'table_snapshot',
        ], '备份清单');
        if ($manifest->format !== self::BACKUP_FORMAT || $manifest->schema !== 'public'
            || $manifest->old_package_sha256 !== self::OLD_PACKAGE_SHA256
            || $manifest->target_version !== $source['version']
            || $manifest->target_update_sha256 !== $source['update_sha256']) {
            throw new RuntimeException('备份格式、旧包、schema 或目标候选不匹配；请使用公开 0.1.0 的备份和同一候选。');
        }
        if (!is_string($manifest->database) || trim($manifest->database) === ''
            || strlen($manifest->database) > 63 || str_contains($manifest->database, "\0")) {
            throw new RuntimeException('database 必须填写实际已有的 PostgreSQL 数据库名；工具不会创建数据库。');
        }
        if ($manifest->consumers_stopped !== true || $manifest->restore_rehearsed !== true) {
            throw new RuntimeException('须确认旧消费者已停止且完整备份恢复已演练，再生成准备或桥接文件。');
        }
        if (!is_array($manifest->artifacts) || count($manifest->artifacts) !== 3) {
            throw new RuntimeException('artifacts 必须恰含 database、files、private_storage 三个备份文件。');
        }
        $kinds = [];
        foreach ($manifest->artifacts as $artifact) {
            self::keys($artifact, ['kind', 'path', 'bytes', 'sha256'], '备份文件条目');
            if (!is_string($artifact->kind) || !in_array($artifact->kind, ['database', 'files', 'private_storage'], true)
                || isset($kinds[$artifact->kind]) || !is_string($artifact->path)
                || !(self::unsignedDecimal($artifact->bytes) || (is_int($artifact->bytes) && $artifact->bytes >= 0))
                || !self::sha256($artifact->sha256)) {
                throw new RuntimeException('备份文件条目重复或字段无效；bytes 使用非负整数或十进制字符串，sha256 使用小写摘要。');
            }
            $kinds[$artifact->kind] = true;
            self::artifact($artifact);
        }
        if (!is_array($manifest->table_snapshot) || count($manifest->table_snapshot) !== 14) {
            throw new RuntimeException('table_snapshot 必须是随包 snapshot.sql 返回的完整 14 表快照。');
        }
        $seenTables = [];
        foreach ($manifest->table_snapshot as $snapshot) {
            self::keys($snapshot, ['table', 'row_count', 'rows_sha256', 'sequence_last_value', 'sequence_is_called'], '旧表快照');
            if (!is_string($snapshot->table) || !in_array($snapshot->table, $source['tables'], true)
                || isset($seenTables[$snapshot->table]) || !self::unsignedDecimal($snapshot->row_count)
                || !self::sha256($snapshot->rows_sha256) || !is_string($snapshot->sequence_last_value)
                || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $snapshot->sequence_last_value) !== 1
                || !is_bool($snapshot->sequence_is_called)) {
                throw new RuntimeException('旧表快照重复或字段无效；不要改写 snapshot.sql 产生的表名、行摘要与序列状态。');
            }
            $seenTables[$snapshot->table] = true;
        }
        return [
            'database' => $manifest->database,
            'manifest_sha256' => hash('sha256', $contents),
            'table_snapshot' => $manifest->table_snapshot,
        ];
    }

    /** @param mixed $object
     *  @param list<string> $expected
     */
    private static function keys(mixed $object, array $expected, string $label): void
    {
        if (!$object instanceof stdClass) {
            throw new RuntimeException($label . '必须是 JSON 对象。');
        }
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException($label . '字段不完整或含未支持字段；请使用随包模板，不添加密码或配置。');
        }
    }

    private static function unsignedDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1;
    }

    private static function sha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function artifact(stdClass $artifact): void
    {
        $file = self::openPrivateFile($artifact->path);
        try {
            if ((string) $file['stat']['size'] !== (string) $artifact->bytes) {
                throw new RuntimeException('备份文件实际字节数与清单不符；请重新确认完整备份。');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $file['handle']) !== $file['stat']['size']) {
                throw new RuntimeException('备份文件未完整读取；请确认存储和权限后重试。');
            }
            $actual = hash_final($hash);
            self::unchangedFile($file);
            if (!hash_equals($artifact->sha256, $actual)) {
                throw new RuntimeException('备份文件实际 SHA256 与清单不符；不要继续升级。');
            }
        } finally {
            fclose($file['handle']);
        }
    }

    /** @return array{handle:resource,stat:array,path:string} */
    private static function openPrivateFile(string $path): array
    {
        self::plainPath($path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600 || $before['nlink'] !== 1) {
            throw new RuntimeException('私有输入必须是权限 0600 的单链接普通文件，不能是符号链接。');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('无法打开私有输入；请确认文件存在且可读。');
        }
        $stat = fstat($handle);
        if (!is_array($stat) || $stat['dev'] !== $before['dev'] || $stat['ino'] !== $before['ino']) {
            fclose($handle);
            throw new RuntimeException('打开私有输入时文件发生变化；请停止修改后重试。');
        }
        return ['handle' => $handle, 'stat' => $stat, 'path' => $path];
    }

    /** @param array{handle:resource,stat:array,path:string} $file */
    private static function unchangedFile(array $file): void
    {
        clearstatcache(true, $file['path']);
        self::plainPath($file['path']);
        $pathStat = @lstat($file['path']);
        $current = fstat($file['handle']);
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime', 'nlink'] as $field) {
            if (!is_array($pathStat) || !is_array($current)
                || $pathStat[$field] !== $file['stat'][$field] || $current[$field] !== $file['stat'][$field]) {
                throw new RuntimeException('读取期间私有输入发生变化；请固定备份后重试。');
            }
        }
    }

    private static function plainPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('私有输入与输出须使用本机绝对路径。');
        }
        $parts = explode('/', substr($path, 1));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('文件路径须规范化，不含空目录、. 或 ..。');
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new RuntimeException('私有文件及父目录不能通过符号链接访问。');
            }
        }
    }

    /** @param array $source
     *  @param ?array $backup
     *  @return array<string,mixed>
     */
    private static function plan(array $source, ?array $backup): array
    {
        return [
            'format' => 'sand-ai-public010-plan/v1',
            'stage' => 'plan',
            'database_connected' => false,
            'supported_source' => ['version' => '0.1.0', 'package_sha256' => self::OLD_PACKAGE_SHA256, 'schema' => 'public', 'table_count' => 14],
            'target' => ['version' => $source['version'], 'update_sha256' => $source['update_sha256'], 'profile_sha256' => $source['profile_sha256']],
            'backup_manifest_sha256' => $backup === null ? null : $backup['manifest_sha256'],
            'backup_files_verified' => $backup !== null,
            'preconditions' => [
                '仅支持所列公开 0.1.0 原包与精确旧结构；不支持 pre-repair v093 或其他缺失 ledger 状态。',
                '使用已有数据库 public schema；工具不创建数据库。',
                '旧消费者停止，database/files/private_storage 完整备份均为 0600 普通文件，实际字节与 SHA256 相符，恢复已演练。',
                'table_snapshot 使用随包 snapshot.sql 的完整 14 表结果；数据库读取或执行另需明确授权。',
                'prepare 仅生成准备 SQL，新文件须在另行授权后执行以持久化意图；不归档、不安装、不授 IAM。',
                'bridge 仅输出候选原 update.sql；实际升级走正式 Package 上传、安装及 SSE，不手工执行绕过 Package。',
                '升级保留旧行、密文与私有对象引用为归档；不转换成现行授权、任务、用量或收款。',
                'SQL 失败整体回滚并保留准备意图，同一候选通过 Package 恢复；SQL 已提交后旧文件不能独立回滚数据库。',
                '完整恢复须另行授权；正常卸载保留旧归档。',
            ],
            'next_stage' => 'prepare',
        ];
    }

    /** @param array $source
     *  @param array $backup
     */
    private static function prepare(array $source, array $backup): string
    {
        $template = self::sourceFile(__DIR__ . '/prepare.sql');
        $guard = self::sourceFile(__DIR__ . '/guard.sql');
        $values = [
            '@@DATABASE@@' => self::sqlLiteral($backup['database']),
            '@@TARGET_VERSION@@' => self::sqlLiteral($source['version']),
            '@@TARGET_UPDATE_SHA256@@' => self::sqlLiteral($source['update_sha256']),
            '@@TARGET_PROFILE_SHA256@@' => self::sqlLiteral($source['profile_sha256']),
            '@@BACKUP_MANIFEST_SHA256@@' => self::sqlLiteral($backup['manifest_sha256']),
            '@@BACKUP_SNAPSHOT@@' => self::sqlLiteral(self::json($backup['table_snapshot'])),
            '@@GUARD_SQL@@' => $guard,
        ];
        foreach (array_keys($values) as $placeholder) {
            if (!str_contains($template, $placeholder)) {
                throw new RuntimeException('准备 SQL 模板与工具合同不一致；请使用完整的同一候选。');
            }
        }
        $sql = strtr($template, $values);
        if (preg_match('/@@[A-Z_]+@@/', $sql) === 1) {
            throw new RuntimeException('准备 SQL 含未绑定占位符；请使用完整的同一候选。');
        }
        return $sql;
    }

    private static function sqlLiteral(string $value): string
    {
        // Encode every byte so private values cannot close a surrounding DO dollar quote.
        $literal = "E'";
        for ($offset = 0, $length = strlen($value); $offset < $length; $offset++) {
            $literal .= '\\x' . bin2hex($value[$offset]);
        }
        return $literal . "'";
    }

    private static function writeNewFile(string $path, string $contents): void
    {
        self::plainPath($path);
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('无法创建新的输出文件；工具拒绝覆盖，须选择尚不存在且父目录可写的文件。');
        }
        $stat = fstat($handle);
        try {
            if (!is_array($stat) || ($stat['mode'] & 0777) !== 0600 || ($stat['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('输出无法保持 0600 普通文件权限。');
            }
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('输出写入不完整；请确认可用空间后换一个新文件重试。');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('无法完成输出写入；请确认存储后换一个新文件重试。');
            }
            clearstatcache(true, $path);
            self::plainPath($path);
            $current = @lstat($path);
            if (!is_array($current) || $current['dev'] !== $stat['dev'] || $current['ino'] !== $stat['ino']
                || ($current['mode'] & 0777) !== 0600 || $current['size'] !== $length) {
                throw new RuntimeException('输出路径或权限在写入期间发生变化；请固定私有目录后重试。');
            }
        } catch (Throwable $error) {
            fclose($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (is_array($stat) && is_array($current) && $current['dev'] === $stat['dev'] && $current['ino'] === $stat['ino']) {
                @unlink($path);
            }
            throw $error;
        }
        fclose($handle);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function elapsed(float $started): string
    {
        return number_format(microtime(true) - $started, 2, '.', '');
    }
}
