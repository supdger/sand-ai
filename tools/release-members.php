<?php

declare(strict_types=1);

/**
 * Mirror of the official SandPackage builder member set for the SandAI package.
 *
 * Authoritative implementation: sand-package/server/scripts/build-plugin-package.php
 * (PACKAGE_REQUIRED_ROOT_FILES, optional root files, addPayloadTree,
 * hasForbiddenPayloadComponent, HostPayloadManifest::inspectDirectory).
 *
 * This file only enumerates files. It never opens a database connection,
 * never installs, and never executes lifecycle SQL.
 */

/** @var list<string> Root files the builder requires unconditionally. */
const SAND_AI_REQUIRED_ROOT_FILES = [
    'info.ini',
    'config.json',
    'install.sql',
    'update.sql',
    'uninstall.sql',
    'README.md',
    'LICENSE',
];

/** @var list<string> Root files the builder packs only when present. */
const SAND_AI_OPTIONAL_ROOT_FILES = [
    'existing-schema.json',
    'CHANGELOG.md',
    'NOTICE',
    'release-build-contract.json',
];

/**
 * Archive prefixes deliberately kept out of the release ZIP even though the
 * official builder would pack everything under plugin/<app>: development and
 * product documents are source-only references, not runtime payload. Anything
 * else under plugin/<app>/docs/ (e.g. docs/architecture/, docs/*.md) still ships.
 */
const SAND_AI_EXCLUDED_PAYLOAD_PREFIXES = [
    'docs/development/',
    'docs/product/',
];

/** Same exclusion rule as the builder's hasForbiddenPayloadComponent(). */
function sandAiForbiddenPayloadComponent(string $relative): bool
{
    foreach (explode('/', $relative) as $part) {
        if (in_array($part, ['.git', 'node_modules', 'vendor'], true)
            || $part === '.env' || str_starts_with($part, '.env.')) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a path relative to a payload tree root falls into an excluded
 * prefix (currently only plugin/<app>/docs/development|product).
 */
function sandAiExcludedPayloadPath(string $relative): bool
{
    foreach (SAND_AI_EXCLUDED_PAYLOAD_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,string> $files archive path => absolute source path
 */
function sandAiAddPayloadTree(string $root, string $archiveRoot, array &$files): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
        $source = $item->getPathname();
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathName());
        if (sandAiExcludedPayloadPath($relative)) {
            continue;
        }
        $archive = $archiveRoot . '/' . $relative;
        if ($item->isLink() || is_link($source)) {
            throw new RuntimeException('载荷不能包含符号链接：' . $archive);
        }
        if (sandAiForbiddenPayloadComponent($relative)) {
            throw new RuntimeException('载荷包含禁止目录或文件：' . $archive);
        }
        if ($item->isDir()) {
            continue;
        }
        if (!$item->isFile()) {
            throw new RuntimeException('载荷只能包含普通文件：' . $archive);
        }
        $files[$archive] = $source;
    }
}

/**
 * Resolve the pinned dependency bundle archive name from config.json.
 *
 * @param array<string,mixed> $config
 * @return array{name:string,version:string,sha256:string,archive:string}
 */
function sandAiDependencyBundle(array $config): array
{
    $declared = $config['plugin_dependencies'] ?? null;
    if (!is_array($declared) || count($declared) !== 1 || !isset($declared['sand-iam'])) {
        throw new RuntimeException('config.json 必须声明唯一的 sand-iam 依赖工件');
    }
    $entry = $declared['sand-iam'];
    if (!is_array($entry) || !is_string($entry['version'] ?? null) || !is_string($entry['sha256'] ?? null)
        || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $entry['version']) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']) !== 1) {
        throw new RuntimeException('sand-iam 依赖声明格式错误');
    }
    return [
        'name' => 'sand-iam',
        'version' => $entry['version'],
        'sha256' => $entry['sha256'],
        'archive' => 'dependencies/sand-iam-' . $entry['version'] . '.zip',
    ];
}

/**
 * Enumerate the release manifest: every source member plus the dependency
 * bundle identity declared by config.json (the artifact itself is external).
 *
 * @param string $root Package source root (the sand-ai/ directory).
 * @return array{files: array<string,string>, dependency: array{name:string,version:string,sha256:string,archive:string}}
 */
function sandAiReleaseManifest(string $root): array
{
    $root = rtrim($root, '/');
    $rawConfig = file_get_contents($root . '/config.json');
    if (!is_string($rawConfig)) {
        throw new RuntimeException('无法读取 config.json');
    }
    $config = json_decode($rawConfig, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new RuntimeException('config.json 必须是 JSON 对象');
    }
    $info = parse_ini_file($root . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($info) || !is_string($info['app'] ?? null)) {
        throw new RuntimeException('info.ini 格式无效');
    }
    $app = $info['app'];

    $files = [];
    foreach (SAND_AI_REQUIRED_ROOT_FILES as $name) {
        $path = $root . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('插件源码缺少根部必备文件：' . $name);
        }
        $files[$name] = $path;
    }

    $bundle = sandAiDependencyBundle($config);

    // The dependency artifact is enumerated but verified separately: the
    // declared archive name and digest come from config.json while the
    // external ZIP only exists when the maintainer supplies it.
    foreach (SAND_AI_OPTIONAL_ROOT_FILES as $name) {
        $path = $root . '/' . $name;
        if (file_exists($path) || is_link($path)) {
            if (!is_file($path) || is_link($path)) {
                throw new RuntimeException('可选根文件必须是普通文件：' . $name);
            }
            $files[$name] = $path;
        }
    }

    $backend = $root . '/plugin/' . $app;
    if (!is_dir($backend) || is_link($backend)) {
        throw new RuntimeException('插件后端载荷目录缺失：plugin/' . $app);
    }
    sandAiAddPayloadTree($backend, 'plugin/' . $app, $files);

    $frontend = $root . '/sandadmin-artd/src/views/plugin/' . $app;
    if (file_exists($frontend) || is_link($frontend)) {
        if (!is_dir($frontend) || is_link($frontend)) {
            throw new RuntimeException('管理端载荷路径必须是普通目录');
        }
        sandAiAddPayloadTree($frontend, 'sandadmin-artd/src/views/plugin/' . $app, $files);
    }

    // The builder adds host-payload.json plus app/ and config/ only when the
    // declared host payload is non-empty.
    $hostDirectories = [];
    foreach (['app', 'config'] as $name) {
        if (is_dir($root . '/' . $name)) {
            $hostDirectories[] = $name;
        }
    }
    if ($hostDirectories !== []) {
        $manifest = $root . '/host-payload.json';
        if (!is_file($manifest) || is_link($manifest)) {
            throw new RuntimeException('宿主载荷存在时必须提供 host-payload.json');
        }
        $files['host-payload.json'] = $manifest;
        foreach ($hostDirectories as $name) {
            sandAiAddPayloadTree($root . '/' . $name, $name, $files);
        }
    }

    ksort($files, SORT_STRING);
    return ['files' => $files, 'dependency' => $bundle];
}

/**
 * Enumerate the exact release ZIP member set.
 *
 * @param string $root        Package source root (the sand-ai/ directory).
 * @param string $iamBundle   Externally injected dependency artifact path.
 * @return array<string,string> archive path => absolute source path, sorted by archive path
 */
function sandAiReleaseMembers(string $root, string $iamBundle): array
{
    $manifest = sandAiReleaseManifest($root);
    $bundle = $manifest['dependency'];
    if (!is_file($iamBundle) || is_link($iamBundle)
        || !hash_equals($bundle['sha256'], (string) hash_file('sha256', $iamBundle))) {
        throw new RuntimeException('SandIAM ' . $bundle['version'] . ' 依赖工件缺失或摘要不符，请提供锁定公开工件');
    }
    $files = $manifest['files'];
    $files[$bundle['archive']] = $iamBundle;
    ksort($files, SORT_STRING);
    return $files;
}
