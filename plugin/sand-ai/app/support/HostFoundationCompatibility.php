<?php

declare(strict_types=1);

namespace plugin\SandAi\app\support;

use Composer\InstalledVersions;

/** SandAI-only loading gate; never installs or changes a host dependency. */
final class HostFoundationCompatibility
{
    private const MINIMUMS = ['supdger/sand-core' => '0.2.4', 'supdger/sand-package' => '0.2.7'];

    /** @return array{ready:bool,message:string} */
    public static function status(): array
    {
        $ready = true;
        $observed = [];
        foreach (self::MINIMUMS as $package => $minimum) {
            $version = null;
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    $installed = InstalledVersions::getVersion($package);
                    // Composer normalizes stable tags to x.y.z.0. A branch,
                    // replacement without a version, or prerelease is unknown.
                    if (is_string($installed) && strlen($installed) <= 64
                        && preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:\.0)?$/D', $installed) === 1) {
                        $version = preg_replace('/\.0$/D', '', $installed);
                        // Only strip the fourth normalization component.
                        if (substr_count($installed, '.') === 2) $version = $installed;
                    }
                }
            } catch (\Throwable) {
                // Missing or unreadable installed metadata closes this domain,
                // without throwing during the shared host configuration load.
                $version = null;
            }
            $compatible = is_string($version) && version_compare($version, $minimum, '>=');
            $ready = $ready && $compatible;
            $observed[] = $package . ' >=' . $minimum . '，当前 ' . ($version ?? '未知或未安装');
        }
        return [
            'ready' => $ready,
            'message' => $ready ? 'SandAI 宿主基础依赖满足最低版本要求。'
                : 'SandAI 未启用：基础依赖须为正式版本并满足各自最低版本；'
                    . implode('，', $observed)
                    . '。请管理员通过宿主根 Composer 的正式依赖管理更新，再按宿主维护流程重载；SandAI 不会自动安装或修改基础依赖。',
        ];
    }
}
