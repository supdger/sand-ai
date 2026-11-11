<?php

declare(strict_types=1);

namespace app\Application\Knowledge;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\AuditLog;
use think\facade\Db;
use Throwable;

/**
 * Automatic embedding-index orchestration. One durable task row per published
 * knowledge version; the worker advances the current index one source block
 * per tick through KnowledgeEmbeddingBuildGateway so budget, usage, receipts
 * and provider dispatch stay on the existing billed path. The task row only
 * tracks scheduling and the user-facing failure projection.
 */
final class IndexTaskGateway
{
    private const BATCH_LIMIT = 8;
    private const RETRY_DELAY_SECONDS = 3;

    /** Queue index construction for a freshly published version; idempotent. */
    public function ensure(int $environmentId, int $knowledgeBaseId, int $versionId): void
    {
        if ($environmentId < 1 || $knowledgeBaseId < 1 || $versionId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge index task target is invalid');
        }
        Db::connect('pgsql')->transaction(function () use ($environmentId, $knowledgeBaseId, $versionId): void {
            Db::connect('pgsql')->execute(
                "INSERT INTO sand_ai_knowledge_index_task
                 (environment_id,knowledge_base_id,knowledge_base_version_id)
                 VALUES (?,?,?) ON CONFLICT (knowledge_base_version_id) DO NOTHING",
                [$environmentId, $knowledgeBaseId, $versionId],
            );
            // Adopt an index already building for this version (manual start or
            // a previous task attempt) so the worker does not open a second one.
            Db::connect('pgsql')->execute(
                "UPDATE sand_ai_knowledge_index_task j SET index_id=(
                     SELECT i.id FROM sand_ai_knowledge_embedding_index i
                     WHERE i.knowledge_base_version_id=j.knowledge_base_version_id
                       AND i.state='building' ORDER BY i.id DESC LIMIT 1),
                     state=CASE WHEN j.state='queued' AND EXISTS (
                         SELECT 1 FROM sand_ai_knowledge_embedding_index i
                         WHERE i.knowledge_base_version_id=j.knowledge_base_version_id
                           AND i.state='building') THEN 'building' ELSE j.state END,
                     update_time=CURRENT_TIMESTAMP
                 WHERE j.knowledge_base_version_id=?",
                [$versionId],
            );
        });
    }

    /** Advance up to $limit due tasks; returns the number processed. */
    public function runBatch(int $limit = self::BATCH_LIMIT): int
    {
        $ids = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_knowledge_index_task
             WHERE state IN ('queued','building') AND next_run_time<=CURRENT_TIMESTAMP
             ORDER BY id LIMIT ?",
            [max(1, min(32, $limit))],
        );
        $advanced = 0;
        foreach ($ids as $row) {
            try {
                $this->advance((int) $row['id']);
                $advanced++;
            } catch (Throwable $exception) {
                // One bad task must not stall the rest of the queue.
                \support\Log::warning('SandAI knowledge index task failed', [
                    'task_id' => (int) $row['id'],
                    'exception_type' => $exception::class,
                ]);
            }
        }
        return $advanced;
    }

    /**
     * Advance one task: begin the index when missing, then embed one source
     * block. Failures land in state 'failed' with a stable error code; the
     * administrator retry path re-queues with a fresh attempt.
     */
    public function advance(int $taskId): void
    {
        $taskRow = $this->taskRow($taskId);
        if ($taskRow === null || in_array((string) $taskRow['state'], ['ready', 'failed'], true)) {
            return;
        }
        $environmentId = (int) $taskRow['environment_id'];
        $versionId = (int) $taskRow['knowledge_base_version_id'];
        $attempt = (int) $taskRow['attempt_count'];
        $indexId = $taskRow['index_id'] === null ? null : (int) $taskRow['index_id'];
        try {
            if ($indexId === null) {
                $indexId = $this->beginIndex($environmentId, $versionId, $taskId, $attempt);
                $this->syncTask($taskId, $attempt, $indexId, 'building', null);
            }
            $index = $this->indexState($indexId);
            if ($index === 'ready') {
                $this->syncTask($taskId, $attempt, $indexId, 'ready', null);
                return;
            }
            if ($index !== 'building') {
                $this->syncTask($taskId, $attempt, $indexId, 'failed', 'SAND_AI_KNOWLEDGE_INDEX_FAILED');
                return;
            }
            $result = (new KnowledgeEmbeddingBuildGateway())->buildNext($environmentId, $indexId);
            $state = is_array($result) ? (string) ($result['state'] ?? '') : '';
            if ($state === 'ready') {
                $this->syncTask($taskId, $attempt, $indexId, 'ready', null);
                return;
            }
            // 'indexed' keeps building; 'in_progress'/'outcome_unknown' retry next tick.
            $this->syncTask($taskId, $attempt, $indexId, 'building', null, self::RETRY_DELAY_SECONDS);
        } catch (ApiProblem $problem) {
            // A concurrent activation can make buildNext report conflict while
            // the index is actually ready; never project that as a failure.
            if ($indexId !== null && $this->indexState($indexId) === 'ready') {
                $this->syncTask($taskId, $attempt, $indexId, 'ready', null);
                return;
            }
            $this->syncTask($taskId, $attempt, $indexId, 'failed', $problem->errorCode);
        } catch (Throwable) {
            // Non-domain failures (database, serialization) must not spin the
            // queue every tick; the failed projection stays retryable.
            try {
                if ($indexId !== null && $this->indexState($indexId) === 'ready') {
                    $this->syncTask($taskId, $attempt, $indexId, 'ready', null);
                    return;
                }
                $this->syncTask($taskId, $attempt, $indexId, 'failed', 'SAND_AI_KNOWLEDGE_INDEX_FAILED');
            } catch (Throwable) {
                // The sweep logs and retries on the next tick.
            }
        }
    }

    /**
     * User-facing retry for a failed (or never started) index. Only a failed
     * task re-queues; ready/in-flight tasks return their current projection.
     *
     * @return array<string,mixed>
     */
    public function retry(int $environmentId, int $knowledgeBaseId, string $actorRef): array
    {
        if ($environmentId < 1 || $knowledgeBaseId < 1 || trim($actorRef) === '') {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_VALIDATION_ERROR', 'Knowledge index retry request is invalid');
        }
        $versionId = $this->activeVersionId($environmentId, $knowledgeBaseId);
        $this->ensure($environmentId, $knowledgeBaseId, $versionId);
        $taskRow = Db::connect('pgsql')->transaction(function () use ($versionId): ?array {
            $rows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_knowledge_index_task WHERE knowledge_base_version_id=? FOR UPDATE',
                [$versionId],
            );
            if ($rows === []) {
                return null;
            }
            $taskRow = $rows[0];
            if ((string) $taskRow['state'] === 'failed') {
                // A failed index row is immutable; the next attempt begins a
                // fresh index under a new attempt-scoped request key.
                Db::connect('pgsql')->execute(
                    "UPDATE sand_ai_knowledge_index_task
                     SET state='queued',index_id=NULL,error_code=NULL,
                         attempt_count=attempt_count+1,next_run_time=CURRENT_TIMESTAMP,
                         update_time=CURRENT_TIMESTAMP
                     WHERE id=? AND state='failed'",
                    [(int) $taskRow['id']],
                );
                $taskRow = $this->taskRow((int) $taskRow['id']);
            }
            return $taskRow;
        });
        if ($taskRow === null) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Knowledge index task is unavailable');
        }
        $state = (string) $taskRow['state'];
        $this->audit($actorRef, $environmentId, $knowledgeBaseId, $versionId, $state);
        return [
            'knowledge_base_id' => $knowledgeBaseId,
            'index_state' => $state === 'ready' ? 'ready' : ($state === 'failed' ? 'failed' : 'indexing'),
            'index_error' => $state === 'failed' ? self::errorText($taskRow['error_code'] === null ? null : (string) $taskRow['error_code']) : null,
        ];
    }

    /**
     * Three-state projection for list rows: 'indexing' | 'ready' | 'failed'.
     * Versions without any task fall back to the latest index row so the
     * pre-0.5.0 manual flow still projects correctly.
     *
     * @param list<int> $versionIds
     * @return array<int,array{state:string,error:?string}>
     */
    public function projection(array $versionIds): array
    {
        $versionIds = array_values(array_unique(array_filter($versionIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($versionIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($versionIds), '?'));
        $tasks = Db::connect('pgsql')->query(
            "SELECT knowledge_base_version_id,state,error_code FROM sand_ai_knowledge_index_task
             WHERE knowledge_base_version_id IN ({$marks})",
            $versionIds,
        );
        $byVersion = [];
        foreach ($tasks as $taskRow) {
            $byVersion[(int) $taskRow['knowledge_base_version_id']] = $taskRow;
        }
        $missing = array_values(array_diff($versionIds, array_keys($byVersion)));
        $indexes = [];
        if ($missing !== []) {
            $marks = implode(',', array_fill(0, count($missing), '?'));
            $rows = Db::connect('pgsql')->query(
                "SELECT DISTINCT ON (knowledge_base_version_id) knowledge_base_version_id,state
                 FROM sand_ai_knowledge_embedding_index
                 WHERE knowledge_base_version_id IN ({$marks})
                 ORDER BY knowledge_base_version_id,id DESC",
                $missing,
            );
            foreach ($rows as $row) {
                $indexes[(int) $row['knowledge_base_version_id']] = (string) $row['state'];
            }
        }
        $projection = [];
        foreach ($versionIds as $versionId) {
            if (isset($byVersion[$versionId])) {
                $taskRow = $byVersion[$versionId];
                $state = (string) $taskRow['state'];
                $projection[$versionId] = [
                    'state' => match ($state) {
                        'ready' => 'ready',
                        'failed' => 'failed',
                        default => 'indexing',
                    },
                    'error' => $state === 'failed'
                        ? self::errorText($taskRow['error_code'] === null ? null : (string) $taskRow['error_code'])
                        : null,
                ];
                continue;
            }
            $indexState = $indexes[$versionId] ?? null;
            $projection[$versionId] = match ($indexState) {
                'ready' => ['state' => 'ready', 'error' => null],
                'building' => ['state' => 'indexing', 'error' => null],
                'failed', 'outcome_unknown' => ['state' => 'failed', 'error' => self::errorText(null)],
                default => ['state' => 'failed', 'error' => '还没有建立索引'],
            };
        }
        return $projection;
    }

    private function beginIndex(int $environmentId, int $versionId, int $taskId, int $attempt): int
    {
        $result = (new KnowledgeEmbeddingIndexGateway())->begin(
            $environmentId,
            $versionId,
            'auto-index:' . $taskId . ':' . $attempt,
        );
        $indexId = is_array($result) ? ($result['id'] ?? null) : null;
        if (!is_int($indexId) || $indexId < 1) {
            throw new ApiProblem('SAND_AI_KNOWLEDGE_INDEX_FAILED', 'Embedding index could not be reserved');
        }
        return $indexId;
    }

    private function indexState(int $indexId): string
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT state FROM sand_ai_knowledge_embedding_index WHERE id=?',
            [$indexId],
        );
        return $rows === [] ? 'missing' : (string) $rows[0]['state'];
    }

    /** @return array<string,mixed>|null */
    private function taskRow(int $taskId): ?array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT * FROM sand_ai_knowledge_index_task WHERE id=?',
            [$taskId],
        );
        return $rows === [] ? null : $rows[0];
    }

    private function activeVersionId(int $environmentId, int $knowledgeBaseId): int
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT kb.active_version_id FROM sand_ai_knowledge_base kb
             WHERE kb.id=? AND kb.environment_id=? AND kb.delete_time IS NULL
               AND kb.status=1 AND kb.state='published'",
            [$knowledgeBaseId, $environmentId],
        );
        $versionId = $rows === [] ? null : $rows[0]['active_version_id'];
        if (!is_int($versionId) || $versionId < 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Published knowledge version is unavailable');
        }
        return $versionId;
    }

    private function syncTask(int $taskId, int $attempt, ?int $indexId, string $state, ?string $errorCode, int $delaySeconds = 0): void
    {
        Db::connect('pgsql')->execute(
            "UPDATE sand_ai_knowledge_index_task
             SET state=?,index_id=COALESCE(?,index_id),error_code=?,
                 next_run_time=CURRENT_TIMESTAMP + make_interval(secs => ?),
                 update_time=CURRENT_TIMESTAMP
             WHERE id=? AND attempt_count=? AND state IN ('queued','building')",
            [$state, $indexId, $errorCode, max(0, $delaySeconds), $taskId, $attempt],
        );
    }

    private function audit(string $actorRef, int $environmentId, int $knowledgeBaseId, int $versionId, string $state): void
    {
        AuditLog::create([
            'actor_type' => 'admin',
            'actor_ref' => trim($actorRef),
            'action' => 'knowledge.index.retry',
            'resource_type' => 'knowledge_base',
            'resource_id' => $knowledgeBaseId,
            'summary' => 'Knowledge index retry requested',
            'context' => [
                'environment_id' => $environmentId,
                'version_id' => $versionId,
                'index_state' => $state,
            ],
        ]);
    }

    /** Safe Chinese copy for the status column; never leaks provider details. */
    public static function errorText(?string $code): string
    {
        if ($code === null || $code === '') {
            return '索引没有成功，请重试';
        }
        return match (true) {
            str_contains($code, 'BUDGET') => 'AI 预算不足或已用完，请到费用页处理后重试',
            str_contains($code, 'CONFIGURATION') || str_contains($code, 'NOT_PUBLISHED') => '嵌入模型配置不可用，请检查能力配置后重试',
            str_contains($code, 'SOURCE') => '资料内容有变化，请重新发布知识库后重试',
            str_contains($code, 'RECEIPT') || str_contains($code, 'DISPATCH') => '索引结果没有确认成功，请稍后重试',
            str_contains($code, 'PROVIDER') || str_contains($code, 'UNREACHABLE') || str_contains($code, 'TIMEOUT') => '连不上模型服务商，请检查服务商配置后重试',
            default => '索引没有成功，请稍后重试',
        };
    }
}
