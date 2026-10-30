<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use think\facade\Db;

/**
 * Executes one reserved synthesis at most once. Public callers must resolve a
 * published target and authorize the frozen run before entering this service.
 */
final class SpeechSynthesisService
{
    public function __construct(
        private readonly SpeechRunLedger $runs,
        private readonly SpeechRuntimeResolver $runtimeResolver,
        private readonly SpeechUsageFinalizer $usage,
    ) {
    }

    /** @return array<string,mixed> */
    public function synthesize(
        UsageAttribution $attribution,
        string $requestId,
        SpeechResolvedRuntime $runtime,
        callable $authorizeRun,
    ): array {
        $run = $this->runs->reserve($attribution, $requestId, $runtime->request, $runtime->target);
        $runId = (int) $run['id'];
        $state = (string) $run['state'];
        if ($state === 'awaiting_usage') {
            $this->usage->finalize($runId, $attribution);
            $authorizeRun($runId);
            return $this->runs->read((string) $run['public_id'], $attribution);
        }
        $authorizeRun($runId);
        if ($state === 'succeeded') {
            return $this->runs->read((string) $run['public_id'], $attribution);
        }
        if ($state !== 'prepared') {
            return $this->runs->read((string) $run['public_id'], $attribution);
        }
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        $this->runtimeResolver->assertCurrent($attribution->environmentId, $runtime);
        $authorizeRun($runId);
        $calledAt = gmdate('Y-m-d H:i:s');
        $price = SpeechCallStartPrice::resolve($runtime->target->providerId, $runtime->target->modelId, $calledAt);
        Db::connect('pgsql')->transaction(function () use ($runId, $scope, $price, $authorizeRun): void {
            (new SpeechBudgetGuard())->reserve($runId, $price, $scope);
            // Budget locks may wait beyond the preceding authorization check.
            // Keep refusal atomic with the reservation and dispatch state.
            $authorizeRun($runId);
        });
        $sendBoundary = new ProviderSendBoundary();
        $reportsSendBoundary = $runtime->driver instanceof SpeechSendBoundaryDriver;
        try {
            $result = $reportsSendBoundary
                ? $runtime->driver->synthesizeWithBoundary($runtime->request, $sendBoundary)
                : $runtime->driver->synthesize($runtime->request);
            // A returned result proves execution even if a driver forgot its
            // marker. Storage/finalizer errors must never release actual usage.
            $sendBoundary->markStarted();
            $this->runs->saveAudio($runId, $scope, $result);
        } catch (\Throwable $failure) {
            $notDispatched = $reportsSendBoundary && !$sendBoundary->started();
            $errorCode = $failure instanceof ApiProblem ? $failure->errorCode
                : ($notDispatched ? 'SAND_AI_AUDIO_SPEECH_UNAVAILABLE' : 'SAND_AI_AUDIO_SPEECH_OUTCOME_UNKNOWN');
            if ($notDispatched) {
                $this->runs->markNotDispatched($runId, $scope, $errorCode, $sendBoundary);
                if ($failure instanceof ApiProblem) throw $failure;
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_UNAVAILABLE', 'Speech request could not be prepared');
            }
            $this->runs->markAmbiguous($runId, $scope, $errorCode);
            if ($failure instanceof ApiProblem) throw $failure;
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_OUTCOME_UNKNOWN', 'Speech result requires manual recovery', true);
        }
        $this->usage->finalize($runId, $attribution);
        $authorizeRun($runId);
        return $this->runs->read((string) $run['public_id'], $attribution);
    }
}
