<?php

declare(strict_types=1);

namespace app\process;

use app\Application\Audio\Speech\SpeechUsageRecovery;
use support\Log;
use Workerman\Timer;

final class SpeechUsageRecoveryWorker
{
    public function onWorkerStart(): void
    {
        $recovery = new SpeechUsageRecovery();
        Timer::add(10.0, function () use ($recovery): void {
            try {
                $result = $recovery->run();
                if ($result['failed'] > 0) {
                    Log::error('SandAI speech usage recovery needs attention', ['failed_count' => $result['failed']]);
                }
            } catch (\Throwable $exception) {
                Log::error('SandAI speech usage recovery failed', ['exception_type' => $exception::class]);
            }
        });
    }
}
