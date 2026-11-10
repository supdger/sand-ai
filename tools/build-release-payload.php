<?php

declare(strict_types=1);

/**
 * Regenerate (or verify) release-payload.json from the actual source tree.
 *
 * release-payload.json is the build-side manifest of the official SandPackage
 * installation ZIP members and their SHA-256 digests. It must list exactly what
 * the builder packs: nothing from docs/, tests/ or tools/, which stay in the
 * source repository only.
 *
 * Usage:
 *   php tools/build-release-payload.php --iam-bundle=/path/sand-iam-0.8.4.zip
 *   php tools/build-release-payload.php --iam-bundle=<path> --check
 *   php tools/build-release-payload.php [--check]
 *
 * --iam-bundle verifies the dependency artifact against the declared digest
 * while enumerating. Omitting it records the declared identity itself, for the
 * window where the next pinned artifact has not been published yet; the build
 * gate still requires the real artifact before any ZIP is produced.
 *
 * This tool never opens a database connection and never executes lifecycle SQL.
 */

require_once __DIR__ . '/release-members.php';

/** @return array{iam:?string,check:bool} */
function sandAiPayloadArguments(): array
{
    $options = getopt('', ['iam-bundle:', 'check']);
    $iam = $options['iam-bundle'] ?? null;
    if ($iam !== null && !is_string($iam)) {
        $iam = null;
    }
    return ['iam' => $iam === '' ? null : $iam, 'check' => array_key_exists('check', $options)];
}

/**
 * Emit the manifest with the two-space layout already committed in this
 * repository, so regeneration produces a reviewable diff.
 *
 * @param array<string,string> $digests archive path => SHA-256
 */
function sandAiEncodePayload(string $app, string $version, array $digests): string
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $lines = [
        '{',
        '  "schema": ' . json_encode('sand-release-payload/v1', $flags) . ',',
        '  "app": ' . json_encode($app, $flags) . ',',
        '  "version": ' . json_encode($version, $flags) . ',',
        '  "files": {',
    ];
    $entries = [];
    foreach ($digests as $archive => $digest) {
        $entries[] = '    ' . json_encode($archive, $flags) . ': ' . json_encode($digest, $flags);
    }
    $lines[] = implode(",\n", $entries);
    $lines[] = '  }';
    $lines[] = '}';
    return implode("\n", $lines);
}

$started = microtime(true);
try {
    $arguments = sandAiPayloadArguments();
    $root = dirname(__DIR__);

    $info = parse_ini_file($root . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($info) || !is_string($info['app'] ?? null) || !is_string($info['version'] ?? null)) {
        throw new RuntimeException('info.ini 缺少 app 或 version');
    }
    $app = $info['app'];
    $version = $info['version'];
    if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
        throw new RuntimeException('根 info.ini 版本必须是三段版本号');
    }
    $pluginInfo = parse_ini_file($root . '/plugin/' . $app . '/info.ini', false, INI_SCANNER_RAW);
    if (!is_array($pluginInfo) || ($pluginInfo['version'] ?? null) !== $version) {
        throw new RuntimeException('两份 info.ini 版本不一致');
    }

    echo "步骤 1/3：枚举官方 builder 成员集合\n";
    if (is_string($arguments['iam'])) {
        $members = sandAiReleaseMembers($root, $arguments['iam']);
        $declaredOnly = null;
    } else {
        echo "未提供依赖工件：按 config.json 声明记录依赖成员摘要\n";
        $manifest = sandAiReleaseManifest($root);
        $members = $manifest['files'];
        $members[$manifest['dependency']['archive']] = '';
        $declaredOnly = $manifest['dependency'];
        ksort($members, SORT_STRING);
    }

    echo '步骤 2/3：计算 ' . count($members) . " 个成员摘要\n";
    $digests = [];
    foreach ($members as $archive => $source) {
        if ($declaredOnly !== null && $archive === $declaredOnly['archive']) {
            // The pinned artifact is external; record its declared digest.
            $digests[$archive] = $declaredOnly['sha256'];
            continue;
        }
        $digest = hash_file('sha256', $source);
        if (!is_string($digest)) {
            throw new RuntimeException('无法计算摘要：' . $archive);
        }
        $digests[$archive] = $digest;
    }

    $encoded = sandAiEncodePayload($app, $version, $digests);
    $target = $root . '/release-payload.json';

    if ($arguments['check']) {
        echo "步骤 3/3：比对已提交的 release-payload.json\n";
        $current = file_get_contents($target);
        if (!is_string($current) || !hash_equals(hash('sha256', $encoded), hash('sha256', $current))) {
            throw new RuntimeException('release-payload.json 与实际源码成员集合不一致，请重新生成');
        }
        echo '一致：' . count($digests) . " 个成员\n";
    } else {
        echo "步骤 3/3：写出 release-payload.json\n";
        if (file_put_contents($target, $encoded) === false) {
            throw new RuntimeException('无法写出 release-payload.json');
        }
        echo '成功：' . $target . '（' . count($digests) . " 个成员）\n";
    }
    echo '耗时：' . round(microtime(true) - $started, 2) . " 秒；未执行安装或 SQL。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '失败：' . $exception->getMessage()
        . "\n耗时：" . round(microtime(true) - $started, 2) . " 秒\n");
    exit(1);
}
