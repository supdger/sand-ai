-- SAND-119B-1C-B1. A provider-successful dispatch can only be recovered from
-- its receipt locally; it is never eligible for a new provider dispatch.
ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS local_recovery_count integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS local_recovery_max integer NOT NULL DEFAULT 1;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_ai_task_run_local_recovery_limits') THEN
        ALTER TABLE sand_ai_task_run
            ADD CONSTRAINT ck_sand_ai_task_run_local_recovery_limits
            CHECK (local_recovery_count >= 0 AND local_recovery_max = 1 AND local_recovery_count <= local_recovery_max);
    END IF;
END $$;

-- The task state constraint is named in the fencing migration.  Recreate that
-- specific state constraint to add the non-claimable recovery state.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT IF EXISTS ck_sand_ai_task_run_state;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_state
    CHECK (state IN ('queued', 'running', 'retrying', 'recovery_pending', 'succeeded', 'failed', 'canceled'));

CREATE INDEX IF NOT EXISTS idx_sand_ai_task_run_recovery_pending
    ON sand_ai_task_run (state, local_recovery_count, id)
    WHERE state = 'recovery_pending';
