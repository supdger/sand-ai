-- A persisted workflow must be visible to SandIAM before authorization.
-- Workers only claim queued/retrying tasks, never authorization_pending.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT IF EXISTS ck_sand_ai_task_run_state;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_state
    CHECK (state IN ('authorization_pending', 'queued', 'running', 'retrying', 'recovery_pending', 'succeeded', 'failed', 'canceled'));
