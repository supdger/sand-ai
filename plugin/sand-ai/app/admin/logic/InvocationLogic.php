<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use plugin\SandAi\app\model\Invocation;

/** Read-only invocation observability use case owned by the full package. */
final class InvocationLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $query = Invocation::order('request_at', 'desc');
        $requestId = trim((string) ($input['request_id'] ?? ''));
        if ($requestId !== '') {
            $query->whereLike('request_id', '%' . $requestId . '%');
        }
        $state = trim((string) ($input['state'] ?? ''));
        if ($state !== '') {
            $query->where('state', $state);
        }
        if (($input['status'] ?? '') !== '') {
            $query->where('status', (int) $input['status']);
        }
        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }
}
