<?php

declare(strict_types=1);

namespace app\Application\Audit;

/**
 * 审计列表在读出时合成「同一人、浏览器当地同一天」的试用签发。
 * 库存 create_time 没有时区，按协调世界时理解。
 */
final class AuditRows
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

    public static function today(int $offsetMinutes): string
    {
        $offset = self::clampOffset($offsetMinutes);
        $utc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sign = $offset >= 0 ? '+' : '';
        return $utc->modify($sign . $offset . ' minutes')->format('Y-m-d');
    }

    public static function localDate(string $utcTime, int $offsetMinutes): string
    {
        $utc = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utcTime, new \DateTimeZone('UTC'));
        if ($utc === false) {
            return '';
        }
        $offset = self::clampOffset($offsetMinutes);
        $sign = $offset >= 0 ? '+' : '';
        return $utc->modify($sign . $offset . ' minutes')->format('Y-m-d');
    }

    public static function trialSentence(string $localDate, string $today, int $count): string
    {
        $times = $count . ' 次';
        if ($localDate !== '' && $localDate === $today) {
            return '今天签发试用身份 ' . $times;
        }
        if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $localDate, $parts) === 1) {
            return (int) $parts[1] . '-' . (int) $parts[2] . ' 签发试用身份 ' . $times;
        }
        return '签发试用身份 ' . $times;
    }

    public static function resultLabel(string $action): string
    {
        foreach (['failed', 'denied', 'rejected'] as $mark) {
            if (str_contains($action, $mark)) {
                return '失败';
            }
        }
        return '成功';
    }

    public static function personName(string $realname, string $username): string
    {
        $shown = trim($realname);
        if ($shown !== '') {
            return $shown;
        }
        $login = trim($username);
        if ($login !== '' && $login !== 'admin') {
            return $login;
        }
        return '管理员';
    }

    /** 列表句子里不出现「版本」「索引」「重建」。 */
    public static function plainSentence(string $sentence): string
    {
        $replaced = strtr($sentence, [
            '已重建知识向量索引' => '整理好了资料',
            '重建知识向量索引' => '整理资料',
            '清除知识向量索引' => '清掉了整理结果',
        ]);
        $replaced = str_replace('版本', '', $replaced);
        $replaced = str_replace('索引', '整理', $replaced);
        $replaced = str_replace('重建', '重新整理', $replaced);
        return trim($replaced);
    }

    /**
     * 输入须已按 create_time、id 倒序。试用签发按人和当地日期合成一行。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function mergeTrialIssues(array $rows, int $offsetMinutes, string $today): array
    {
        $single = [];
        $groups = [];
        foreach ($rows as $row) {
            $action = (string) ($row['action'] ?? '');
            if ($action !== 'admin_trial.context.issue') {
                $single[] = self::asRow($row, 1, []);
                continue;
            }
            $localDate = self::localDate((string) ($row['create_time'] ?? ''), $offsetMinutes);
            $key = (string) ($row['actor_ref'] ?? '') . '|' . $localDate;
            if (!isset($groups[$key])) {
                $groups[$key] = self::asRow($row, 0, []);
                $groups[$key]['local_date'] = $localDate;
                $groups[$key]['entries'] = [];
            }
            $groups[$key]['issue_count'] = ((int) $groups[$key]['issue_count']) + 1;
            $groups[$key]['entries'][] = ['create_time' => (string) ($row['create_time'] ?? '')];
        }
        $merged = $single;
        foreach ($groups as $group) {
            $group['sentence_key'] = self::trialSentence(
                (string) $group['local_date'],
                $today,
                (int) $group['issue_count']
            );
            $merged[] = $group;
        }
        usort($merged, static function (array $left, array $right): int {
            $byTime = strcmp((string) $right['create_time'], (string) $left['create_time']);
            if ($byTime !== 0) {
                return $byTime;
            }
            return ((int) $right['id']) <=> ((int) $left['id']);
        });
        return $merged;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, string>> $entries
     * @return array<string, mixed>
     */
    private static function asRow(array $row, int $issueCount, array $entries): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'create_time' => (string) ($row['create_time'] ?? ''),
            'actor_type' => (string) ($row['actor_type'] ?? ''),
            'actor_ref' => (string) ($row['actor_ref'] ?? ''),
            'action' => (string) ($row['action'] ?? ''),
            'resource_type' => (string) ($row['resource_type'] ?? ''),
            'resource_id' => (int) ($row['resource_id'] ?? 0),
            'summary' => (string) ($row['summary'] ?? ''),
            'issue_count' => $issueCount,
            'entries' => $entries,
            'local_date' => '',
            'sentence_key' => '',
        ];
    }
}
