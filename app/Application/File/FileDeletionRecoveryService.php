<?php

declare(strict_types=1);

namespace app\Application\File;

use support\Log;
use think\facade\Db;

/** Finishes authorized deletions left between the database mark and storage cleanup. */
final class FileDeletionRecoveryService
{
    private int $lastFileId = 0;

    public function run(): void
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT id FROM sand_ai_file
             WHERE state='deleting' AND status=1 AND delete_time IS NULL
               AND update_time < CURRENT_TIMESTAMP - INTERVAL '2 minutes'
               AND id > ?
             ORDER BY id LIMIT 1",
            [$this->lastFileId],
        );
        if ($rows === []) {
            $this->lastFileId = 0;
            $rows = Db::connect('pgsql')->query(
                "SELECT id FROM sand_ai_file
                 WHERE state='deleting' AND status=1 AND delete_time IS NULL
                   AND update_time < CURRENT_TIMESTAMP - INTERVAL '2 minutes'
                 ORDER BY id LIMIT 1",
            );
        }
        if ($rows === []) return;
        $this->lastFileId = (int) $rows[0]['id'];
        try {
            (new FileGateway())->resumeDeleting($this->lastFileId);
        } catch (\Throwable $exception) {
            Log::warning('SandAI file deletion recovery failed', [
                'file_id' => $this->lastFileId,
                'exception_type' => $exception::class,
            ]);
        }
    }
}
