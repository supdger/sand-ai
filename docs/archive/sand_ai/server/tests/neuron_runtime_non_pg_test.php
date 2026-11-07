<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The shared vendor directory resolves app\ against the source checkout it was
// installed in; this standalone test must bind app\ to its own worktree so new
// classes under test are found regardless of which checkout runs the file.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'app\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

use app\Infrastructure\Support\NeuronRuntime;
use Composer\Autoload\ClassLoader;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Neuron runtime check failed: ' . $label);
    }
    ++$checks;
};

/* ---------- 健康宿主：包已装且可加载 ---------- */

$assert(NeuronRuntime::available() === true, 'installed SDK reports available');
$assert(class_exists(\NeuronAI\Providers\OpenAI\OpenAI::class), 'marker class resolvable after probe');

/* ---------- 陈旧宿主 autoloader 模拟：fork 继承的 loader 找不到 NeuronAI ----------
 * Workerman worker 从 master fork，master 在 composer 依赖安装前初始化的
 * ClassLoader 永远缺少后写入的 psr-4 映射。测试通过从 spl 栈临时摘除全部
 * 既有 composer loader 复现该形态；InstalledVersions 仍按调用时
 * installed.php 正常上报包已装，available() 必须靠包自身清单把 SDK
 * 前缀补回来。
 */

$detached = [];
foreach (ClassLoader::getRegisteredLoaders() as $loader) {
    if (spl_autoload_unregister([$loader, 'loadClass'])) {
        $detached[] = $loader;
    }
}
$assert($detached !== [], 'composer loaders detached for stale-master simulation');
$staleMarker = \NeuronAI\Agent\Agent::class;
$assert(!class_exists($staleMarker), 'stale loader cannot resolve SDK class');

try {
    $assert(NeuronRuntime::available($staleMarker) === true, 'probe heals autoload from installed package manifest');
    $assert(
        class_exists(\NeuronAI\Chat\Messages\UserMessage::class),
        'healed loader serves the whole NeuronAI prefix, not only the marker',
    );
} finally {
    foreach ($detached as $loader) {
        $loader->register();
    }
}

/* ---------- 幂等与复用 ---------- */

$assert(NeuronRuntime::available() === true, 'probe stays available after heal');
$assert(
    NeuronRuntime::available(\NeuronAI\RAG\Splitter\DelimiterTextSplitter::class) === true,
    'alternate marker class honoured',
);

/* ---------- 不存在 marker：不误报 ---------- */

$assert(
    NeuronRuntime::available('NeuronAI\\DefinitelyMissing\\Marker' . str_replace('.', '', uniqid('', true))) === false,
    'missing marker class still reports unavailable',
);

echo 'neuron_runtime_non_pg_test OK (' . $checks . " checks)\n";
