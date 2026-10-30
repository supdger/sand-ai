<?php

declare(strict_types=1);

namespace app\Api\Controller;

use app\Api\Support\ApiProblem;
use app\Api\Support\ApiResponder;
use app\Application\Audio\AudioSourceGateway;
use app\Application\Audio\AudioSegmentReviewGateway;
use app\Application\Audio\MeetingSummarySource;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Gateway\SandIamContextAuthenticator;
use app\Application\Usage\UsageAttribution;
use app\Identity\Infrastructure\HostSandIamInvocationAuthorizer;
use app\Identity\Infrastructure\RequestAuditId;
use support\Request;
use support\Response;

/** The application owns review of its transcript; SandAdmin remains configuration. */
final class AudioSegmentReviewController
{
    public function source(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $authorized = $this->authorize($request, $taskId, $trace);
            $source = (new AudioSourceGateway())->read(
                $authorized['environment_id'], $authorized['task_id'], $authorized['attribution'],
            );
            return new Response(200, [
                'Content-Type' => $source['content_type'],
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], $source['bytes']);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function read(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $authorized = $this->authorize($request, $taskId, $trace);
            return ApiResponder::success(
                (new AudioSegmentReviewGateway())->read($authorized['environment_id'], $authorized['task_id'], $authorized['attribution']),
                $trace,
            );
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function save(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $authorized = $this->authorize($request, $taskId, $trace);
            $input = $request->post();
            if (!is_array($input) || array_diff(array_keys($input), ['expected_revision', 'segment_index', 'text', 'speaker']) !== []
                || !is_int($input['expected_revision'] ?? null)
                || !is_int($input['segment_index'] ?? null)
                || !is_string($input['text'] ?? null)
                || (array_key_exists('speaker', $input)
                    && $input['speaker'] !== null && !is_string($input['speaker']))) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio review input is invalid');
            }
            $key = RequestIdempotencyKey::required($request, 'audio_review', $authorized['identity']);
            return ApiResponder::success((new AudioSegmentReviewGateway())->save(
                $authorized['environment_id'], $authorized['task_id'], $authorized['attribution'],
                $input['expected_revision'], $input['segment_index'], $input['text'], $key,
                array_key_exists('speaker', $input), $input['speaker'] ?? null,
            ), $trace);
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace);
        }
    }

    public function readByRequestKey(Request $request, string $taskId): Response
    {
        $trace = RequestAuditId::ensure($request);
        try {
            $query = $request->get();
            $callerKey = $query['request_key'] ?? null;
            if (array_keys($query) !== ['request_key'] || $request->rawBody() !== ''
                || !is_string($callerKey)) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio review lookup requires only request_key and no body');
            }
            $authorized = $this->authorize($request, $taskId, $trace);
            $requestId = RequestIdempotencyKey::fromCallerKey($callerKey, 'audio_review', $authorized['identity']);
            $receipt = (new AudioSegmentReviewGateway())->readReceipt(
                $authorized['environment_id'], $authorized['task_id'], $authorized['attribution'], $requestId,
            );
            // A receipt never bypasses current audio/review and private-source
            // authorization, including a revocation while reading its ledger.
            $current = $this->authorize($request, $taskId, $trace);
            if ($current['environment_id'] !== $authorized['environment_id']
                || $current['task_id'] !== $authorized['task_id']
                || !hash_equals(
                    RequestIdempotencyKey::trustedScopeForAttribution($current['attribution']),
                    RequestIdempotencyKey::trustedScopeForAttribution($authorized['attribution']),
                )) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Audio review receipt not found');
            }
            return ApiResponder::success([...$receipt, 'request_key' => trim($callerKey)], $trace)
                ->header('Cache-Control', 'no-store');
        } catch (ApiProblem $problem) {
            return ApiResponder::error($problem, $trace)->header('Cache-Control', 'no-store');
        }
    }

    /** @return array{environment_id:int,task_id:int,attribution:UsageAttribution,identity:\app\Identity\Contract\IdentityContext} */
    private function authorize(Request $request, string $taskId, string $trace): array
    {
        $id = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'A valid audio task id is required');
        }
        $authenticator = new SandIamContextAuthenticator();
        $audio = $authenticator->authenticate($request, 'sand_ai.audio.transcription');
        $review = $authenticator->authenticate($request, 'sand_ai.audio.review');
        if ((int) $audio['environment_id'] !== (int) $review['environment_id']
            || !hash_equals(
                RequestIdempotencyKey::trustedScope($audio['context']),
                RequestIdempotencyKey::trustedScope($review['context']),
            )) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
        }
        $attribution = UsageAttribution::fromIdentity(
            $audio['context'], 'capability', 'sand_ai.audio.transcription', 'audio_transcription',
        );
        $source = (new MeetingSummarySource())->load((int) $audio['environment_id'], (int) $id, $attribution, null, 0, true);
        (new HostSandIamInvocationAuthorizer())->authorize(
            $request, $review['context'], 'sand_ai.audio.review', 'sand_ai.file',
            (string) $source['file_id'], 'audio-review:' . bin2hex(random_bytes(16)), $trace,
        );
        return [
            'environment_id' => (int) $audio['environment_id'],
            'task_id' => (int) $id,
            'attribution' => $attribution,
            'identity' => $review['context'],
        ];
    }
}
