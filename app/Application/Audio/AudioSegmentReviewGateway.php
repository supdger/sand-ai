<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Application\Gateway\RequestIdempotencyKey;
use app\Application\Usage\UsageAttribution;
use app\Infrastructure\Persistence\Model\AudioSegmentReview;
use app\Infrastructure\Persistence\Model\AuditLog;
use app\Infrastructure\Persistence\Model\AiFile;
use app\Infrastructure\Persistence\Model\TaskRun;
use think\db\ConnectionInterface;
use think\facade\Db;

/** Workload-attributed, append-only corrections to a private audio task. */
final class AudioSegmentReviewGateway
{
    /** Read an existing receipt without replaying its private input or creating a review.
     * @return array{audio_task_id:int,expected_revision:int,segment_index:int,revision:int}
     */
    public function readReceipt(int $environmentId, int $audioTaskId, UsageAttribution $identity, string $requestId): array
    {
        $source = (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, 0, true);
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($identity);
        $row = AudioSegmentReview::where('request_id', $requestId)->find();
        if ($row === null || (int) $row->audio_task_id !== $audioTaskId
            || (string) $row->owner_scope !== $scope
            || !hash_equals((string) $row->base_sha256, $source['sha256'])) {
            throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Audio review receipt not found');
        }
        $revision = (int) $row->revision;
        $expectedRevision = (int) $row->expected_revision;
        $index = (int) $row->segment_index;
        if ($expectedRevision < 0 || $revision !== $expectedRevision + 1
            || $revision > 10000 || $index < 0 || !isset($source['transcript']['segments'][$index])) {
            throw new ApiProblem('SAND_AI_AUDIO_REVIEW_EVIDENCE_INVALID', 'Audio review evidence is unavailable');
        }
        // The formal source reader verifies the immutable transcript, contiguous
        // review ledger and the persisted seal for this exact committed revision.
        $reviewed = (new MeetingSummarySource())->load(
            $environmentId, $audioTaskId, $identity, null, $revision, true,
        );
        $segment = $reviewed['transcript']['segments'][$index] ?? null;
        $speakerChanged = in_array($row->speaker_changed, [true, 1, '1', 't'], true);
        if (!is_array($segment) || ($segment['review_revision'] ?? null) !== $revision
            || ($segment['text'] ?? null) !== trim((string) $row->review_text)
            || ($speakerChanged && ($segment['speaker'] ?? null) !== $row->review_speaker)) {
            throw new ApiProblem('SAND_AI_AUDIO_REVIEW_EVIDENCE_INVALID', 'Audio review evidence is unavailable');
        }
        return [
            'audio_task_id' => $audioTaskId,
            'expected_revision' => $expectedRevision,
            'segment_index' => $index,
            'revision' => $revision,
        ];
    }

    /** @return array{revision:int,segments:list<array<string,mixed>>} */
    public function read(int $environmentId, int $audioTaskId, UsageAttribution $identity): array
    {
        $source = (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, 0, true);
        $revision = (new AudioSegmentReviewLedger())->latestRevision($audioTaskId);
        $reviewed = $revision === 0 ? $source['transcript']
            : (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, $revision, true)['transcript'];
        $segments = [];
        foreach ($reviewed['segments'] as $index => $segment) {
            $segments[] = [
                'segment_index' => $index,
                'start_ms' => $segment['start_ms'],
                'end_ms' => $segment['end_ms'],
                'speaker' => $segment['speaker'],
                'original_speaker' => $source['transcript']['segments'][$index]['speaker'],
                'confidence' => $source['transcript']['segments'][$index]['confidence'],
                'warnings' => $segment['warnings'],
                'original_text' => $source['transcript']['segments'][$index]['text'],
                'text' => $segment['text'],
                'review_revision' => $segment['review_revision'] ?? null,
                'source' => $segment['source'],
            ];
        }
        return ['revision' => $revision, 'segments' => $segments];
    }

    /** @return array{revision:int,segment_index:int,text:string,idempotent_replay:bool} */
    public function save(int $environmentId, int $audioTaskId, UsageAttribution $identity, int $expectedRevision, int $segmentIndex, string $text, string $requestId, bool $speakerChanged = false, ?string $speaker = null): array
    {
        if ($expectedRevision < 0 || $expectedRevision >= 10000 || $segmentIndex < 0
            || !mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > 10000
            || str_contains($text, "\0")
            || (!$speakerChanged && $speaker !== null)
            || ($speaker !== null && preg_match('/\Aspeaker_[1-9][0-9]{0,3}\z/D', $speaker) !== 1)) {
            throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio review input is invalid');
        }
        $text = trim($text);
        $scope = RequestIdempotencyKey::trustedScopeForAttribution($identity);
        $requestLockKey = (int) hexdec(substr(hash('sha256', $requestId), 0, 15));
        return Db::connect('pgsql')->transaction(function (ConnectionInterface $connection) use (
            $environmentId, $audioTaskId, $identity, $expectedRevision, $segmentIndex, $text, $requestId, $scope,
            $requestLockKey, $speakerChanged, $speaker,
        ): array {
            // Serialize the request key, then take the file before the task;
            // deletion uses the same row-lock order.
            $connection->query('SELECT pg_advisory_xact_lock(?)', [$requestLockKey]);
            $candidate = TaskRun::where('id', $audioTaskId)
                ->where('environment_id', $environmentId)->find();
            $candidateInput = $candidate === null ? null
                : (is_object($candidate->input) ? (array) $candidate->input : $candidate->input);
            $fileId = is_array($candidateInput) ? ($candidateInput['file_id'] ?? null) : null;
            if (!is_int($fileId) || $fileId < 1) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
            }
            $file = AiFile::where('id', $fileId)->where('environment_id', $environmentId)
                ->lock(true)->find();
            $task = TaskRun::where('id', $audioTaskId)->where('environment_id', $environmentId)->lock(true)->find();
            if ($task === null) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Completed audio task not found');
            }
            $lockedInput = is_object($task->input) ? (array) $task->input : $task->input;
            if (!is_array($lockedInput) || ($lockedInput['file_id'] ?? null) !== $fileId) {
                throw new ApiProblem('SAND_AI_AUDIO_SUMMARY_SOURCE_INVALID', 'The audio task source changed');
            }
            $source = (new MeetingSummarySource())->load($environmentId, $audioTaskId, $identity, null, 0, true);
            if ($file === null || $source['file_id'] !== $fileId
                || (string) $file->state !== 'uploaded'
                || (int) $file->status !== 1 || $file->delete_time !== null
                || (string) $file->owner_scope !== $scope
                || (string) $file->file_ref !== $source['file_ref']
                || !hash_equals((string) $file->sha256, $source['transcript']['segments'][0]['source']['content_sha256'])) {
                throw new ApiProblem('SAND_AI_RESOURCE_NOT_FOUND', 'Private audio source not found');
            }
            if (!isset($source['transcript']['segments'][$segmentIndex])) {
                throw new ApiProblem('SAND_AI_VALIDATION_ERROR', 'Audio segment index is invalid');
            }
            $existing = AudioSegmentReview::where('request_id', $requestId)->find();
            if ($existing !== null) {
                if ((int) $existing->audio_task_id !== $audioTaskId
                    || (int) $existing->expected_revision !== $expectedRevision
                    || (int) $existing->segment_index !== $segmentIndex
                    || (string) $existing->review_text !== $text
                    || in_array($existing->speaker_changed, [true, 1, '1', 't'], true) !== $speakerChanged
                    || ($speakerChanged && $existing->review_speaker !== $speaker)
                    || (string) $existing->owner_scope !== $scope
                    || !hash_equals((string) $existing->base_sha256, $source['sha256'])) {
                    throw new ApiProblem('SAND_AI_IDEMPOTENCY_CONFLICT', 'Audio review request changed');
                }
                return [
                    'revision' => (int) $existing->revision,
                    'segment_index' => $segmentIndex,
                    'text' => $text,
                    'idempotent_replay' => true,
                ];
            }
            $revision = (new AudioSegmentReviewLedger())->latestRevision($audioTaskId);
            if ($revision !== $expectedRevision) {
                throw new ApiProblem('SAND_AI_RESOURCE_CONFLICT', 'Audio review revision changed');
            }
            AudioSegmentReview::create([
                'audio_task_id' => $audioTaskId,
                'revision' => $revision + 1,
                'expected_revision' => $revision,
                'segment_index' => $segmentIndex,
                'base_sha256' => $source['sha256'],
                'review_text' => $text,
                'speaker_changed' => $speakerChanged,
                'review_speaker' => $speaker,
                'owner_scope' => $scope,
                'request_id' => $requestId,
            ]);
            (new AudioTranscriptSealLedger())->sealReview($task, $revision + 1, $source['transcript'], $scope);
            AuditLog::create([
                'environment_id' => $environmentId,
                'actor_type' => 'workload',
                'actor_ref' => $identity->identitySnapshot['workload_client_ref'] ?? '',
                'action' => 'audio_transcription.segment_review',
                'resource_type' => 'audio_transcription',
                'resource_id' => $audioTaskId,
                'summary' => 'Reviewed audio segment',
                'context' => ['revision' => $revision + 1, 'segment_index' => $segmentIndex],
            ]);
            return [
                'revision' => $revision + 1,
                'segment_index' => $segmentIndex,
                'text' => $text,
                'idempotent_replay' => false,
            ];
        });
    }
}
