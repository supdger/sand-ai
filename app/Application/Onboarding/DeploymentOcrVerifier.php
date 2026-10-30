<?php

declare(strict_types=1);

namespace app\Application\Onboarding;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\File\LocalOcrDeploymentPolicy;
use app\Infrastructure\Parsing\LocalPaddleOcrDriver;
use app\Infrastructure\Parsing\ParseInput;

/** Actual local OCR verification uses the same private send driver and bounded response. */
final class DeploymentOcrVerifier
{
    public function verify(string $runtime, ProviderSendBoundary $boundary): void
    {
        LocalOcrDeploymentPolicy::assertTarget('local_ocr_runtime', 'image_ocr', $runtime);
        $sample = dirname(__DIR__, 3) . '/plugin/sand-ai/resources/verification/ocr-sample.png';
        if (!is_file($sample) || filesize($sample) !== 70095
            || !hash_equals('c004fade65203979c50f8c7ec346d967377a3a55fc0417f2447bf7218a4bd1a2', (string) hash_file('sha256', $sample))) {
            throw new ApiProblem('SAND_AI_CONFIGURATION_DRIFTED', 'OCR verification sample is unavailable');
        }
        $result = (new LocalPaddleOcrDriver())->parse(new ParseInput($sample, 'png', 'image/png',
            static function (string $event, ?int $status) use ($boundary): void {
                if ($event === 'dispatch_started' && $status === null) $boundary->markStarted();
            }));
        if ($result->blocks === []) throw new ApiProblem('SAND_AI_PARSE_EMPTY', 'Local OCR verification returned no recognized source blocks');
    }
}
