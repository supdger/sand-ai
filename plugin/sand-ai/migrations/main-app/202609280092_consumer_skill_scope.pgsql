-- Source-only: consumer-declared Skill content scope; does not create IAM identities or grants.
ALTER TABLE sand_ai_skill
    ADD COLUMN organization_id bigint NULL,
    ADD COLUMN application_id bigint NULL,
    ADD COLUMN created_workload_client_id bigint NULL,
    ADD COLUMN scope_type varchar(16) NOT NULL DEFAULT 'legacy',
    ADD COLUMN scope_ref varchar(128) NULL,
    ADD COLUMN scope_source varchar(32) NOT NULL DEFAULT 'legacy',
    ADD COLUMN created_actor_ref varchar(128) NULL,
    ADD CONSTRAINT sand_ai_skill_consumer_scope_ck CHECK (
        (scope_type='legacy' AND scope_source='legacy' AND scope_ref IS NULL
         AND organization_id IS NULL AND application_id IS NULL
         AND created_workload_client_id IS NULL AND created_actor_ref IS NULL)
        OR (scope_type IN ('personal','team','organization') AND scope_source='consumer_declared'
            AND organization_id IS NOT NULL AND application_id IS NOT NULL AND created_workload_client_id IS NOT NULL
            AND organization_id>0 AND application_id>0 AND created_workload_client_id>0
            AND scope_ref IS NOT NULL AND octet_length(scope_ref) BETWEEN 1 AND 128
            AND created_actor_ref IS NOT NULL AND octet_length(created_actor_ref) BETWEEN 1 AND 128
            AND (scope_type<>'organization' OR scope_ref=organization_id::text))
    );
CREATE INDEX sand_ai_skill_consumer_scope_idx
    ON sand_ai_skill(organization_id,application_id,environment_id,scope_type,scope_ref,id)
    WHERE scope_source='consumer_declared' AND delete_time IS NULL;
ALTER TABLE sand_ai_skill_version
    ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object'),
    ADD COLUMN published_consumer_context jsonb NULL CHECK (published_consumer_context IS NULL OR jsonb_typeof(published_consumer_context)='object');
ALTER TABLE sand_ai_skill_run ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_operation ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_case ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
ALTER TABLE sand_ai_skill_case_evaluation ADD COLUMN consumer_context jsonb NULL CHECK (consumer_context IS NULL OR jsonb_typeof(consumer_context)='object');
DO $$ DECLARE constraint_name text;
BEGIN
    FOR constraint_name IN SELECT conname FROM pg_constraint
        WHERE conrelid='sand_ai_skill_operation'::regclass AND contype='c'
          AND pg_get_constraintdef(oid) LIKE '%operation%create_draft%' LOOP
        EXECUTE format('ALTER TABLE sand_ai_skill_operation DROP CONSTRAINT %I',constraint_name);
    END LOOP;
END $$;
ALTER TABLE sand_ai_skill_operation ADD CONSTRAINT sand_ai_skill_operation_kind_v092_ck
    CHECK (operation IN ('create_draft','publish','revoke','rollback'));

CREATE FUNCTION sand_ai_skill_scope_immutable_v092() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.environment_id,NEW.code,NEW.organization_id,NEW.application_id,
           NEW.created_workload_client_id,NEW.scope_type,NEW.scope_ref,NEW.scope_source,NEW.created_actor_ref)
       IS DISTINCT FROM
       ROW(OLD.environment_id,OLD.code,OLD.organization_id,OLD.application_id,
           OLD.created_workload_client_id,OLD.scope_type,OLD.scope_ref,OLD.scope_source,OLD.created_actor_ref)
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_SCOPE_IMMUTABLE'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_scope_immutable_v092 BEFORE UPDATE ON sand_ai_skill
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_scope_immutable_v092();

CREATE FUNCTION sand_ai_skill_consumer_context_guard_v092() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill%ROWTYPE; fixed_skill_id bigint;
BEGIN
    IF TG_OP='UPDATE' AND NEW.consumer_context IS DISTINCT FROM OLD.consumer_context
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_CONTEXT_IMMUTABLE'; END IF;
    IF TG_TABLE_NAME IN ('sand_ai_skill_run','sand_ai_skill_operation','sand_ai_skill_version') THEN
        fixed_skill_id := NEW.skill_id;
    ELSIF TG_TABLE_NAME='sand_ai_skill_case' THEN
        SELECT skill_id INTO fixed_skill_id FROM sand_ai_skill_version WHERE id=NEW.skill_version_id;
    ELSE
        SELECT v.skill_id INTO fixed_skill_id FROM sand_ai_skill_case c
            JOIN sand_ai_skill_version v ON v.id=c.skill_version_id WHERE c.id=NEW.case_id;
    END IF;
    SELECT * INTO owner_row FROM sand_ai_skill WHERE id=fixed_skill_id;
    IF owner_row.scope_type='legacy' THEN
        IF NEW.consumer_context IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_SKILL_LEGACY_CONTEXT_INVALID'; END IF;
    ELSIF NEW.consumer_context IS NULL
        OR NEW.consumer_context->>'scope_source' IS DISTINCT FROM 'consumer_declared'
        OR NEW.consumer_context->>'actor_source' IS DISTINCT FROM 'consumer_declared'
        OR NEW.consumer_context->'scope'->>'type' IS DISTINCT FROM owner_row.scope_type
        OR NEW.consumer_context->'scope'->>'ref' IS DISTINCT FROM owner_row.scope_ref
        OR NEW.consumer_context->>'organization_id' IS DISTINCT FROM owner_row.organization_id::text
        OR NEW.consumer_context->>'application_id' IS DISTINCT FROM owner_row.application_id::text
        OR NEW.consumer_context->>'environment_id' IS DISTINCT FROM owner_row.environment_id::text
        OR COALESCE(NEW.consumer_context->>'workload_client_id','') !~ '^[1-9][0-9]*$'
        OR COALESCE(octet_length(NEW.consumer_context->>'actor_ref'),0) NOT BETWEEN 1 AND 128
    THEN RAISE EXCEPTION 'SAND_AI_SKILL_CONSUMER_CONTEXT_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_version_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_run_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_operation_consumer_v092 BEFORE INSERT ON sand_ai_skill_operation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_case_consumer_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_case
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();
CREATE TRIGGER sand_ai_skill_case_evaluation_consumer_v092 BEFORE INSERT ON sand_ai_skill_case_evaluation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_consumer_context_guard_v092();

CREATE FUNCTION sand_ai_skill_publish_consumer_guard_v092() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE owner_row sand_ai_skill%ROWTYPE; context jsonb;
BEGIN
    SELECT * INTO owner_row FROM sand_ai_skill WHERE id=NEW.skill_id;
    context := NEW.published_consumer_context;
    IF NEW.state='published' AND owner_row.scope_type<>'legacy' THEN
        IF context IS NULL
            OR context->>'scope_source' IS DISTINCT FROM 'consumer_declared'
            OR context->>'actor_source' IS DISTINCT FROM 'consumer_declared'
            OR context->'scope'->>'type' IS DISTINCT FROM owner_row.scope_type
            OR context->'scope'->>'ref' IS DISTINCT FROM owner_row.scope_ref
            OR context->>'organization_id' IS DISTINCT FROM owner_row.organization_id::text
            OR context->>'application_id' IS DISTINCT FROM owner_row.application_id::text
            OR context->>'environment_id' IS DISTINCT FROM owner_row.environment_id::text
            OR COALESCE(context->>'workload_client_id','') !~ '^[1-9][0-9]*$'
            OR COALESCE(octet_length(context->>'actor_ref'),0) NOT BETWEEN 1 AND 128
        THEN RAISE EXCEPTION 'SAND_AI_SKILL_PUBLISH_CONSUMER_INVALID'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_skill_publish_consumer_guard_v092 BEFORE INSERT OR UPDATE ON sand_ai_skill_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_skill_publish_consumer_guard_v092();
