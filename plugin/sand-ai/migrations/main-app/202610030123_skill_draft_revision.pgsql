-- Preserve fixed Skill evidence while allowing a new revision of an unpublished draft.
DO $$
DECLARE constraint_name text;
BEGIN
    FOR constraint_name IN
        SELECT conname FROM pg_constraint
        WHERE conrelid='sand_ai_skill_version'::regclass AND contype='c'
            AND pg_get_constraintdef(oid) LIKE '%state%'
            AND pg_get_constraintdef(oid) LIKE '%draft%'
            AND pg_get_constraintdef(oid) LIKE '%published%'
            AND pg_get_constraintdef(oid) LIKE '%revoked%'
    LOOP
        EXECUTE format('ALTER TABLE sand_ai_skill_version DROP CONSTRAINT %I', constraint_name);
    END LOOP;
END $$;
ALTER TABLE sand_ai_skill_version ADD CONSTRAINT ck_sand_ai_skill_version_state_v123
    CHECK (state IN ('draft','published','revoked','abandoned'));

CREATE OR REPLACE FUNCTION sand_ai_skill_version_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    -- Retain the existing narrow source-content redaction contract from migration 095.
    IF TG_OP='UPDATE' AND sand_ai_skill_source_redaction_allowed(OLD,NEW) THEN RETURN NEW; END IF;
    IF TG_OP='DELETE' OR OLD.state IN ('published','revoked','abandoned') THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE';
    END IF;
    IF OLD.state<>'draft' OR NEW.state NOT IN ('draft','published','revoked','abandoned') THEN
        RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_STATE_INVALID';
    END IF;
    IF NEW.state='published' THEN
        IF to_jsonb(NEW)-ARRAY['state','published_time','published_consumer_context','update_time']
            IS DISTINCT FROM to_jsonb(OLD)-ARRAY['state','published_time','published_consumer_context','update_time']
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    ELSE
        IF to_jsonb(NEW)-ARRAY['state','update_time']
            IS DISTINCT FROM to_jsonb(OLD)-ARRAY['state','update_time']
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_VERSION_IMMUTABLE'; END IF;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_skill_knowledge_ref_immutable_v019() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') THEN
        RAISE EXCEPTION 'Skill knowledge references are immutable; save a new draft version';
    END IF;
    IF EXISTS (SELECT 1 FROM sand_ai_skill_version WHERE id=NEW.skill_version_id AND state<>'draft') THEN
        RAISE EXCEPTION 'Only a new draft version accepts its fixed knowledge reference';
    END IF;
    RETURN NEW;
END $$;
