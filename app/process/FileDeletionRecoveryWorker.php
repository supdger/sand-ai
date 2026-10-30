<?php

declare(strict_types=1);

namespace app\process;

use app\Application\File\FileDeletionRecoveryService;
use support\Log;
use Workerman\Timer;

final class FileDeletionRecoveryWorker
{
    public function onWorkerStart(): void
    {
        $recovery = new FileDeletionRecoveryService();
        Timer::add(30.0, function () use ($recovery): void {
            try {
                $recovery->run();
                $chunks = (new \app\Application\Audio\Chunk\AudioChunkTaskRunner())->cleanup();
                if ($chunks['retry_pending'] > 0) Log::warning('SandAI private audio cleanup will retry', ['retry_count' => $chunks['retry_pending']]);
            } catch (\Throwable $exception) {
                Log::error('SandAI file deletion recovery scan failed', [
                    'exception_type' => $exception::class,
                ]);
            }
        });
    }
}
