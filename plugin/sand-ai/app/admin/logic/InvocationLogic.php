<?php

declare(strict_types=1);

namespace plugin\SandAi\app\admin\logic;

use app\Application\Invocation\InvocationRows;
use app\Infrastructure\Persistence\Model\Invocation;

/** Read-only invocation observability use case owned by the full package. */
final class InvocationLogic
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $limit = min(100, max(1, (int) ($input['limit'] ?? 10)));
        $offset = InvocationRows::clampOffset((int) ($input['offset_minutes'] ?? 0));
        $query = Invocation::alias('invocation')
            ->leftJoin('sand_ai_model model', 'model.id = invocation.model_id')
            ->leftJoin('sand_ai_usage usage_row', 'usage_row.invocation_id = invocation.id AND usage_row.delete_time IS NULL')
            ->leftJoin('sand_iam_application application', 'application.id = invocation.application_id')
            ->leftJoin('sand_iam_workload_client client', 'client.id = invocation.workload_client_id')
            ->field('invocation.id,invocation.request_id,invocation.environment_id,invocation.state,invocation.latency_ms,invocation.request_at,model.name as model_name,model.type as model_type,usage_row.cost_amount as spend_amount,usage_row.input_tokens as input_tokens,usage_row.output_tokens as output_tokens,application.name as application_name,client.name as client_name,invocation.knowledge_publication_id as knowledge_id')
            ->order('invocation.request_at', 'desc')
            ->order('invocation.id', 'desc');
        $state = trim((string) ($input['state'] ?? ''));
        if ($state !== '') {
            $query->where('invocation.state', $state);
        }
        $purpose = trim((string) ($input['purpose'] ?? ''));
        if ($purpose === 'knowledge') {
            $query->whereNotNull('invocation.knowledge_publication_id');
        } elseif ($purpose === 'embed') {
            $query->whereNull('invocation.knowledge_publication_id')->where('model.type', 'embedding');
        } elseif ($purpose === 'chat') {
            $query->whereNull('invocation.knowledge_publication_id')->where('model.type', 'chat');
        }
        $from = InvocationRows::utcStamp((string) ($input['request_from'] ?? ''), '00:00:00', $offset);
        $to = InvocationRows::utcStamp((string) ($input['request_to'] ?? ''), '23:59:59', $offset);
        if ($from !== null) {
            $query->where('invocation.request_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('invocation.request_at', '<=', $to);
        }
        $requestId = trim((string) ($input['request_id'] ?? ''));
        if ($requestId !== '') {
            $query->whereLike('invocation.request_id', '%' . addcslashes($requestId, '%_\\') . '%');
        }
        $result = $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
        $rows = [];
        foreach ($result['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $knowledgeId = $row['knowledge_id'] ?? null;
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'request_id' => (string) ($row['request_id'] ?? ''),
                'environment_id' => (int) ($row['environment_id'] ?? 0),
                'state' => (string) ($row['state'] ?? ''),
                'latency_ms' => $row['latency_ms'] === null || $row['latency_ms'] === '' ? null : (int) $row['latency_ms'],
                'request_at' => (string) ($row['request_at'] ?? ''),
                'model_name' => trim((string) ($row['model_name'] ?? '')) !== '' ? (string) $row['model_name'] : '未知模型',
                'caller_name' => InvocationRows::callerName(
                    isset($row['client_name']) ? (string) $row['client_name'] : null,
                    isset($row['application_name']) ? (string) $row['application_name'] : null
                ),
                'purpose' => InvocationRows::purposeLabel(
                    (string) ($row['model_type'] ?? ''),
                    $knowledgeId !== null && $knowledgeId !== ''
                ),
                'spend_amount' => $row['spend_amount'] === null || $row['spend_amount'] === '' ? null : (string) $row['spend_amount'],
                'input_tokens' => $row['input_tokens'] === null || $row['input_tokens'] === '' ? null : (int) $row['input_tokens'],
                'output_tokens' => $row['output_tokens'] === null || $row['output_tokens'] === '' ? null : (int) $row['output_tokens'],
            ];
        }
        $result['data'] = $rows;
        return $result;
    }
}
