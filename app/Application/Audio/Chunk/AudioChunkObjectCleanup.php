<?php

declare(strict_types=1);

namespace app\Application\Audio\Chunk;

use think\facade\Db;

/** Bounded recovery entrypoint; deletion failures keep ownership and reserved capacity durable. */
final readonly class AudioChunkObjectCleanup
{
    public function __construct(private LocalPrivateAudioChunkObjects $objects,
        private PostgresAudioChunkPreparationLedger $ledger = new PostgresAudioChunkPreparationLedger()) {}

    /** Caller owns scheduling in an isolated Worker. No task state is changed here. */
    public function runBatch(int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $rootFingerprint = $this->objects->rootFingerprint();
        $this->ledger->assertStore($this->objects->storeCode, $rootFingerprint);
        $candidates = Db::connect('pgsql')->query(
            "SELECT p.environment_id,p.task_run_id FROM sand_ai_audio_chunk_preparation p JOIN sand_ai_task_run t ON t.id=p.task_run_id AND t.environment_id=p.environment_id WHERE p.store_code=? AND p.store_root_sha256=? AND p.state IN ('preparing','sealed') AND (t.content_purged_time IS NOT NULL OR t.delete_time IS NOT NULL OR t.state='canceled') ORDER BY p.id LIMIT " . $limit,
            [$this->objects->storeCode, $rootFingerprint],
        );
        foreach ($candidates as $candidate) $this->ledger->requestCleanup((int) $candidate['environment_id'], (int) $candidate['task_run_id']);
        $rows = $this->ledger->claimCleanup($this->objects->storeCode, $rootFingerprint, $limit);
        $deleted = 0; $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->objects->locked((string) $row['chunk_ref'], function () use ($row): void {
                    $this->ledger->assertStore($this->objects->storeCode, $this->objects->rootFingerprint());
                    $current = $this->ledger->object((int) $row['audio_chunk_preparation_id'], (int) $row['sequence_no']);
                    if ($current['state'] === 'deleted') return;
                    $this->objects->delete((string) $current['chunk_ref']);
                    $this->ledger->assertStore($this->objects->storeCode, $this->objects->rootFingerprint());
                    $this->ledger->deleted((int) $row['audio_chunk_preparation_id'], (int) $row['id']);
                });
                $deleted++;
            } catch (\Throwable) { $failed++; }
        }
        return ['scanned' => count($rows), 'deleted' => $deleted, 'retry_pending' => $failed];
    }
}
