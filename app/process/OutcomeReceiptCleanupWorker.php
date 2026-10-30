<?php

declare(strict_types=1);

namespace app\process;

use app\Application\Gateway\OutcomeReceiptCleanupService;
use app\Application\Gateway\OutcomeReceiptCleanupConfig;
use app\Application\Gateway\PostgresOutcomeReceiptCleanupStore;
use app\Application\Knowledge\KnowledgeQueryEmbeddingRecoveryService;
use support\Log;
use Workerman\Timer;

final class OutcomeReceiptCleanupWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(60.0, function (): void {
            try { (new \app\Application\Composition\CompositionRunGateway())->purgeExpired(); }
            catch (\Throwable $exception) { Log::error('SandAI composition private input cleanup failed', ['exception_type' => $exception::class]); }
        });
        Timer::add(15.0, function (): void {
            try {
                (new KnowledgeQueryEmbeddingRecoveryService())->run(20);
            } catch (\Throwable $exception) {
                Log::error('SandAI knowledge query receipt recovery failed', [
                    'exception_type' => $exception::class,
                ]);
            }
        });
        try {
            $config = config('sand_ai_outcome_receipt');
            if (!is_array($config)) {
                return;
            }
            $cleanup = OutcomeReceiptCleanupConfig::fromConfig($config);
        } catch (\Throwable $exception) {
            Log::error('SandAI outcome receipt cleanup did not start', ['exception_type' => $exception::class]);
            return;
        }
        if (!$cleanup->enabled) {
            return;
        }
        $interval = $cleanup->intervalSeconds;
        Timer::add($interval, function (): void {
            try {
                $config = config('sand_ai_outcome_receipt');
                if (!is_array($config)) {
                    return;
                }
                $cleanup = OutcomeReceiptCleanupConfig::fromConfig($config);
                $service = new OutcomeReceiptCleanupService(new PostgresOutcomeReceiptCleanupStore(), $cleanup, $this->owner());
                $service->run($cleanup->batchSize);
            } catch (\Throwable $exception) {
                Log::error('SandAI outcome receipt cleanup failed', ['exception_type' => $exception::class]);
            }
        });
    }

    private function owner(): string
    {
        return sprintf('%s:%d', preg_replace('/[^a-z0-9_-]/', '-', strtolower(gethostname() ?: 'sand-ai')) ?: 'sand-ai', getmypid());
    }
}
