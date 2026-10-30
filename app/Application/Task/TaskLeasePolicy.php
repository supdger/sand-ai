<?php

declare(strict_types=1);

namespace app\Application\Task;

use app\Api\Support\ApiProblem;

final class TaskLeasePolicy
{
    /** RUNNING timestamp-without-zone rows use the trusted application's date() clock. */
    public static function runningTimezone(): string
    {
        $zone = config('app.default_timezone');
        if (!is_string($zone) || strlen($zone) > 128
            || !in_array($zone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || date_default_timezone_get() !== $zone) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', '任务运行时区与宿主可信配置不一致，请管理员核对app.default_timezone与Worker初始化后重试');
        }
        return $zone;
    }

    public static function seconds(): int
    {
        $value = config('sand_ai_task.lease_seconds', 300);
        if (!is_int($value) || $value < 60 || $value > 3600) {
            throw new ApiProblem('SAND_AI_TASK_LEASE_LOST', 'Task lease configuration is invalid');
        }
        return $value;
    }
}
