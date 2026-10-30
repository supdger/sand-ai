<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Audio\Speech\SpeechRunLedger;
use app\Application\Audio\Speech\SpeechAudioFormat;
use app\Application\Audio\Speech\SpeechRuntimeResolver;
use app\Application\Audio\Speech\SpeechSynthesisService;
use app\Application\Audio\Speech\SpeechUsageFinalizer;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\RequestAuditId;
use app\Identity\Infrastructure\SpeechRunAccessGuard;
use support\Request;
use support\Response;

/** SandIAM-authorized public speech API; provider routing remains administrator-owned. */
final class SpeechController
{
    public function submit(Request $request): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $auth = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.speech');
            $body = $request->post();
            if (!is_array($body) || array_diff(array_keys($body), ['text', 'voice']) !== []
                || !is_string($body['text'] ?? null) || !is_string($body['voice'] ?? null)) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Text and voice are required');
            }
            $key = RequestIdempotencyKey::required($request, 'audio_speech', $auth['context']);
            $attribution = UsageAttribution::fromIdentity(
                $auth['context'], 'capability', 'sand_ai.audio.speech', 'audio_speech',
            );
            $runs = new SpeechRunLedger();
            $existing = $runs->findExisting($key, $attribution, $body['text'], $body['voice']);
            if ($existing !== null && $existing['state'] !== 'prepared') {
                if ($existing['state'] === 'awaiting_usage') {
                    (new SpeechUsageFinalizer())->finalize($existing['id'], $attribution);
                }
                (new SpeechRunAccessGuard())->authorize(
                    $request, $auth['context'], $existing['id'], 'sand_ai.audio.speech', $trace,
                );
                return ApiResponder::success($runs->read($existing['public_id'], $attribution), $trace);
            }
            $resolver = new SpeechRuntimeResolver();
            $runtime = $resolver->resolve((int) $auth['environment_id'], $body['text'], $body['voice']);
            $guard = new SpeechRunAccessGuard();
            $decision = null;
            $authorize = function (int $runId) use ($request, $auth, $trace, $guard, &$decision): void {
                if ($decision === null) {
                    $decision = $guard->authorize($request, $auth['context'], $runId, 'sand_ai.audio.speech', $trace);
                } else {
                    $guard->revalidate($request, $auth['context'], $runId, 'sand_ai.audio.speech', $trace, $decision);
                }
            };
            return ApiResponder::success((new SpeechSynthesisService(
                $runs, $resolver, new SpeechUsageFinalizer(),
            ))->synthesize($attribution, $key, $runtime, $authorize), $trace);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function read(Request $request, string $runId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            [$runs, $attribution, $revalidate] = $this->authorizeRead($request, $runId, $trace);
            $result = $runs->read($runId, $attribution);
            $revalidate();
            return ApiResponder::success($result, $trace);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function readByRequestKey(Request $request): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $auth = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.speech.read');
            $attribution = UsageAttribution::fromIdentity(
                $auth['context'], 'capability', 'sand_ai.audio.speech', 'audio_speech',
            );
            $key = RequestIdempotencyKey::required($request, 'audio_speech', $auth['context']);
            $runs = new SpeechRunLedger();
            $run = $runs->ownedByRequestId($key, $attribution);
            $guard = new SpeechRunAccessGuard();
            $decision = $guard->authorize(
                $request, $auth['context'], $run['id'], 'sand_ai.audio.speech.read', $trace,
            );
            $result = $runs->read($run['public_id'], $attribution);
            $guard->revalidate($request, $auth['context'], $run['id'], 'sand_ai.audio.speech.read', $trace, $decision);
            return ApiResponder::success($result, $trace);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function audio(Request $request, string $runId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            [$runs, $attribution, $revalidate] = $this->authorizeRead($request, $runId, $trace);
            $format = $runs->privateAudioFormat($runId, $attribution);
            $audio = $runs->privateAudio($runId, $attribution);
            $revalidate();
            return new Response(200, [
                'Content-Type' => SpeechAudioFormat::mime($format),
                'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'attachment; filename="speech.' . $format . '"',
                'X-Content-Type-Options' => 'nosniff',
            ], $audio);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    /** @return array{SpeechRunLedger,UsageAttribution,\Closure():void} */
    private function authorizeRead(Request $request, string $publicId, string $trace): array
    {
        $auth = (new SandIamContextAuthenticator())->authenticate($request, 'sand_ai.audio.speech.read');
        $attribution = UsageAttribution::fromIdentity(
            $auth['context'], 'capability', 'sand_ai.audio.speech', 'audio_speech',
        );
        $runs = new SpeechRunLedger();
        $runId = $runs->ownedId($publicId, $attribution);
        $guard = new SpeechRunAccessGuard();
        $decision = $guard->authorize(
            $request, $auth['context'], $runId, 'sand_ai.audio.speech.read', $trace,
        );
        return [$runs, $attribution, static function () use ($guard, $request, $auth, $runId, $trace, $decision): void {
            $guard->revalidate($request, $auth['context'], $runId, 'sand_ai.audio.speech.read', $trace, $decision);
        }];
    }
}
