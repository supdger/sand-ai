<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\facade\Db;
use Throwable;

/** Optional immutable acceleration evidence; absent evidence always needs the full source. */
final class AudioTranscriptSealLedger
{
    public const VERSION = 'audio_transcript_seal@1';

    /**
     * An old installation without any seal schema keeps the full validation path.
     * A partially installed schema is an error, not a reason to ignore evidence.
     * Probe catalogs without querying an absent relation; never cache this result.
     */
    public static function available(): bool
    {
        $rows = Db::connect('pgsql')->query(
            "SELECT to_regclass('sand_ai_audio_transcript_seal') IS NOT NULL AS relation_present,
                EXISTS (SELECT 1 FROM pg_attribute
                     WHERE attrelid=to_regclass('sand_ai_task_run')
                       AND attname='audio_seal_eligible' AND NOT attisdropped) AS column_present,
                (SELECT count(*) FROM pg_trigger
                     WHERE (tgrelid=to_regclass('sand_ai_audio_transcript_seal')
                         AND tgname IN ('sand_ai_audio_transcript_seal_insert','sand_ai_audio_transcript_seal_immutable')
                         OR tgrelid=to_regclass('sand_ai_task_run')
                         AND tgname IN ('sand_ai_audio_task_seal_guard','sand_ai_audio_task_seal_purge'))
                       AND tgenabled IN ('O','A')) AS new_trigger_count,
                EXISTS (SELECT 1 FROM pg_trigger
                    WHERE tgrelid=to_regclass('sand_ai_audio_segment_review')
                      AND tgname='sand_ai_audio_segment_review_immutable'
                      AND tgenabled IN ('O','A')) AS review_trigger_present",
        );
        if (count($rows) !== 1) self::unavailable();
        $relation = self::trueValue($rows[0]['relation_present'] ?? null);
        $column = self::trueValue($rows[0]['column_present'] ?? null);
        $newTriggers = (int) ($rows[0]['new_trigger_count'] ?? -1);
        if (!$relation && !$column && $newTriggers === 0) return false;
        if ($relation && $column && $newTriggers === 4
            && self::trueValue($rows[0]['review_trigger_present'] ?? null)) return true;
        self::unavailable();
    }

    public static function assertReady(): void
    {
        if (!self::available()) self::unavailable();
    }

    private static function trueValue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }

    private static function unavailable(): never
    {
        throw new ApiProblem(
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_UNAVAILABLE',
            'Audio transcript storage is not ready',
        );
    }

    /** Called inside the existing result/usage transaction, after the audio result is persisted. */
    public function sealBase(int $audioTaskId): void
    {
        if (!self::available()) return;
        $this->attempt(function () use ($audioTaskId): void {
            $task = TaskRun::where('id', $audioTaskId)->find();
            if ($task === null) self::invalid();
            if (!self::eligible($task)) return; // Includes pre-migration queued and completed tasks.
            $input = self::array($task->input);
            $raw = self::array($task->result);
            if ($input === null || $raw === null || !is_array($input['execution_driver'] ?? null)) self::invalid();
            // SQL98 seals a public projection equal to task.result. Chunk aggregation retains
            // a private proof marker and therefore always uses the full immutable evidence path.
            if (\app\Application\Audio\Chunk\PostgresAudioChunkAggregation::marked($raw)) return;
            $driver = $input['execution_driver'];
            $projection = (new \app\Application\Task\AudioTaskResultPresenter())->present(
                $raw, (string) ($driver['code'] ?? ''), (string) ($driver['version'] ?? ''),
            );
            $this->insert($audioTaskId, 0, $projection);
        });
    }

    /** @param array<string,mixed> $baseTranscript Called after the review insert in its existing transaction. */
    public function sealReview(TaskRun $task, int $revision, array $baseTranscript, string $ownerScope): void
    {
        if (\app\Application\Audio\Chunk\PostgresAudioChunkAggregation::marked($task->result)) return;
        if (self::available() && self::eligible($task)) {
            $this->attempt(function () use ($task, $revision, $baseTranscript, $ownerScope): void {
                $projection = (new AudioSegmentReviewLedger())->snapshot(
                    (int) $task->id, $baseTranscript, $ownerScope, $revision,
                );
                $this->insert((int) $task->id, $revision, $projection);
            });
        }
    }

    /**
     * A known projection/chain failure only discards this optional acceleration row.
     * The completed result, actual usage, budget settlement and receipt remain in the
     * original transaction. Unexpected database errors still abort that transaction.
     *
     */
    private function attempt(\Closure $seal): void
    {
        $db = Db::connect('pgsql');
        try {
            $db->transaction($seal);
        } catch (Throwable $failure) {
            if (self::knownOptionalFailure($failure)) return;
            throw $failure;
        }
    }

    /** @param array<string,mixed> $projection */
    private function insert(int $audioTaskId, int $revision, array $projection): void
    {
        Db::connect('pgsql')->execute(
            'INSERT INTO sand_ai_audio_transcript_seal (audio_task_id,review_revision,projection_text) VALUES (?,?,?)',
            [$audioTaskId, $revision, self::serialize($projection)],
        );
    }

    /** @param array<string,mixed> $projection */
    public static function serialize(array $projection): string
    {
        return json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Full reads compare the exact normalized projection before freezing a seal.
     * Missing optional rows remain legacy/full reads; an existing wrong row rejects.
     *
     * @return array{id:int,version:string}|null
     */
    public function reference(TaskRun $task, int $revision, string $digest, int $total, int $eligible, string $ownerScope): ?array
    {
        if (!self::available()) return null;
        $rows = Db::connect('pgsql')->query(
            'SELECT id,normalization_version,transcript_sha256,total_segments,eligible_segments,
                    task_version,environment_id,file_id,file_ref,file_sha256,owner_scope,
                    data_egress_policy,content_purged_time
             FROM sand_ai_audio_transcript_seal WHERE audio_task_id=? AND review_revision=?',
            [(int) $task->id, $revision],
        );
        if ($rows === []) return null;
        $input = self::array($task->input);
        $request = is_array($input) ? ($input['request'] ?? null) : null;
        if (count($rows) !== 1 || !self::eligible($task)
            || !is_array($input) || !is_array($request)
            || $rows[0]['normalization_version'] !== self::VERSION
            || !hash_equals($digest, (string) $rows[0]['transcript_sha256'])
            || (int) $rows[0]['total_segments'] !== $total || (int) $rows[0]['eligible_segments'] !== $eligible
            || (int) $rows[0]['task_version'] !== (int) $task->version
            || (int) $rows[0]['environment_id'] !== (int) $task->environment_id
            || (int) $rows[0]['file_id'] !== ($input['file_id'] ?? null)
            || $rows[0]['file_ref'] !== ($request['file_ref'] ?? null)
            || $rows[0]['file_sha256'] !== ($input['file_sha256'] ?? null)
            || $rows[0]['owner_scope'] !== $ownerScope
            || ($input['_trusted_identity_scope'] ?? null) !== $ownerScope
            || $rows[0]['data_egress_policy'] !== ($request['data_egress_policy'] ?? null)
            || $rows[0]['content_purged_time'] !== null) self::invalid();
        return ['id' => (int) $rows[0]['id'], 'version' => self::VERSION];
    }

    /** @return array{id:int,version:string}|null */
    public static function normalizeReference(mixed $value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || count($value) !== 2 || !is_int($value['id'] ?? null)
            || $value['id'] < 1 || ($value['version'] ?? null) !== self::VERSION) self::invalid();
        return ['id' => $value['id'], 'version' => $value['version']];
    }

    public static function eligible(TaskRun $task): bool
    {
        return in_array($task->getAttr('audio_seal_eligible'), [true, 1, '1', 't'], true);
    }

    /** @return array<string,mixed>|null */
    private static function array(mixed $value): ?array
    {
        if (is_object($value)) $value = (array) $value;
        return is_array($value) ? $value : null;
    }

    private static function knownOptionalFailure(Throwable $failure): bool
    {
        if ($failure instanceof ApiProblem && in_array($failure->errorCode, [
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID', 'SAND_AI_AUDIO_TRANSCRIPTION_RESULT_INVALID',
            'SAND_AI_AUDIO_REVIEW_EVIDENCE_INVALID',
        ], true)) return true;
        // SQLSTATE alone is insufficient: unrelated raised exceptions must propagate.
        $known = [
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_PROJECTION_INVALID',
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_CHAIN_INVALID',
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_MISSING',
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_LATE',
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_REVIEW_INVALID',
            'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID',
        ];
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if (!str_contains($cause->getMessage(), 'SQLSTATE[P0001]')) continue;
            foreach ($known as $code) {
                if (str_contains($cause->getMessage(), $code)) return true;
            }
        }
        return false;
    }

    public static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID', 'Audio transcript evidence is unavailable');
    }
}
