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
 * Hard gates before any ZIP is produced:
 *   - --tag=<git tag> is required; the payload comes from a clean worktree
 *     checkout of that tag, never from the working tree, so uncommitted or
 *     main-branch content cannot leak into the release;
 *   - the frontend gate (tools/frontend-gate.php) must pass on that checkout;
 *   - release-payload.json in the checkout must match the enumerated member
 *     set, which excludes plugin/<app>/docs/development|product.
 *
 * Usage:
 *   php tools/build-package.php \
 *     --output=/已建输出目录 \
 *     --iam-bundle=/锁定的 sand-iam-0.9.6.zip \
 *     --tag=v0.4.10 \
 *     [--builder=/path/to/sand-package/server/scripts/build-plugin-package.php] \
 *     [--host-min=0.2.3] [--host-max=<version>] [--notes=<摘要>] [--dry-run]
 *
 * --dry-run performs every gate (tag checkout, frontend gate, member and
 * manifest checks) but stops before staging and the official builder, so no
 * ZIP is produced.
 *
 * This tool never opens a database connection, never installs, and never
 * executes lifecycle SQL.
 */

require_once __DIR__ . '/release-members.php';
require_once __DIR__ . '/frontend-gate.php';

/**
 * @return array{output:string,iam:string,builder:string,tag:string,hostMin:string,hostMax:?string,notes:?string,dryRun:bool}
 */
function sandAiBuildArguments(string $root): array
{
    $options = getopt('', ['output:', 'iam-bundle:', 'builder:', 'tag:', 'host-min:', 'host-max:', 'notes:', 'dry-run']);
    $output = $options['output'] ?? null;
    $iam = $options['iam-bundle'] ?? null;
    $tag = $options['tag'] ?? null;
    $dryRun = array_key_exists('dry-run', $options);
    if (!is_string($tag) || $tag === '') {
        throw new RuntimeException('必须提供 --tag=<已存在的 git tag>：发行载荷只能来自明确 tag 的干净检出');
    }
    if (!is_string($iam) || $iam === '') {
        throw new RuntimeException(
            "用法：php tools/build-package.php --output=/已建输出目录 --iam-bundle=/锁定的 sand-iam-<version>.zip --tag=<git tag>"
            . " [--builder=<官方 builder 路径>] [--host-min=0.2.3] [--host-max=<version>] [--notes=<摘要>] [--dry-run]"
        );
    }
    if (!$dryRun && (!is_string($output) || !is_dir($output) || !is_writable($output) || is_link($output))) {
        throw new RuntimeException(
            "用法：php tools/build-package.php --output=/已建输出目录 --iam-bundle=/锁定的 sand-iam-<version>.zip --tag=<git tag>"
            . " [--builder=<官方 builder 路径>] [--host-min=0.2.3] [--host-max=<version>] [--notes=<摘要>] [--dry-run]"
        );
    }
    $builder = $options['builder'] ?? dirname($root) . '/sand-package/server/scripts/build-plugin-package.php';
    if (!$dryRun && (!is_string($builder) || !is_file($builder) || is_link($builder))) {
        throw new RuntimeException('官方 SandPackage builder 不存在，请用 --builder= 指定 build-plugin-package.php');
    }
    $hostMin = $options['host-min'] ?? '0.2.3';
    $hostMax = $options['host-max'] ?? null;
    $notes = $options['notes'] ?? null;
    if (!is_string($hostMin) || preg_match('/^\d+\.\d+\.\d+$/D', $hostMin) !== 1) {
        throw new RuntimeException('--host-min 必须是三段版本号');
    }
    if ($dryRun && (!is_string($output) || $output === '')) {
        $output = sys_get_temp_dir();
    }
    return [
        'output' => (string) realpath($output),
        'iam' => $iam,
        'builder' => is_string($builder) && is_file($builder) ? (string) realpath($builder) : (string) $builder,
        'tag' => $tag,
        'hostMin' => $hostMin,
        'hostMax' => is_string($hostMax) ? $hostMax : null,
        'notes' => is_string($notes) ? $notes : null,
        'dryRun' => $dryRun,
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

/**
 * Run a git command in $root with a hard timeout.
 *
 * @param list<string> $args
 * @return array{status:int,output:string}
 */
function sandAiGit(string $root, array $args, int $timeoutSeconds = 120): array
{
    $command = 'git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 git 命令');
    }
    $deadline = microtime(true) + $timeoutSeconds;
    $output = '';
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('git 命令超时（' . $timeoutSeconds . ' 秒）');
        }
        usleep(50000);
    } while (true);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    foreach ([1, 2] as $fd) {
        fclose($pipes[$fd]);
    }
    $exit = proc_close($process);

    return ['status' => is_int($exit) ? $exit : 1, 'output' => $output];
}

/**
 * Resolve --tag to an existing git tag name (accepts "v1.2.3" or "1.2.3") and
 * check it out into a clean detached worktree so the packaged bytes are the
 * tag's commit exactly.
 *
 * @return array{tag:string,checkout:string}
 */
function sandAiCheckoutTag(string $root, string $requested, string $checkoutBase): array
{
    $tag = null;
    foreach ([$requested, 'v' . $requested] as $candidate) {
        $result = sandAiGit($root, ['rev-parse', '--verify', 'refs/tags/' . $candidate . '^{commit}']);
        if ($result['status'] === 0) {
            $tag = $candidate;
            break;
        }
    }
    if ($tag === null) {
        throw new RuntimeException('git tag 不存在：' . $requested . '（发行必须先打 tag 再从 tag 检出干净树构包）');
    }
    $checkout = $checkoutBase . '/.sand-ai-tag-checkout-' . bin2hex(random_bytes(8));
    $result = sandAiGit($root, ['worktree', 'add', '--detach', $checkout, $tag]);
    if ($result['status'] !== 0) {
        throw new RuntimeException('无法按 tag 检出干净工作树：' . trim($result['output']));
    }

    return ['tag' => $tag, 'checkout' => $checkout];
}

/** Remove the temporary tag worktree; never throws during cleanup. */
function sandAiRemoveCheckout(string $root, string $checkout): void
{
    try {
        sandAiGit($root, ['worktree', 'remove', '--force', $checkout]);
    } catch (Throwable) {
        // Fall back to a plain delete when the worktree registration is gone.
        sandAiRemoveTree($checkout);
    }
}

$started = microtime(true);
$staging = null;
$checkout = null;
try {
    $root = dirname(__DIR__);
    $arguments = sandAiBuildArguments($root);

    echo "步骤 0/5：按 tag 检出干净源码树\n";
    $resolved = sandAiCheckoutTag($root, $arguments['tag'], $arguments['output']);
    $checkout = $resolved['checkout'];
    $tag = $resolved['tag'];
    echo '  tag：' . $tag . '，检出：' . $checkout . "\n";

    $info = parse_ini_file($checkout . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($info) || !is_string($info['app'] ?? null) || !is_string($info['version'] ?? null)) {
        throw new RuntimeException('tag 检出缺少 info.ini 的 app 或 version');
    }
    $app = $info['app'];
    $version = $info['version'];

    echo "步骤 1/5：前端构建闸门（vite build → vue-tsc --noEmit → 相对 import 扫描）\n";
    sandAiFrontendGate($checkout, $app);

    echo "步骤 2/5：枚举发行成员并核对 release-payload.json\n";
    $members = sandAiReleaseMembers($checkout, $arguments['iam']);
    foreach ($members as $archive => $source) {
        foreach (SAND_AI_EXCLUDED_PAYLOAD_PREFIXES as $prefix) {
            if (str_contains('/' . $archive, '/' . 'plugin/' . $app . '/' . $prefix)) {
                throw new RuntimeException('发行成员包含已排除路径：' . $archive);
            }
        }
    }
    $rawPayload = file_get_contents($checkout . '/release-payload.json');
    if (!is_string($rawPayload)) {
        throw new RuntimeException('无法读取 tag 检出的 release-payload.json');
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

    if ($arguments['dryRun']) {
        echo '干跑通过：tag ' . $tag . ' 检出门禁全部通过，' . count($members)
            . " 个成员；未组装 staging、未调用官方 builder、未产出 ZIP。\n";
        sandAiRemoveCheckout($root, $checkout);
        $checkout = null;
        echo '耗时：' . round(microtime(true) - $started, 2) . " 秒；未执行安装或 SQL。\n";
        exit(0);
    }

    echo '步骤 3/5：组装临时源码树并注入 SandIAM 工件（' . count($members) . " 个成员）\n";
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

    echo "步骤 4/5：调用官方 SandPackage builder\n";
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

    echo "步骤 5/5：清理临时组装目录与 tag 检出\n";
    sandAiRemoveTree($staging);
    $staging = null;
    sandAiRemoveCheckout($root, $checkout);
    $checkout = null;
    echo '成功：' . $arguments['output'] . '/' . $app . '-' . $version
        . ".zip\n耗时：" . round(microtime(true) - $started, 2) . " 秒；未执行安装或 SQL。\n";
} catch (Throwable $exception) {
    if (is_string($staging)) {
        sandAiRemoveTree($staging);
    }
    if (is_string($checkout)) {
        sandAiRemoveCheckout(dirname(__DIR__), $checkout);
    }
    fwrite(STDERR, '失败：' . $exception->getMessage()
        . "\n耗时：" . round(microtime(true) - $started, 2) . " 秒\n");
    exit(1);
}
