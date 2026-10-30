-- New workload files bind to the authenticated SandIAM caller. Historical files
-- remain unowned and are unavailable to direct workload file/source APIs.
ALTER TABLE sand_ai_file
    ADD COLUMN IF NOT EXISTS owner_scope char(64) NULL,
    ADD COLUMN IF NOT EXISTS owner_attribution jsonb NULL;

ALTER TABLE sand_ai_file
    ADD CONSTRAINT ck_sand_ai_file_owner_scope_v036 CHECK (
        (owner_scope IS NULL AND owner_attribution IS NULL)
        OR (owner_scope ~ '^[a-f0-9]{64}$' AND jsonb_typeof(owner_attribution) = 'object')
    ) NOT VALID;

CREATE INDEX IF NOT EXISTS sand_ai_file_owner_scope_idx
    ON sand_ai_file (environment_id, owner_scope, status)
    WHERE delete_time IS NULL;
