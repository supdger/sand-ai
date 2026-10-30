<?php

declare(strict_types=1);

namespace app\Application\Audio\Speech;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\ProviderSendBoundary;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Storage\ObjectKey;
use app\Infrastructure\Storage\StorageDriverFactory;
use think\facade\Db;

/**
 * Durable speech dispatch boundary. A dispatched call is never sent again
 * merely because its client disconnected or its provider result is unknown.
 */
final class SpeechRunLedger
{
    /** @return array<string,mixed> */
    public function reserve(
        UsageAttribution $attribution,
        string $requestId,
        SpeechSynthesisRequest $request,
        SpeechExecutionTarget $target,
    ): array {
        if ($attribution->sourceKind !== 'capability'
            || $attribution->serviceAction !== 'sand_ai.audio.speech'
            || $attribution->capabilityCode !== 'audio_speech') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_VALIDATION_ERROR', 'Speech attribution is invalid');
        }
        $attribution->canonicalTrustedIdentity();
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        $requestPayload = [
            'text' => $request->text, 'model' => $request->model,
            'voice' => $request->voice, 'target' => $target->snapshot(),
        ];
        // Preserve the exact historical MP3 fingerprint. Other formats cannot
        // resume an original prepared/unknown request under the same key.
        if ($request->format !== 'mp3') $requestPayload['response_format'] = $request->format;
        $fingerprint = hash('sha256', json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return Db::connect('pgsql')->transaction(function () use ($attribution, $requestId, $request, $target, $scope, $fingerprint): array {
            Db::connect('pgsql')->execute(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['sand-ai-speech:' . $requestId],
            );
            $existing = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_speech_run WHERE request_id=? FOR UPDATE',
                [$requestId],
            );
            if ($existing !== []) {
                $row = $existing[0];
                if (!hash_equals((string) $row['owner_scope'], $scope)
                    || (int) $row['environment_id'] !== $attribution->environmentId) {
                    throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
                }
                if (!hash_equals((string) $row['request_fingerprint'], $fingerprint)) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Speech request key belongs to another input');
                }
                return $row;
            }
            $publicId = bin2hex(random_bytes(16));
            $storage = StorageDriverFactory::create();
            Db::connect('pgsql')->execute(
                'INSERT INTO sand_ai_speech_run
                 (public_id,environment_id,owner_scope,identity_snapshot,request_id,request_fingerprint,
                  text_sha256,input_characters,voice,provider_id,model_id,deployment_id,profile_id,
                  profile_revision,route_id,credential_digest,storage_driver,object_key)
                 VALUES (?,?,?,CAST(? AS jsonb),?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $publicId, $attribution->environmentId, $scope,
                    json_encode($attribution->toPersisted(), JSON_THROW_ON_ERROR),
                    $requestId, $fingerprint, hash('sha256', $request->text),
                    mb_strlen($request->text, 'UTF-8'), $request->voice,
                    $target->providerId, $target->modelId, $target->deploymentId,
                    $target->profileId, $target->profileRevision, $target->routeId,
                    $target->credentialDigest, $storage->code(), ObjectKey::generate($request->format),
                ],
            );
            $created = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_speech_run WHERE public_id=?',
                [$publicId],
            );
            if (count($created) !== 1) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_UNAVAILABLE', 'Speech run could not be reserved');
            }
            return $created[0];
        });
    }

    /** @return array{id:int,public_id:string,state:string}|null */
    public function findExisting(string $requestId, UsageAttribution $attribution, string $text, string $voice): ?array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id,public_id,state,environment_id,owner_scope,text_sha256,input_characters,voice
             FROM sand_ai_speech_run WHERE request_id=?', [$requestId],
        );
        if ($rows === []) {
            return null;
        }
        $run = $rows[0];
        if ((int) $run['environment_id'] !== $attribution->environmentId
            || !hash_equals((string) $run['owner_scope'], RequestIdempotencyKey::trustedScopeForAttribution($attribution))) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
        }
        if (!hash_equals((string) $run['text_sha256'], hash('sha256', $text))
            || (int) $run['input_characters'] !== mb_strlen($text, 'UTF-8')
            || (string) $run['voice'] !== $voice) {
            throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Speech request key belongs to another input');
        }
        return ['id' => (int) $run['id'], 'public_id' => (string) $run['public_id'],
            'state' => (string) $run['state']];
    }

    /** @return array{id:int,public_id:string} */
    public function ownedByRequestId(string $requestId, UsageAttribution $attribution): array
    {
        $rows = Db::connect('pgsql')->query(
            'SELECT id,public_id,environment_id,owner_scope
             FROM sand_ai_speech_run WHERE request_id=?', [$requestId],
        );
        if (count($rows) !== 1 || (int) $rows[0]['environment_id'] !== $attribution->environmentId
            || !hash_equals((string) $rows[0]['owner_scope'], RequestIdempotencyKey::trustedScopeForAttribution($attribution))) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
        }
        return ['id' => (int) $rows[0]['id'], 'public_id' => (string) $rows[0]['public_id']];
    }

    public function markDispatched(int $runId, string $ownerScope, SpeechCallStartPrice $price): void
    {
        $changed = Db::connect('pgsql')->execute(
            "UPDATE sand_ai_speech_run SET state='dispatching',
             dispatch_started_at=CURRENT_TIMESTAMP(0),called_at=?,pricing_state=?,
             price_revision_id=?,price_snapshot=CAST(? AS jsonb),update_time=CURRENT_TIMESTAMP(0)
             WHERE id=? AND owner_scope=? AND state='prepared'",
            [$price->calledAt, $price->revisionId === null ? 'unpriced' : 'priced',
                $price->revisionId, json_encode($price->snapshot(), JSON_THROW_ON_ERROR), $runId, $ownerScope],
        );
        if ($changed !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech request was already dispatched', true);
        }
    }

    public function markFailedBeforeDispatch(int $runId, string $ownerScope, string $errorCode): void
    {
        if (preg_match('/\A[A-Z][A-Z0-9_]{2,95}\z/D', $errorCode) !== 1) {
            $errorCode = 'SAND_AI_AUDIO_SPEECH_UNAVAILABLE';
        }
        Db::connect('pgsql')->execute(
            "UPDATE sand_ai_speech_run SET state='failed',error_code=?,
             complete_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
             WHERE id=? AND owner_scope=? AND state='prepared'",
            [$errorCode, $runId, $ownerScope],
        );
    }

    /** Persist private audio before usage finalization; never return the storage key. */
    public function saveAudio(int $runId, string $ownerScope, SpeechSynthesisResult $result): void
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT storage_driver,object_key,state,input_characters FROM sand_ai_speech_run
             WHERE id=? AND owner_scope=?",
            [$runId, $ownerScope],
        );
        if (count($rows) !== 1 || $rows[0]['state'] !== 'dispatching') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech result cannot be written', true);
        }
        if ((int) $rows[0]['input_characters'] !== $result->inputCharacters
            || pathinfo((string) $rows[0]['object_key'], PATHINFO_EXTENSION) !== $result->format
            || strlen($result->audio) < 32 || strlen($result->audio) > 8388608) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_PROVIDER_INVALID', 'Speech result does not match the reserved input');
        }
        $driver = StorageDriverFactory::create((string) $rows[0]['storage_driver']);
        $driver->upload((string) $rows[0]['object_key'], $result->audio, SpeechAudioFormat::mime($result->format));
        $changed = Db::connect('pgsql')->execute(
            "UPDATE sand_ai_speech_run SET state='awaiting_usage',audio_sha256=?,audio_bytes=?,
             update_time=CURRENT_TIMESTAMP(0)
             WHERE id=? AND owner_scope=? AND state='dispatching'",
            [hash('sha256', $result->audio), strlen($result->audio), $runId, $ownerScope],
        );
        if ($changed !== 1) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech result needs recovery', true);
        }
    }

    /** Seal a proven pre-send failure and release all budgets in one transaction. */
    public function markNotDispatched(int $runId, string $ownerScope, string $errorCode, ProviderSendBoundary $boundary): void
    {
        if ($boundary->started()) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Sent speech cannot release its budget');
        }
        if (preg_match('/\A[A-Z][A-Z0-9_]{2,95}\z/D', $errorCode) !== 1) {
            $errorCode = 'SAND_AI_AUDIO_SPEECH_UNAVAILABLE';
        }
        Db::connect('pgsql')->transaction(function () use ($runId, $ownerScope, $errorCode): void {
            $budget = new SpeechBudgetGuard();
            $budget->lockForRun($runId);
            $rows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_speech_run WHERE id=? AND owner_scope=? FOR UPDATE',
                [$runId, $ownerScope],
            );
            if (count($rows) !== 1 || $rows[0]['state'] !== 'dispatching'
                || $rows[0]['audio_sha256'] !== null || $rows[0]['audio_bytes'] !== null) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech outcome changed before unsent closure');
            }
            $events = Db::connect('pgsql')->query(
                'SELECT id FROM sand_ai_usage_event WHERE speech_run_id=?', [$runId],
            );
            if ($events !== []) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_USAGE_INVALID', 'Metered speech cannot release its budget');
            }
            $budget->release($rows[0]);
            // Preserve the frozen claim time and price facts. The existing
            // schema binds them together; failure and release prove no send.
            $changed = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_speech_run SET state='failed',error_code=?,
                 complete_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND owner_scope=? AND state='dispatching'",
                [$errorCode, $runId, $ownerScope],
            );
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech changed before unsent closure');
            }
        });
    }

    public function markAmbiguous(int $runId, string $ownerScope, string $errorCode): void
    {
        if (preg_match('/\A[A-Z][A-Z0-9_]{2,95}\z/D', $errorCode) !== 1) {
            $errorCode = 'SAND_AI_AUDIO_SPEECH_OUTCOME_UNKNOWN';
        }
        Db::connect('pgsql')->transaction(function () use ($runId, $ownerScope, $errorCode): void {
            (new SpeechBudgetGuard())->lockForRun($runId);
            $rows = Db::connect('pgsql')->query(
                'SELECT * FROM sand_ai_speech_run WHERE id=? AND owner_scope=? FOR UPDATE',
                [$runId, $ownerScope],
            );
            if (count($rows) !== 1 || $rows[0]['state'] !== 'dispatching') {
                return;
            }
            (new SpeechBudgetGuard())->hold($rows[0]);
            $changed = Db::connect('pgsql')->execute(
                "UPDATE sand_ai_speech_run SET state='ambiguous',error_code=?,
                 complete_time=CURRENT_TIMESTAMP(0),update_time=CURRENT_TIMESTAMP(0)
                 WHERE id=? AND owner_scope=? AND state='dispatching'",
                [$errorCode, $runId, $ownerScope],
            );
            if ($changed !== 1) {
                throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech outcome changed', true);
            }
        });
    }

    public function ownedId(string $publicId, UsageAttribution $attribution): int
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $publicId) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
        }
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($attribution);
        $rows = Db::connect('pgsql')->query(
            'SELECT id FROM sand_ai_speech_run WHERE public_id=? AND environment_id=? AND owner_scope=?',
            [$publicId, $attribution->environmentId, $scope],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
        }
        return (int) $rows[0]['id'];
    }

    /** @return array<string,mixed> */
    public function read(string $publicId, UsageAttribution $attribution): array
    {
        $runId = $this->ownedId($publicId, $attribution);
        $rows = Db::connect('pgsql')->query(
            'SELECT r.public_id,r.state,r.input_characters,r.voice,r.audio_bytes,r.error_code,
                    r.create_time,r.complete_time,u.pricing_state,u.price_revision_id,
                    u.metered_amount,u.currency
             FROM sand_ai_speech_run r
             LEFT JOIN sand_ai_usage_event u ON u.speech_run_id=r.id
             WHERE r.id=?', [$runId],
        );
        if (count($rows) !== 1) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Speech run not found');
        }
        $row = $rows[0];
        return [
            'public_id' => (string) $row['public_id'], 'state' => (string) $row['state'],
            'input_characters' => (int) $row['input_characters'], 'voice' => (string) $row['voice'],
            'audio_bytes' => $row['audio_bytes'] === null ? null : (int) $row['audio_bytes'],
            'error_code' => $row['error_code'], 'create_time' => $row['create_time'],
            'complete_time' => $row['complete_time'],
            'cost' => $row['pricing_state'] === null ? null : [
                'pricing_state' => (string) $row['pricing_state'],
                'price_revision_id' => $row['price_revision_id'] === null ? null : (int) $row['price_revision_id'],
                'metered_amount' => $row['metered_amount'] === null ? null : (string) $row['metered_amount'],
                'currency' => $row['currency'],
            ],
        ];
    }

    public function privateAudio(string $publicId, UsageAttribution $attribution): string
    {
        $runId = $this->ownedId($publicId, $attribution);
        $rows = Db::connect('pgsql')->query(
            'SELECT state,storage_driver,object_key,audio_sha256,audio_bytes
             FROM sand_ai_speech_run WHERE id=?', [$runId],
        );
        if (count($rows) !== 1 || $rows[0]['state'] !== 'succeeded') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech audio is not ready', true);
        }
        try {
            $audio = StorageDriverFactory::create((string) $rows[0]['storage_driver'])
                ->read((string) $rows[0]['object_key']);
        } catch (\Throwable) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_UNAVAILABLE', 'Speech audio is unavailable', true);
        }
        if (strlen($audio) !== (int) $rows[0]['audio_bytes']
            || !hash_equals((string) $rows[0]['audio_sha256'], hash('sha256', $audio))) {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_UNAVAILABLE', 'Speech audio integrity check failed', true);
        }
        return $audio;
    }

    /** Format is derived only from the immutable, server-generated private object key. */
    public function privateAudioFormat(string $publicId, UsageAttribution $attribution): string
    {
        $runId = $this->ownedId($publicId, $attribution);
        $rows = Db::connect('pgsql')->query(
            'SELECT state,object_key FROM sand_ai_speech_run WHERE id=?', [$runId],
        );
        if (count($rows) !== 1 || $rows[0]['state'] !== 'succeeded') {
            throw new ApiProblem('SAND_AI_AUDIO_SPEECH_IN_PROGRESS', 'Speech audio is not ready', true);
        }
        return SpeechAudioFormat::validate(pathinfo((string) $rows[0]['object_key'], PATHINFO_EXTENSION));
    }
}
