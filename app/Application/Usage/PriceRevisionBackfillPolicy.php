<?php

declare(strict_types=1);

namespace app\Application\Usage;

/** Mirrors the migration's deterministic allocation for legacy null revisions. */
final class PriceRevisionBackfillPolicy
{
    /**
     * @param list<array{id: int, provider_id: int, model_id: int, capability_code: string, revision: int|null, effective_from: string|null}> $rows
     * @return array<int, int> row id => assigned revision; existing revisions are never returned or changed
     */
    public function allocate(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['provider_id'] . ':' . $row['model_id'] . ':' . $row['capability_code'];
            $groups[$key][] = $row;
        }

        $allocated = [];
        foreach ($groups as $rowsInGroup) {
            $maximum = 0;
            $missing = [];
            foreach ($rowsInGroup as $row) {
                if ($row['revision'] === null) {
                    $missing[] = $row;
                } else {
                    $maximum = max($maximum, $row['revision']);
                }
            }
            usort($missing, static function (array $left, array $right): int {
                $leftTime = $left['effective_from'] ?? '';
                $rightTime = $right['effective_from'] ?? '';
                $timeOrder = strcmp($leftTime, $rightTime);

                return $timeOrder !== 0 ? $timeOrder : $left['id'] <=> $right['id'];
            });
            foreach ($missing as $offset => $row) {
                $allocated[$row['id']] = $maximum + $offset + 1;
            }
        }

        return $allocated;
    }
}
