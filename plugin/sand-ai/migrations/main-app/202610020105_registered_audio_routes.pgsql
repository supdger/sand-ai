-- Source only: execute through the authorized lifecycle in an existing database.
-- Preserve historical checksums and existing rows. Enforce registered ASR drivers
-- and an explicit deployment on every new or updated audio-transcription route.
ALTER TABLE sand_ai_capability_route
    DROP CONSTRAINT ck_sand_ai_capability_route_audio_v030;
ALTER TABLE sand_ai_capability_route
    ADD CONSTRAINT ck_sand_ai_capability_route_audio_v105
    CHECK (
        capability_code <> 'audio_transcription'
        OR (
            driver_code IS NOT NULL
            AND driver_code IN ('local_whisper_cpp', 'openai_compatible_audio', 'glm_asr_audio')
            AND deployment_id IS NOT NULL
        )
    ) NOT VALID;
