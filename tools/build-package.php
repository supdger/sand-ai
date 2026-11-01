<?php

declare(strict_types=1);

/**
 * Build the installable SandAI package with the pinned SandIAM artifact
 * injected at build time.
 *
 * The dependency artifact is NOT stored in this repository. config.json keeps
 * only its identity (version + SHA-256); the maintainer supplies the locked
 * public artifact through --iam-bundle. The official SandPackage builder then
 * verifies that identity again via PluginDependencyPolicy::verifyBundles().
 *
 * Usage:
 *   php tools/build-package.php \
 *     --output=/已建输出目录 \
 *     --iam-bundle=/锁定的 sand-iam-0.8.4.zip \
 *     [--builder=/path/to/sand-package/server/scripts/build-plugin-package.php] \
 *     [--tag=0.3.0] [--host-min=0.2.3] [--host-max=<version>] [--notes=<摘要>]
 *
 * This tool never opens a database connection, never installs, and never
 * executes lifecycle SQL.
 */

require_once __DIR__ . '/release-members.php';

/**
 * @return array{output:string,iam:string,builder:string,tag:?string,hostMin:string,hostMax:?string,notes:?string}
 */
function sandAiBuildArguments(string $root): array
{
    $options = getopt('', ['output:', 'iam-bundle:', 'builder:', 'tag:', 'host-min:', 'host-max:', 'notes:']);
    $output = $options['output'] ?? null;
    $iam = $options['iam-bundle'] ?? null;
    if (!is_string($output) || !is_dir($output) || !is_writable($output) || is_link($output)
        || !is_string($iam) || $iam === '') {
        throw new RuntimeException(
            "用法：php tools/build-package.php --output=/已建输出目录 --iam-bundle=/锁定的 sand-iam-<version>.zip"
            . " [--builder=<官方 builder 路径>] [--tag=<Release tag>] [--host-min=0.2.3] [--host-max=<version>] [--notes=<摘要>]"
        );
    }
    $builder = $options['builder'] ?? dirname($root) . '/sand-package/server/scripts/build-plugin-package.php';
    if (!is_string($builder) || !is_file($builder) || is_link($builder)) {
        throw new RuntimeException('官方 SandPackage builder 不存在，请用 --builder= 指定 build-plugin-package.php');
    }
    $tag = $options['tag'] ?? null;
    $hostMin = $options['host-min'] ?? '0.2.3';
    $hostMax = $options['host-max'] ?? null;
    $notes = $options['notes'] ?? null;
    if (!is_string($hostMin) || preg_match('/^\d+\.\d+\.\d+$/D', $hostMin) !== 1) {
        throw new RuntimeException('--host-min 必须是三段版本号');
    }
    return [
        'output' => (string) realpath($output),
        'iam' => $iam,
        'builder' => (string) realpath($builder),
        'tag' => is_string($tag) ? $tag : null,
        'hostMin' => $hostMin,
        'hostMax' => is_string($hostMax) ? $hostMax : null,
        'notes' => is_string($notes) ? $notes : null,
    ];
}

function sandAiRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

$started = microtime(true);
$staging = null;
try {
    $root = dirname(__DIR__);
    $arguments = sandAiBuildArguments($root);

    $info = parse_ini_file($root . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($info) || !is_string($info['app'] ?? null) || !is_string($info['version'] ?? null)) {
        throw new RuntimeException('info.ini 缺少 app 或 version');
    }
    $app = $info['app'];
    $version = $info['version'];
    $tag = $arguments['tag'] ?? $version;

    echo "步骤 1/4：枚举发行成员并核对 release-payload.json\n";
    $members = sandAiReleaseMembers($root, $arguments['iam']);
    $rawPayload = file_get_contents($root . '/release-payload.json');
    if (!is_string($rawPayload)) {
        throw new RuntimeException('无法读取 release-payload.json');
    }
    $payload = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['schema'] ?? null) !== 'sand-release-payload/v1'
        || ($payload['app'] ?? null) !== $app || ($payload['version'] ?? null) !== $version
        || !is_array($payload['files'] ?? null)) {
        throw new RuntimeException('release-payload.json 的 schema、应用或版本声明不符');
    }
    $declared = $payload['files'];
    if (array_keys($declared) !== array_keys($members)) {
        throw new RuntimeException('release-payload.json 成员集合与源码不一致，请先运行 tools/build-release-payload.php');
    }
    foreach ($members as $archive => $source) {
        if (!hash_equals((string) $declared[$archive], (string) hash_file('sha256', $source))) {
            throw new RuntimeException('发行清单摘要与源码不一致：' . $archive);
        }
    }

    echo '步骤 2/4：组装临时源码树并注入 SandIAM 工件（' . count($members) . " 个成员）\n";
    $staging = $arguments['output'] . '/.sand-ai-staging-' . bin2hex(random_bytes(8));
    if (!mkdir($staging, 0700)) {
        throw new RuntimeException('无法创建临时组装目录');
    }
    foreach ($members as $archive => $source) {
        $target = $staging . '/' . $archive;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('无法创建临时目录：' . $archive);
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('无法复制成员：' . $archive);
        }
    }

    echo "步骤 3/4：调用官方 SandPackage builder\n";
    $command = [PHP_BINARY, $arguments['builder'], $staging, $arguments['output'], $tag, $arguments['hostMin']];
    if ($arguments['hostMax'] !== null) {
        $command[] = $arguments['hostMax'];
    }
    if ($arguments['notes'] !== null) {
        $command[] = '--notes=' . $arguments['notes'];
    }
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $status = 0;
    passthru($escaped, $status);
    if ($status !== 0) {
        throw new RuntimeException('官方 builder 构包失败（退出码 ' . $status . '）');
    }

    echo "步骤 4/4：清理临时组装目录\n";
    sandAiRemoveTree($staging);
    $staging = null;
    echo '成功：' . $arguments['output'] . '/' . $app . '-' . $version
        . ".zip\n耗时：" . round(microtime(true) - $started, 2) . " 秒；未执行安装或 SQL。\n";
} catch (Throwable $exception) {
    if (is_string($staging)) {
        sandAiRemoveTree($staging);
    }
    fwrite(STDERR, '失败：' . $exception->getMessage()
        . "\n耗时：" . round(microtime(true) - $started, 2) . " 秒\n");
    exit(1);
}
