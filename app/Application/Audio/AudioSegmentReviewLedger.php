<?php

declare(strict_types=1);

namespace app\Application\Audio;

use app\Api\Support\ApiProblem;
use app\Infrastructure\Persistence\Model\AudioSegmentReview;

/** Projects append-only workload reviews onto an immutable audio transcript. */
final class AudioSegmentReviewLedger
{
    public function latestRevision(int $audioTaskId): int
    {
        $last = AudioSegmentReview::where('audio_task_id', $audioTaskId)
            ->order('revision', 'desc')->find();
        return $last === null ? 0 : (int) $last->revision;
    }

    /** @param array<string,mixed> $transcript @return array<string,mixed> */
    public function snapshot(int $audioTaskId, array $transcript, string $ownerScope, int $revision): array
    {
        if ($revision < 0 || $revision > 10000) {
            throw new ApiProblem('SAND_AI_AUDIO_REVIEW_REVISION_INVALID', 'Audio review revision is invalid');
        }
        if ($revision === 0) return $transcript;
        $rows = AudioSegmentReview::where('audio_task_id', $audioTaskId)
            ->where('revision', '<=', $revision)->order('revision')->select();
        $reviews = [];
        foreach ($rows as $row) {
            $reviews[] = [
                'revision' => (int) $row->revision,
                'segment_index' => (int) $row->segment_index,
                'base_sha256' => (string) $row->base_sha256,
                'review_text' => (string) $row->review_text,
                'speaker_changed' => in_array($row->speaker_changed, [true, 1, '1', 't'], true),
                'review_speaker' => $row->review_speaker === null ? null : (string) $row->review_speaker,
                'owner_scope' => (string) $row->owner_scope,
            ];
        }
        return self::overlay($transcript, $reviews, $ownerScope, $revision);
    }

    /** @param array<string,mixed> $transcript @param list<array<string,mixed>> $reviews @return array<string,mixed> */
    public static function overlay(array $transcript, array $reviews, string $ownerScope, int $revision): array
    {
        if ($revision < 0 || $revision > 10000
            || preg_match('/\A[a-f0-9]{64}\z/D', $ownerScope) !== 1
            || !is_array($transcript['segments'] ?? null)) {
            self::invalid();
        }
        if ($revision === 0) {
            if ($reviews !== []) self::invalid();
            return $transcript;
        }
        $baseHash = hash('sha256', json_encode($transcript, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $next = 1;
        foreach ($reviews as $review) {
            $index = $review['segment_index'] ?? null;
            $text = $review['review_text'] ?? null;
            $speakerChanged = $review['speaker_changed'] ?? false;
            $speaker = $review['review_speaker'] ?? null;
            if (($review['revision'] ?? null) !== $next
                || !is_int($index) || $index < 0 || !isset($transcript['segments'][$index])
                || !is_string($text) || mb_strlen($text, 'UTF-8') > 10000
                || !is_bool($speakerChanged)
                || (!$speakerChanged && $speaker !== null)
                || ($speaker !== null && (!is_string($speaker)
                    || preg_match('/\Aspeaker_[1-9][0-9]{0,3}\z/D', $speaker) !== 1))
                || ($review['owner_scope'] ?? null) !== $ownerScope
                || !is_string($review['base_sha256'] ?? null)
                || !hash_equals($baseHash, $review['base_sha256'])) {
                self::invalid();
            }
            $warnings = $transcript['segments'][$index]['warnings'] ?? null;
            if (!is_array($warnings) || !array_is_list($warnings)) self::invalid();
            $warnings = array_values(array_filter(
                $warnings, static fn (string $warning): bool => $warning !== 'no_recognized_speech' && $warning !== 'human_reviewed',
            ));
            if (trim($text) === '') $warnings[] = 'no_recognized_speech';
            $warnings[] = 'human_reviewed';
            $transcript['segments'][$index]['text'] = trim($text);
            if ($speakerChanged) $transcript['segments'][$index]['speaker'] = $speaker;
            // Old numeric projections retain their exact sealed representation.
            // A review cannot manufacture a confidence value for new unknown results.
            if ($transcript['segments'][$index]['confidence'] !== null) {
                $transcript['segments'][$index]['confidence'] = '0.0000';
            }
            $transcript['segments'][$index]['warnings'] = $warnings;
            $transcript['segments'][$index]['review_revision'] = $next;
            $next++;
        }
        if ($next !== $revision + 1) self::invalid();
        $transcript['review_revision'] = $revision;
        return $transcript;
    }

    private static function invalid(): never
    {
        throw new ApiProblem('SAND_AI_AUDIO_REVIEW_EVIDENCE_INVALID', 'Audio review evidence is unavailable');
    }
}
