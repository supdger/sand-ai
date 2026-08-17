<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\model\UsageRecord;

/** Read-only SandAI usage search use case owned by the full package. */
final class UsageLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $query = UsageRecord::order('create_time', 'desc');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '') {
            $query->whereLike('source', '%' . $name . '%');
        }
        if (($input['status'] ?? '') !== '') {
            $query->where('status', (int) $input['status']);
        }
        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }
}
