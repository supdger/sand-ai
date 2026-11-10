<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Infrastructure\Persistence\Model\AiModel;
use plugin\SandIam\app\model\Application;

/**
 * Adds administrator-facing Chinese catalog names to period usage rows.
 * Soft-deleted resources keep their last name and are flagged so the
 * frontend can append （已删除）; rows without a catalog entry degrade to a
 * null name rather than exposing the bare identifier.
 */
final class UsagePeriodLogic
{
    private const UNATTRIBUTED = '未归属';

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function monthlyNames(string $groupBy, array $rows): array
    {
        $key = $groupBy === 'model' ? 'model_id' : 'application_id';
        $ids = [];
        foreach ($rows as $row) {
            $id = $row[$key] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            }
        }
        $names = $this->catalogNames($groupBy, array_keys($ids));
        foreach ($rows as $index => $row) {
            $id = $row[$key] ?? null;
            if ($id === null) {
                $rows[$index]['name'] = self::UNATTRIBUTED;
                $rows[$index]['deleted'] = false;
                continue;
            }
            $entry = is_int($id) ? ($names[$id] ?? null) : null;
            $rows[$index]['name'] = $entry['name'] ?? null;
            $rows[$index]['deleted'] = $entry === null || $entry['deleted'];
        }

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{name:?string,deleted:bool}>
     */
    private function catalogNames(string $groupBy, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $class = $groupBy === 'model'
            ? AiModel::class
            : (class_exists(Application::class) ? Application::class : null);
        if ($class === null) {
            return [];
        }
        $names = [];
        foreach ($class::withTrashed()->whereIn('id', $ids)->field('id,name,delete_time')->select() as $row) {
            $name = $row->getAttr('name');
            $names[(int) $row->getAttr('id')] = [
                'name' => $name === null ? null : (string) $name,
                'deleted' => $row->getAttr('delete_time') !== null,
            ];
        }

        return $names;
    }
}
