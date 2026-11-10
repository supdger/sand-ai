<?php

declare(strict_types=1);

namespace app\process;

use app\Application\Knowledge\IndexTaskGateway;
use support\Log;
use Workerman\Timer;

/** Drains the knowledge embedding index queue; each tick advances one index task. */
final class KnowledgeIndexWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(2.0, function (): void {
            try {
                (new IndexTaskGateway())->runBatch();
            } catch (\Throwable $exception) {
                Log::error('SandAI knowledge index queue sweep failed', [
                    'exception_type' => $exception::class,
                ]);
            }
        });
    }
}
