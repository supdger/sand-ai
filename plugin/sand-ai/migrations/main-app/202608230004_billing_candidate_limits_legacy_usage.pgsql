
ALTER TABLE sand_ai_usage_reconciliation ADD COLUMN IF NOT EXISTS reason VARCHAR(64) NOT NULL DEFAULT 'unspecified';
COMMENT ON COLUMN sand_ai_usage_reconciliation.reason IS 'Final automatic reconciliation reason; immutable with the result.';

ALTER TABLE sand_ai_usage ALTER COLUMN cost_amount DROP NOT NULL;
ALTER TABLE sand_ai_usage ALTER COLUMN currency DROP NOT NULL;
ALTER TABLE sand_ai_usage ALTER COLUMN cost_amount DROP DEFAULT;
ALTER TABLE sand_ai_usage ALTER COLUMN currency DROP DEFAULT;
COMMENT ON TABLE sand_ai_usage IS 'Legacy compatibility usage rows only; sand_ai_usage_event is the pricing authority.';
COMMENT ON COLUMN sand_ai_usage.cost_amount IS 'NULL means unknown or unpriced; do not treat as zero cost.';
