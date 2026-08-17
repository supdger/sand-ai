<?php

declare(strict_types=1);

namespace plugin\SandAi\app\process;

use plugin\SandAi\app\domain\task\TaskGateway;
use support\Log;
use Workerman\Timer;

/** Plugin-local worker for SandAI asynchronous work. */
final class TaskWorker
{
    private string $workerId;

    public function __construct()
    {
        $this->workerId = sprintf('%s:%d', gethostname() ?: 'sand-ai-plugin', getmypid());
    }

    public function onWorkerStart(): void
    {
        $interval = max(0.05, (int) config('plugin.sand-ai.task.poll_interval_ms', 500) / 1000);
        Timer::add($interval, function (): void {
            $this->tick();
        });
    }

    private function tick(): void
    {
        $limit = max(1, min(20, (int) config('plugin.sand-ai.task.worker_batch_size', 1)));
        for ($index = 0; $index < $limit; $index++) {
            try {
                if (!(new TaskGateway())->runOne($this->workerId)) {
                    return;
                }
            } catch (\Throwable $exception) {
                Log::error('SandAI plugin task worker tick failed', [
                    'worker_id' => $this->workerId,
                    'exception_type' => $exception::class,
                    'exception_file' => basename($exception->getFile()),
                    'exception_line' => $exception->getLine(),
                ]);

                return;
            }
        }
    }
}
