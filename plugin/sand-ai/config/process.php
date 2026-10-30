<?php

declare(strict_types=1);

use app\process\OutcomeReceiptCleanupWorker;
use app\process\TaskWorker;
use app\process\FeishuDeliveryWorker;
use app\process\WecomDeliveryWorker;
use app\process\SpeechUsageRecoveryWorker;
use app\process\FileDeletionRecoveryWorker;

// Process configuration is read before autoload.files. Reuse this plugin's
// existing namespace registrar; the later include_once will not register twice.
require_once __DIR__ . '/../app/functions.php';

// Keep this failure inside SandAI; the shared host and its other plugins load normally.
$foundation = \plugin\SandAi\app\support\HostFoundationCompatibility::status();
if (!$foundation['ready']) {
    error_log($foundation['message']);
    return [];
}

// The installed plugin owns process registration; handlers live in the shared application.
if (!class_exists(TaskWorker::class) || !class_exists(OutcomeReceiptCleanupWorker::class)
    || !class_exists(FeishuDeliveryWorker::class)
    || !class_exists(WecomDeliveryWorker::class)
    || !class_exists(SpeechUsageRecoveryWorker::class)
    || !class_exists(FileDeletionRecoveryWorker::class)) {
    return [];
}

return [
    // The task handler drains its current tick on reload before exiting; avoid master timeout kills.
    'sand_ai_task_worker' => [
        'handler' => TaskWorker::class,
        'count' => max(1, (int) (getenv('SAND_AI_TASK_WORKER_COUNT') ?: 1)),
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
    'sand_ai_outcome_receipt_cleanup' => [
        'handler' => OutcomeReceiptCleanupWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
    'sand_ai_speech_usage_recovery' => [
        'handler' => SpeechUsageRecoveryWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
    'sand_ai_feishu_delivery' => [
        'handler' => FeishuDeliveryWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
    'sand_ai_wecom_delivery' => [
        'handler' => WecomDeliveryWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
    'sand_ai_file_deletion_recovery' => [
        'handler' => FileDeletionRecoveryWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ],
];
