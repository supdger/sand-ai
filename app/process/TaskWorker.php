<?php

declare(strict_types=1);

namespace app\process;

use app\Application\Task\TaskGateway;
use app\Application\Task\TaskWorkerIdentity;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

final class TaskWorker
{
    private string $workerId;
    private ?int $pollTimerId = null;
    private bool $running = false;
    private bool $draining = false;

    public function __construct()
    {
        $this->workerId = TaskWorkerIdentity::forProcess(gethostname(), getmypid());
    }

    public function onWorkerStart(): void
    {
        $interval = max(0.05, (int) config('sand_ai_task.poll_interval_ms', 500) / 1000);
        $this->pollTimerId = Timer::add($interval, function (): void {
            $this->tick();
        });
    }

    public function onWorkerReload(): void
    {
        if ($this->draining) return;
        $this->draining = true;
        if ($this->pollTimerId !== null) {
            Timer::del($this->pollTimerId);
            $this->pollTimerId = null;
        }
        if (!$this->running) Worker::stopAll();
    }

    private function tick(): void
    {
        // Timer callbacks may overlap when an I/O coroutine yields. Only one tick owns this worker.
        if ($this->draining || $this->running) return;
        $this->running = true;
        try {
            $limit = max(1, min(20, (int) config('sand_ai_task.worker_batch_size', 1)));
            for ($index = 0; $index < $limit; $index++) {
                if ($this->draining) return;
                try {
                    $gateway = new TaskGateway();
                    if (!$gateway->runOne($this->workerId)) {
                        return;
                    }
                    // A long recording slice retains its fenced lease for the next timer callback.
                    if ($gateway->audioSliceYielded()) return;
                } catch (\Throwable $exception) {
                    // Never log raw driver messages, SQL, parameters or queued credentials.
                    $sqlState = null;
                    $guardCode = null;
                    $sqlMatch = [];
                    $guardMatch = [];
                    if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $exception->getMessage(), $sqlMatch) === 1) {
                        $sqlState = $sqlMatch[1];
                    }
                    if (preg_match('/\b(SAND_AI_[A-Z0-9_]{1,96})\b/', $exception->getMessage(), $guardMatch) === 1) {
                        $guardCode = $guardMatch[1];
                    }
                    if ($exception instanceof \app\Api\Support\ApiProblem) {
                        $guardCode = $exception->errorCode;
                    }
                    Log::error('SandAI task worker tick failed', [
                        'worker_id' => $this->workerId,
                        'exception_type' => $exception::class,
                        'exception_file' => basename($exception->getFile()),
                        'exception_line' => $exception->getLine(),
                        'sql_state' => $sqlState, 'guard_code' => $guardCode,
                    ]);
                    return;
                }
            }
        } finally {
            $this->running = false;
            // runOne has returned or its error has unwound; no further task is claimed by this process.
            if ($this->draining) Worker::stopAll();
        }
    }
}
