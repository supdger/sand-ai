<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\controller;

use plugin\sandadmin\app\cache\UserInfoCache;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;

abstract class SandAiAdminController extends BaseController
{
    protected function authenticatedAdminId(): int
    {
        $request = request();
        $checked = $request->header('check_admin');
        $current = getCurrentInfo();
        if ($request->header('check_login') !== true
            || !is_array($checked)
            || !is_array($current)
            || ($checked['plat'] ?? null) !== 'sandadmin'
            || ($current['plat'] ?? null) !== 'sandadmin') {
            throw new ApiException('管理员登录状态无效，请重新登录', 401);
        }

        $checkedId = $this->positiveAdminId($checked['id'] ?? null);
        $currentId = $this->positiveAdminId($current['id'] ?? null);
        if ($checkedId === null || $checkedId !== $currentId) {
            throw new ApiException('管理员登录状态无效，请重新登录', 401);
        }

        return $checkedId;
    }

    /** @return array<string,mixed> */
    protected function authenticatedAdminInfo(): array
    {
        return UserInfoCache::getUserInfo($this->authenticatedAdminId());
    }

    private function positiveAdminId(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }
}
