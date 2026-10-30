-- Source-only migration. Apply only after explicit database authorization.
-- NULL identifies legacy evidence. Do not rehash or backfill immutable rows.
ALTER TABLE sand_ai_workflow_file_result
    ADD COLUMN payload_digest_version smallint NULL,
    ADD CONSTRAINT ck_sand_ai_workflow_file_result_digest_version
        CHECK (payload_digest_version IS NULL OR payload_digest_version = 2);
