<?php

declare(strict_types=1);

namespace app\Application\Workflow;

use app\Api\Support\ApiProblem;
use support\Log;

/** Records controlled failure identity, never messages, SQL text or output. */
final class MaterialOutputFailureDiagnostics
{
    public static function record(\Throwable $failure, int $taskId, ?int $stepId, ?int $runId, string $phase): void
    {
        $code = $failure instanceof ApiProblem || $failure instanceof MaterialReviewOutputRejected
            ? $failure->errorCode : null;
        if (!is_string($code) || preg_match('/\ASAND_AI_[A-Z0-9_]{1,96}\z/D', $code) !== 1) {
            $code = null;
        }
        $sqlState = null;
        $probe = $failure;
        for ($depth = 0; $depth < 8 && $probe !== null; ++$depth, $probe = $probe->getPrevious()) {
            if ($probe instanceof \PDOException) {
                $candidate = $probe->errorInfo[0] ?? $probe->getCode();
                if (is_string($candidate) && preg_match('/\A[A-Z0-9]{5}\z/D', $candidate) === 1) {
                    $sqlState = $candidate;
                }
                break;
            }
        }
        try {
            Log::warning('SandAI material output processing failed', [
                'task_id' => $taskId, 'step_id' => $stepId, 'workflow_run_id' => $runId,
                'phase' => $phase, 'exception_class' => $failure::class,
                'stable_domain_code' => $code, 'sqlstate' => $sqlState,
            ]);
        } catch (\Throwable) {
            // A logging failure cannot replace the original processing failure.
        }
    }
}
