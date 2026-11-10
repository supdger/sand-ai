<?php

declare(strict_types=1);

/**
 * Frontend release gate for the packaged admin views tree.
 *
 * The plugin frontend is a views-only source tree compiled inside the host
 * sandadmin-artd project. The gate applies the strictest available check and
 * always runs the relative-import resolution floor:
 *
 *   1. vite build          — when the frontend tree is independently buildable
 *                            (own package.json + local vite binary);
 *   2. vue-tsc --noEmit    — when a tsconfig and vue-tsc binary exist next to
 *                            the tree (host checkout context);
 *   3. relative-import scan — always: every relative import specifier inside
 *                            the packaged tree must resolve to a real file.
 *
 * Any failure throws and the caller must refuse to produce the ZIP.
 *
 * CLI: php tools/frontend-gate.php <checkoutRoot> [app]
 */

const SAND_AI_GATE_TIMEOUT_SECONDS = 600;

/**
 * Run a command with a hard timeout; returns the exit code. The child process
 * is terminated when the timeout elapses so a stuck toolchain cannot hold the
 * gate open.
 *
 * @param list<string> $argv
 */
function sandAiGateRun(array $argv, string $cwd, int $timeoutSeconds = SAND_AI_GATE_TIMEOUT_SECONDS): int
{
    $command = implode(' ', array_map('escapeshellarg', $argv));
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动前端检查命令：' . $argv[0]);
    }
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            proc_close($process);
            return is_int($status['exitcode']) && $status['exitcode'] >= 0 ? $status['exitcode'] : 1;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('前端检查命令超时（' . $timeoutSeconds . ' 秒）：' . $argv[0]);
        }
        usleep(100000);
    } while (true);
}

/**
 * Scan every relative import specifier inside a .ts/.vue tree and fail on any
 * that cannot resolve. Alias imports (@/, host paths) are not checked here —
 * they belong to the host project — but a missing relative file is an actual
 * packaging break.
 *
 * @return array{files:int,imports:int,unresolved:list<string>}
 */
function sandAiScanRelativeImports(string $frontend): array
{
    $scanned = 0;
    $imports = 0;
    $unresolved = [];
    $spec = '/(?:from\s*|import\s*\(?\s*|import\s+)[\'"](\.{1,2}\/[^\'"]+)[\'"]/';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($frontend, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()
            || !in_array($item->getExtension(), ['ts', 'vue'], true)) {
            continue;
        }
        $scanned++;
        $dir = $item->getPath();
        $text = file_get_contents($item->getPathname());
        if (!is_string($text)) {
            throw new RuntimeException('无法读取前端文件：' . $item->getPathname());
        }
        if (preg_match_all($spec, $text, $matches) === false) {
            throw new RuntimeException('前端 import 扫描失败：' . $item->getPathname());
        }
        foreach ($matches[1] as $candidate) {
            $imports++;
            $base = $dir . '/' . $candidate;
            $resolved = false;
            foreach (['', '.ts', '.vue', '.d.ts', '.tsx', '.css'] as $suffix) {
                if (is_file($base . $suffix)) {
                    $resolved = true;
                    break;
                }
            }
            if (!$resolved) {
                foreach (['/index.ts', '/index.vue', '/index.css'] as $index) {
                    if (is_file($base . $index)) {
                        $resolved = true;
                        break;
                    }
                }
            }
            if (!$resolved) {
                $unresolved[] = substr($item->getPathname(), strlen($frontend) + 1) . ': ' . $candidate;
            }
        }
    }

    return ['files' => $scanned, 'imports' => $imports, 'unresolved' => $unresolved];
}

/**
 * Apply the strictest available frontend build gate on the packaged tree.
 * $checkoutRoot is the clean tag checkout used for packaging, never the
 * working tree.
 */
function sandAiFrontendGate(string $checkoutRoot, string $app): void
{
    $frontend = $checkoutRoot . '/sandadmin-artd/src/views/plugin/' . $app;
    if (!is_dir($frontend)) {
        echo "前端闸门：无管理端载荷树，跳过\n";
        return;
    }

    // 1) Independently buildable frontend tree → real vite build.
    if (is_file($frontend . '/package.json')) {
        $vite = is_file($frontend . '/node_modules/.bin/vite')
            ? $frontend . '/node_modules/.bin/vite'
            : (is_file($checkoutRoot . '/node_modules/.bin/vite')
                ? $checkoutRoot . '/node_modules/.bin/vite' : null);
        if ($vite !== null) {
            echo "前端闸门：执行 vite build\n";
            if (sandAiGateRun([$vite, 'build'], $frontend) !== 0) {
                throw new RuntimeException('前端 vite build 失败，拒绝产出发行 ZIP');
            }
        }
    }

    // 2) Host-context type check when the toolchain is present.
    $vueTsc = is_file($checkoutRoot . '/node_modules/.bin/vue-tsc')
        ? $checkoutRoot . '/node_modules/.bin/vue-tsc' : null;
    $hasTsConfig = glob($checkoutRoot . '/tsconfig*.json') !== [];
    if ($vueTsc !== null && $hasTsConfig) {
        echo "前端闸门：执行 vue-tsc --noEmit\n";
        if (sandAiGateRun([$vueTsc, '--noEmit'], $checkoutRoot) !== 0) {
            throw new RuntimeException('前端 vue-tsc --noEmit 失败，拒绝产出发行 ZIP');
        }
    }

    // 3) Always: relative-import resolution floor on the packaged tree.
    $result = sandAiScanRelativeImports($frontend);
    echo '前端闸门：相对 import 解析 ' . $result['files'] . ' 个文件 / '
        . $result['imports'] . " 条相对 import\n";
    if ($result['unresolved'] !== []) {
        foreach ($result['unresolved'] as $entry) {
            fwrite(STDERR, '  未解析：' . $entry . "\n");
        }
        throw new RuntimeException('发布前端树存在 ' . count($result['unresolved']) . ' 条不可解析相对 import，拒绝产出发行 ZIP');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $root = $argv[1] ?? null;
        $appName = $argv[2] ?? 'sand-ai';
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('用法：php tools/frontend-gate.php <检出根目录> [app]');
        }
        sandAiFrontendGate((string) realpath($root), $appName);
        echo "前端闸门：通过\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, '失败：' . $exception->getMessage() . "\n");
        exit(1);
    }
}
