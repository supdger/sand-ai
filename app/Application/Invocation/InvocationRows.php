<?php

declare(strict_types=1);

namespace app\Application\Invocation;

/**
 * 调用记录列表上给人看的名字。
 * 库存的 request_at 没有时区，按协调世界时理解；筛选日期是浏览器当地的一天。
 */
final class InvocationRows
{
    public static function clampOffset(int $offsetMinutes): int
    {
        if ($offsetMinutes > 840) {
            return 840;
        }
        if ($offsetMinutes < -840) {
            return -840;
        }
        return $offsetMinutes;
    }

    public static function callerName(?string $clientName, ?string $applicationName): string
    {
        $client = trim((string) $clientName);
        if ($client === '管理员试用') {
            return '管理员';
        }
        if ($client !== '') {
            return $client;
        }
        $application = trim((string) $applicationName);
        if ($application !== '') {
            return $application;
        }
        return '未知调用方';
    }

    public static function purposeLabel(string $modelType, bool $hasKnowledge): string
    {
        if ($hasKnowledge) {
            return '查资料';
        }
        if ($modelType === 'embedding') {
            return '整理资料';
        }
        if ($modelType === 'chat') {
            return '对话';
        }
        return '其他';
    }

    /** 当地某一天的起点或终点，换回库存使用的协调世界时。 */
    public static function utcStamp(string $day, string $clock, int $offsetMinutes): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }
        if ($clock !== '00:00:00' && $clock !== '23:59:59') {
            return null;
        }
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $day . ' ' . $clock, new \DateTimeZone('UTC'));
        if ($local === false) {
            return null;
        }
        $minutes = abs(self::clampOffset($offsetMinutes));
        $sign = $offsetMinutes >= 0 ? '-' : '+';
        return $local->modify($sign . $minutes . ' minutes')->format('Y-m-d H:i:s');
    }
}
