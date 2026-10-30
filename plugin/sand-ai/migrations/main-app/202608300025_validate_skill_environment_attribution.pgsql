-- SAND-139: close the Skill environment identity gap without changing the
-- historical SAND-134A migrations or their checksums.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_skill_run run
        LEFT JOIN sand_ai_skill skill ON skill.id = run.skill_id
        LEFT JOIN sand_ai_skill_version version ON version.id = run.skill_version_id AND version.skill_id = run.skill_id
        WHERE skill.id IS NULL OR version.id IS NULL OR run.environment_id <> skill.environment_id
    ) THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_RUN_ENVIRONMENT_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_skill_run run
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = run.invocation_id
        WHERE run.invocation_id IS NOT NULL
            AND (invocation.id IS NULL
                OR invocation.environment_id IS DISTINCT FROM run.environment_id
                OR invocation.skill_id IS DISTINCT FROM run.skill_id
                OR invocation.skill_version_id IS DISTINCT FROM run.skill_version_id
                OR invocation.skill_run_id IS DISTINCT FROM run.id
                OR invocation.model_id IS DISTINCT FROM run.model_id)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_RUN_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation invocation
        LEFT JOIN sand_ai_skill_run run ON run.environment_id = invocation.environment_id
            AND run.skill_id = invocation.skill_id AND run.skill_version_id = invocation.skill_version_id AND run.id = invocation.skill_run_id
        WHERE num_nonnulls(invocation.skill_id, invocation.skill_version_id, invocation.skill_run_id) NOT IN (0, 3)
            OR (invocation.skill_id IS NOT NULL AND run.id IS NULL)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_INVOCATION_SKILL_ENVIRONMENT_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_usage_event usage_event
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = usage_event.invocation_id
        WHERE (usage_event.invocation_id IS NOT NULL
                AND (invocation.id IS NULL
                    OR invocation.environment_id IS DISTINCT FROM usage_event.environment_id
                    OR invocation.model_id IS DISTINCT FROM usage_event.model_id
                    OR invocation.deployment_id IS DISTINCT FROM usage_event.deployment_id
                    OR invocation.skill_id IS DISTINCT FROM usage_event.skill_id
                    OR invocation.skill_version_id IS DISTINCT FROM usage_event.skill_version_id
                    OR invocation.skill_run_id IS DISTINCT FROM usage_event.skill_run_id))
            OR (usage_event.invocation_id IS NULL
                AND num_nonnulls(usage_event.skill_id, usage_event.skill_version_id, usage_event.skill_run_id) > 0)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_USAGE_EVENT_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_usage_event usage_event
        LEFT JOIN sand_ai_skill_run run ON run.environment_id = usage_event.environment_id
            AND run.skill_id = usage_event.skill_id AND run.skill_version_id = usage_event.skill_version_id AND run.id = usage_event.skill_run_id
        WHERE num_nonnulls(usage_event.skill_id, usage_event.skill_version_id, usage_event.skill_run_id) NOT IN (0, 3)
            OR (usage_event.skill_id IS NOT NULL AND run.id IS NULL)
    ) THEN
        RAISE EXCEPTION 'SAND_AI_USAGE_EVENT_SKILL_ENVIRONMENT_MISMATCH';
    END IF;
END $$;

ALTER TABLE sand_ai_skill
    ADD CONSTRAINT uq_sand_ai_skill_id_environment_v025 UNIQUE (id, environment_id);
ALTER TABLE sand_ai_skill_run
    ADD CONSTRAINT fk_sand_ai_skill_run_environment_v025 FOREIGN KEY (skill_id, environment_id)
        REFERENCES sand_ai_skill(id, environment_id) NOT VALID,
    ADD CONSTRAINT fk_sand_ai_skill_run_version_v025 FOREIGN KEY (skill_version_id, skill_id)
        REFERENCES sand_ai_skill_version(id, skill_id) NOT VALID,
    ADD CONSTRAINT uq_sand_ai_skill_run_environment_triple_v025 UNIQUE (environment_id, skill_id, skill_version_id, id);
ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT fk_sand_ai_invocation_skill_environment_triple_v025
        FOREIGN KEY (environment_id, skill_id, skill_version_id, skill_run_id)
        REFERENCES sand_ai_skill_run(environment_id, skill_id, skill_version_id, id) NOT VALID;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT fk_sand_ai_usage_event_skill_environment_triple_v025
        FOREIGN KEY (environment_id, skill_id, skill_version_id, skill_run_id)
        REFERENCES sand_ai_skill_run(environment_id, skill_id, skill_version_id, id) NOT VALID;

ALTER TABLE sand_ai_skill VALIDATE CONSTRAINT ck_sand_ai_skill_state_v019;
ALTER TABLE sand_ai_skill VALIDATE CONSTRAINT ck_sand_ai_skill_published_active_v019;
ALTER TABLE sand_ai_skill_version VALIDATE CONSTRAINT ck_sand_ai_skill_version_snapshot_object_v019;
ALTER TABLE sand_ai_skill_operation VALIDATE CONSTRAINT fk_sand_ai_skill_operation_version_v019;
ALTER TABLE sand_ai_skill_operation VALIDATE CONSTRAINT ck_sand_ai_skill_operation_key_v020;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT ck_sand_ai_skill_run_kind_v019;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT fk_sand_ai_skill_run_environment_v025;
ALTER TABLE sand_ai_skill_run VALIDATE CONSTRAINT fk_sand_ai_skill_run_version_v025;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT ck_sand_ai_invocation_skill_triple_v019;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT fk_sand_ai_invocation_skill_triple_v019;
ALTER TABLE sand_ai_invocation VALIDATE CONSTRAINT fk_sand_ai_invocation_skill_environment_triple_v025;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT ck_sand_ai_usage_event_skill_triple_v019;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_skill_triple_v019;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_skill_environment_triple_v025;
