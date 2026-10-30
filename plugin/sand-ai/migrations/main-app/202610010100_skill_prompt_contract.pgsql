-- Freeze the prompt format per accepted Skill run. Existing runs retain v1 so
-- their provider request fingerprint and paid receipt can be recovered exactly.
ALTER TABLE sand_ai_skill_run
    ADD COLUMN prompt_contract_version smallint NOT NULL DEFAULT 1,
    ADD CONSTRAINT sand_ai_skill_run_prompt_contract_v100_ck
        CHECK (prompt_contract_version IN (1,2));

CREATE FUNCTION sand_ai_skill_run_prompt_contract_immutable_v100() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.prompt_contract_version IS DISTINCT FROM OLD.prompt_contract_version
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_PROMPT_CONTRACT_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_run_prompt_contract_immutable_v100
    BEFORE UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_run_prompt_contract_immutable_v100();
