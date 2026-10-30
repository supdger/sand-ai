-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_audio_segment_review
    ADD COLUMN speaker_changed boolean NOT NULL DEFAULT false,
    ADD COLUMN review_speaker varchar(16) NULL;

ALTER TABLE sand_ai_audio_segment_review
    ADD CONSTRAINT ck_sand_ai_audio_segment_review_speaker
    CHECK (
        (speaker_changed AND (review_speaker IS NULL OR review_speaker ~ '^speaker_[1-9][0-9]{0,3}$'))
        OR (NOT speaker_changed AND review_speaker IS NULL)
    );
