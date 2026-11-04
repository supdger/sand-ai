-- An accepted authorization continuation grants exactly one more local
-- receipt recovery for the same retained paid outcome; it never dispatches a
-- provider again. The original fencing bound pinned local_recovery_max to 1
-- before continuations existed, so every explicit continuation write rolled
-- its fresh authorization, marker and state back on the row CHECK. The bound
-- now keeps the attempt audit monotonic instead of pinning the allowance.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
ALTER TABLE sand_ai_task_run DROP CONSTRAINT ck_sand_ai_task_run_local_recovery_limits;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_local_recovery_limits
    CHECK (local_recovery_count >= 0 AND local_recovery_max >= 1 AND local_recovery_count <= local_recovery_max);
