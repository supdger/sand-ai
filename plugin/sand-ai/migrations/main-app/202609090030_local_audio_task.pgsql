-- SAND-121C source-only migration. Do not execute without separate database authorization.
ALTER TABLE sand_ai_file ADD COLUMN IF NOT EXISTS file_ref varchar(128) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_file_file_ref_v030
    ON sand_ai_file(file_ref) WHERE file_ref IS NOT NULL AND delete_time IS NULL;
ALTER TABLE sand_ai_file ADD CONSTRAINT ck_sand_ai_file_file_ref_v030
    CHECK (file_ref IS NULL OR file_ref ~ '^saf_[A-Za-z0-9_-]{16,120}$') NOT VALID;

-- Existing profiles may not turn audio into a model/external route. The application
-- performs the code-owned driver and local-only checks before TaskWorker dispatch.
ALTER TABLE sand_ai_capability_route ADD CONSTRAINT ck_sand_ai_capability_route_audio_v030
    CHECK (capability_code <> 'audio_transcription' OR (driver_code = 'local_whisper_cpp' AND deployment_id IS NOT NULL)) NOT VALID;
