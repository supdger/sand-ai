<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use support\Log;
use think\facade\Db;

/** Bounded Embedding expiry closure and receipt-only query settlement. */
final class KnowledgeQueryEmbeddingRecoveryService
{
    public function run(int $limit = 20): int
    {
        $limit = min(50, max(1, $limit));
        $recovered = (new EmbeddingPreparedExpiryRecoveryService())->run(min(10, $limit));
        $rows = Db::connect('pgsql')->query(
            "SELECT d.invocation_id
               FROM sand_ai_invocation_dispatch d
               JOIN sand_ai_invocation i ON i.id=d.invocation_id
               JOIN sand_ai_invocation_outcome_receipt r ON r.invocation_id=i.id
              WHERE d.execution_scope='embedding' AND d.source_kind='knowledge_query'
                AND d.service_action='sand_ai.knowledge.query'
                AND d.state='dispatching' AND i.state='running'
                AND r.state='available' AND r.expire_time>(clock_timestamp() AT TIME ZONE 'UTC')
              ORDER BY d.id LIMIT " . $limit,
        );
        $settled = 0;
        foreach ($rows as $row) {
            try {
                (new KnowledgeQueryEmbeddingGateway())->recoverSealed((int) $row['invocation_id']);
                $settled++;
            } catch (\Throwable $failure) {
                Log::warning('SandAI knowledge query receipt recovery failed', [
                    'invocation_id' => (int) $row['invocation_id'],
                    'exception_type' => $failure::class,
                ]);
            }
        }
        return $settled + $recovered;
    }
}
