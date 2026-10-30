-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_budget_reservation
    ADD COLUMN speech_run_id bigint NULL REFERENCES sand_ai_speech_run(id);
CREATE UNIQUE INDEX uq_sand_ai_budget_reservation_speech_run_v062
    ON sand_ai_budget_reservation(speech_run_id) WHERE speech_run_id IS NOT NULL;
ALTER TABLE sand_ai_budget_reservation
    ADD CONSTRAINT ck_sand_ai_budget_reservation_owner_v062
    CHECK (speech_run_id IS NULL OR invocation_id IS NULL) NOT VALID;
ALTER TABLE sand_ai_budget_event
    ADD COLUMN speech_run_id bigint NULL REFERENCES sand_ai_speech_run(id);
ALTER TABLE sand_ai_budget_event
    ADD CONSTRAINT ck_sand_ai_budget_event_owner_v062
    CHECK (invocation_id IS NULL OR speech_run_id IS NULL) NOT VALID;
ALTER TABLE sand_ai_speech_run
    ADD COLUMN budget_reservation_id bigint NULL REFERENCES sand_ai_budget_reservation(id);
CREATE UNIQUE INDEX uq_sand_ai_speech_run_budget_reservation_v062
    ON sand_ai_speech_run(budget_reservation_id) WHERE budget_reservation_id IS NOT NULL;

CREATE FUNCTION sand_ai_speech_budget_link_guard_v062() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.budget_reservation_id IS NOT NULL
       AND NEW.budget_reservation_id IS DISTINCT FROM OLD.budget_reservation_id THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_IMMUTABLE';
    END IF;
    IF NEW.budget_reservation_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM sand_ai_budget_reservation r
           JOIN sand_ai_budget_policy p ON p.id = r.policy_id
           WHERE r.id = NEW.budget_reservation_id AND r.speech_run_id = NEW.id
             AND r.invocation_id IS NULL AND p.environment_id = NEW.environment_id) THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_BUDGET_LINK_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_speech_budget_link_v062
    BEFORE UPDATE OF budget_reservation_id ON sand_ai_speech_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_speech_budget_link_guard_v062();
