<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Infrastructure\Persistence\Model\AuditLog;

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
        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        foreach ($result['data'] as &$row) {
            if (in_array($row['action'] ?? null, ['catalog.provider.save','catalog.provider.update','catalog.model.save','catalog.model.update'], true)) {
                // Catalogue idempotency HMACs remain internal; ordinary audit reads expose only a safe receipt.
                $context = $row['context'] ?? null;
                if (is_object($context)) $context = (array) $context;
                $row['context'] = is_array($context) ? array_intersect_key($context, array_flip(['version','target_id','code','receipt'])) : [];
            } elseif (($row['action'] ?? null) === 'billing.credential.update') {
                $context = $row['context'] ?? null;
                if (is_object($context)) $context = (array) $context;
                if (is_string($context)) $context = json_decode($context, true);
                // Keyed billing proofs stay internal; no organization, Key or HMAC enters the public audit DTO.
                $row['context'] = is_array($context) ? array_intersect_key($context, array_flip(['version','provider_id','receipt'])) : [];
            }
            if (in_array($row['action'] ?? null, ['legal_analysis_profile.draft','legal_analysis_profile.publish','legal_analysis_profile.revoke'], true)) {
                $context = $row['context'] ?? null;
                if (is_object($context)) $context = (array) $context;
                if (is_string($context)) $context = json_decode($context, true);
                // Original profile body proofs and snapshots are available only through the scoped operation API.
                $row['context'] = is_array($context) ? array_intersect_key($context, array_flip(['code','profile_version','version','revision','expected_revision'])) : [];
            }
        }
        unset($row);
        return $result;
    }
}
