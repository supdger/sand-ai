<?php

declare(strict_types=1);

namespace app\process;

use app\Application\Channel\WecomDeliveryGateway;
use support\Log;
use Workerman\Timer;

final class WecomDeliveryWorker
{
    public function onWorkerStart(): void
    {
        Timer::add(1.0, function (): void {
            try { (new WecomDeliveryGateway())->runOne(); }
            catch (\Throwable $exception) {
                Log::error('SandAI Wecom delivery tick failed', [
                    'exception_type' => $exception::class,
                    'exception_file' => basename($exception->getFile()),
                    'exception_line' => $exception->getLine(),
                ]);
            }
        });
    }
}
