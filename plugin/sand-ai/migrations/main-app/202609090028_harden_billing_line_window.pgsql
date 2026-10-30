-- Keep 003 immutable: existing data must be clean before this stricter invariant is installed.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_billing_line
        WHERE occurred_end <= occurred_start
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23514',
            MESSAGE = 'SAND_AI_BILLING_LINE_PERIOD_INVALID',
            DETAIL = 'Existing billing lines require occurred_end later than occurred_start before migration 202609090028 can run.';
    END IF;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_billing_line
    DROP CONSTRAINT ck_sand_ai_billing_line_period;

ALTER TABLE sand_ai_billing_line
    ADD CONSTRAINT ck_sand_ai_billing_line_period
    CHECK (occurred_end > occurred_start) NOT VALID;

ALTER TABLE sand_ai_billing_line
    VALIDATE CONSTRAINT ck_sand_ai_billing_line_period;
