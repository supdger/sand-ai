-- 0.4.9: sand_ai_provider.timeout_ms column DEFAULT becomes 120000.
-- Rows that still store the historical default 30000 and are not soft-deleted
-- (delete_time IS NULL) become 120000. Any other timeout_ms stays unchanged.
-- Soft-deleted rows keep both timeout_ms and delete_time. Deployment snapshots
-- and verification receipts are not rewritten.
ALTER TABLE sand_ai_provider ALTER COLUMN timeout_ms SET DEFAULT 120000;

UPDATE sand_ai_provider
SET timeout_ms = 120000,
    update_time = CURRENT_TIMESTAMP(0)
WHERE timeout_ms = 30000
  AND delete_time IS NULL;
