-- 0.5.0 UX declutter: an empty monthly limit means the budget has no
-- ceiling, and each published revision may carry its own reminder
-- percentage (default 80, matching the historical fixed notice level).
-- Unlimited windows keep tracking reserved/settled/held amounts; only the
-- limit ceiling and the alert thresholds change meaning.
ALTER TABLE sand_ai_budget_policy
    ALTER COLUMN limit_amount DROP NOT NULL,
    ADD COLUMN reminder_percent smallint NOT NULL DEFAULT 80,
    DROP CONSTRAINT ck_sand_ai_budget_policy_limit,
    ADD CONSTRAINT ck_sand_ai_budget_policy_limit CHECK (limit_amount IS NULL OR limit_amount > 0),
    ADD CONSTRAINT ck_sand_ai_budget_policy_reminder CHECK (reminder_percent BETWEEN 1 AND 100);

ALTER TABLE sand_ai_budget_window
    ALTER COLUMN limit_amount DROP NOT NULL,
    DROP CONSTRAINT ck_sand_ai_budget_window_amounts,
    ADD CONSTRAINT ck_sand_ai_budget_window_amounts CHECK (
        (limit_amount IS NULL OR limit_amount > 0)
        AND reserved_amount >= 0 AND settled_amount >= 0 AND held_amount >= 0
    );
