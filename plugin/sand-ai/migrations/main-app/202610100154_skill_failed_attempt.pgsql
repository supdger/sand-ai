-- Preserve every failed run and its invocation; recovery creates a separate run.
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS original_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS previous_run_id bigint NULL REFERENCES sand_ai_skill_run(id);
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS attempt_no smallint NOT NULL DEFAULT 1;
ALTER TABLE sand_ai_skill_run ADD COLUMN IF NOT EXISTS retry_feedback text NULL;
ALTER TABLE sand_ai_skill_run ADD CONSTRAINT ck_sand_ai_skill_run_attempt CHECK (
    (attempt_no = 1 AND original_run_id IS NULL AND previous_run_id IS NULL AND retry_feedback IS NULL)
    OR (attempt_no BETWEEN 2 AND 3 AND original_run_id IS NOT NULL AND previous_run_id IS NOT NULL
        AND original_run_id < id AND previous_run_id < id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_run_previous_attempt ON sand_ai_skill_run(previous_run_id)
    WHERE previous_run_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_skill_run_original_attempt ON sand_ai_skill_run(original_run_id, attempt_no)
    WHERE original_run_id IS NOT NULL;

CREATE FUNCTION sand_ai_skill_run_attempt_guard_v154() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE original sand_ai_skill_run%ROWTYPE; previous sand_ai_skill_run%ROWTYPE;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.original_run_id IS DISTINCT FROM NEW.original_run_id
            OR OLD.previous_run_id IS DISTINCT FROM NEW.previous_run_id
            OR OLD.attempt_no IS DISTINCT FROM NEW.attempt_no
            OR OLD.retry_feedback IS DISTINCT FROM NEW.retry_feedback THEN
            RAISE EXCEPTION 'Skill attempt identity and correction are immutable';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.attempt_no = 1 THEN RETURN NEW; END IF;
    SELECT * INTO original FROM sand_ai_skill_run WHERE id = NEW.original_run_id;
    SELECT * INTO previous FROM sand_ai_skill_run WHERE id = NEW.previous_run_id;
    IF original.id IS NULL OR previous.id IS NULL OR original.attempt_no <> 1
        OR previous.state <> 'failed' OR previous.delete_time IS NOT NULL OR original.delete_time IS NOT NULL
        OR previous.error_code NOT IN ('SAND_AI_SCHEMA_UNSUPPORTED', 'SAND_AI_SKILL_OUTPUT_INVALID')
        OR COALESCE(previous.original_run_id, previous.id) <> original.id
        OR NEW.attempt_no <> previous.attempt_no + 1
        OR NEW.environment_id <> original.environment_id OR NEW.skill_id <> original.skill_id
        OR NEW.skill_version_id <> original.skill_version_id OR NEW.run_kind <> original.run_kind
        OR NEW.model_id IS DISTINCT FROM original.model_id
        OR NEW.request_fingerprint IS DISTINCT FROM original.request_fingerprint
        OR NEW.input IS DISTINCT FROM original.input
        OR NEW.consumer_context IS DISTINCT FROM original.consumer_context
        OR NEW.citations IS DISTINCT FROM original.citations
        OR NEW.prompt_contract_version <> original.prompt_contract_version THEN
        RAISE EXCEPTION 'Skill recovery must preserve the original request and fixed version';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_skill_run_attempt_guard_v154 BEFORE INSERT OR UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_run_attempt_guard_v154();
