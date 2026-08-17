<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\model\AuditLog;

/** Read-only SandAI audit search use case owned by the full package. */
final class AuditLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $query = AuditLog::order('create_time', 'desc');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name !== '') {
            $query->where(function ($filter) use ($name): void {
                $filter->whereLike('summary', '%' . $name . '%')->whereOr('actor_ref', 'like', '%' . $name . '%');
            });
        }
        $action = trim((string) ($input['action'] ?? ''));
        if ($action !== '') {
            $query->whereLike('action', '%' . $action . '%');
        }
        if (($input['status'] ?? '') !== '') {
            $query->where('status', (int) $input['status']);
        }
        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }
}
