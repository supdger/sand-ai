-- Recover authorized private-file deletions after a worker or request exits.
CREATE INDEX IF NOT EXISTS sand_ai_file_deleting_recovery_idx
    ON sand_ai_file (id)
    WHERE state = 'deleting' AND status = 1 AND delete_time IS NULL;
