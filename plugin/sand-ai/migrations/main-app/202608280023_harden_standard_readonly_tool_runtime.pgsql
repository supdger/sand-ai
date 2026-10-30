-- SAND-135A static review hardening: existing Usage vocabulary, rotating
-- connection key identity, frozen receipt/run identity, explicit historical
-- version bindings, and immutable terminal runs.

ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS sand_ai_usage_event_source_kind_ck;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck
    CHECK (source_kind IN ('chat','ai_task','agent','capability','workflow','structured_workflow','external_service','onboarding_verification','skill','skill_test','tool'));

ALTER TABLE sand_ai_tool_connection_revision
    ADD COLUMN IF NOT EXISTS encryption_key_id varchar(128) NOT NULL DEFAULT 'legacy_config_v1';
ALTER TABLE sand_ai_tool_connection_revision ALTER COLUMN encryption_key_id DROP DEFAULT;
DO $$ DECLARE item record; BEGIN FOR item IN SELECT conname FROM pg_constraint WHERE conrelid='sand_ai_tool_connection_revision'::regclass AND contype='c' AND pg_get_constraintdef(oid) ILIKE '%encrypted_config%' LOOP EXECUTE format('ALTER TABLE sand_ai_tool_connection_revision DROP CONSTRAINT %I', item.conname); END LOOP; END $$;
ALTER TABLE sand_ai_tool_connection_revision DROP CONSTRAINT IF EXISTS sand_ai_tool_connection_revision_key_check;
ALTER TABLE sand_ai_tool_connection_revision
    ADD CONSTRAINT sand_ai_tool_connection_revision_envelope_ck_v023 CHECK (
        jsonb_typeof(encrypted_config) = 'object' AND
        encryption_key_id ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
        AND (
            (encrypted_config->>'version' = 'v1' AND encryption_key_id = 'legacy_config_v1' AND encrypted_config ?& ARRAY['iv','tag','ciphertext'])
            OR (encrypted_config->>'version' = 'v2' AND encrypted_config->>'cipher' = 'aes-256-gcm' AND encrypted_config->>'key_id' = encryption_key_id AND encrypted_config ?& ARRAY['nonce','tag','ciphertext'])
        )
    );

ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_receipt_identity_uniq
    UNIQUE (id, environment_id, tool_id, tool_version_id, request_fingerprint);
ALTER TABLE sand_ai_tool_outcome_receipt
    ADD CONSTRAINT sand_ai_tool_receipt_run_identity_fk
    FOREIGN KEY (tool_run_id, environment_id, tool_id, tool_version_id, request_fingerprint)
    REFERENCES sand_ai_tool_run (id, environment_id, tool_id, tool_version_id, request_fingerprint)
    DEFERRABLE INITIALLY DEFERRED;

DO $$ DECLARE item record; BEGIN FOR item IN SELECT t.tgname FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid WHERE t.tgrelid='sand_ai_tool_environment_binding'::regclass AND NOT t.tgisinternal AND (pg_get_functiondef(p.oid) ILIKE '%active_version_id%' OR pg_get_functiondef(p.oid) ILIKE '%active_revision_id%') LOOP EXECUTE format('DROP TRIGGER %I ON sand_ai_tool_environment_binding', item.tgname); END LOOP; END $$;

CREATE OR REPLACE FUNCTION sand_ai_tool_binding_published_guard_v023() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NOT EXISTS (SELECT 1 FROM sand_ai_tool t JOIN sand_ai_tool_version v ON v.tool_id=t.id AND v.id=NEW.tool_version_id JOIN sand_ai_tool_connection c ON c.id=NEW.tool_connection_id AND c.environment_id=NEW.environment_id JOIN sand_ai_tool_connection_revision r ON r.tool_connection_id=c.id AND r.id=NEW.tool_connection_revision_id WHERE t.id=NEW.tool_id AND t.state='published' AND t.status=1 AND v.state='published' AND c.state='published' AND c.status=1 AND r.state='published') THEN RAISE EXCEPTION 'tool binding requires exact published resources' USING ERRCODE='23514'; END IF; RETURN NEW; END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_binding_published_guard_v023 ON sand_ai_tool_environment_binding;
CREATE CONSTRAINT TRIGGER trg_sand_ai_tool_binding_published_guard_v023 AFTER INSERT OR UPDATE ON sand_ai_tool_environment_binding DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_binding_published_guard_v023();

CREATE OR REPLACE FUNCTION sand_ai_tool_run_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP='INSERT' AND NOT EXISTS (
        SELECT 1 FROM sand_ai_tool_environment_binding b
        WHERE b.id=NEW.tool_environment_binding_id AND b.environment_id=NEW.environment_id AND b.tool_id=NEW.tool_id
          AND b.tool_version_id=NEW.tool_version_id AND b.tool_connection_id=NEW.tool_connection_id
          AND b.tool_connection_revision_id=NEW.tool_connection_revision_id AND b.state='published' AND b.status=1
    ) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_BINDING_INVALID';
    END IF;
    IF TG_OP='UPDATE' AND (OLD.environment_id,OLD.organization_id,OLD.application_id,OLD.workload_client_id,OLD.tool_id,OLD.tool_version_id,OLD.tool_environment_binding_id,OLD.tool_connection_id,OLD.tool_connection_revision_id,OLD.request_id,OLD.request_payload_fingerprint,OLD.request_fingerprint,OLD.business_scope,OLD.input,OLD.execution_snapshot,OLD.timeout_ms,OLD.identity_snapshot)
        IS DISTINCT FROM (NEW.environment_id,NEW.organization_id,NEW.application_id,NEW.workload_client_id,NEW.tool_id,NEW.tool_version_id,NEW.tool_environment_binding_id,NEW.tool_connection_id,NEW.tool_connection_revision_id,NEW.request_id,NEW.request_payload_fingerprint,NEW.request_fingerprint,NEW.business_scope,NEW.input,NEW.execution_snapshot,NEW.timeout_ms,NEW.identity_snapshot) THEN
        RAISE EXCEPTION 'SAND_AI_TOOL_RUN_IMMUTABLE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_tool_run DROP CONSTRAINT IF EXISTS sand_ai_tool_run_running_lease_ck_v023;
ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_running_lease_ck_v023
    CHECK (state <> 'running' OR lease_expires_at IS NOT NULL) NOT VALID;

ALTER TABLE sand_ai_tool_run DROP CONSTRAINT IF EXISTS sand_ai_tool_run_running_payload_ck_v023;
ALTER TABLE sand_ai_tool_run
    ADD CONSTRAINT sand_ai_tool_run_running_payload_ck_v023
    CHECK (state <> 'running' OR (
        result IS NULL AND citations IS NULL AND review_required = false AND metering IS NULL
        AND error_code IS NULL AND error_detail IS NULL AND audit_log_id IS NULL AND completed_time IS NULL
    )) NOT VALID;

CREATE OR REPLACE FUNCTION sand_ai_tool_run_terminal_immutable_v023() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'tool run records are append-only' USING ERRCODE='55000'; END IF; IF OLD.state IN ('succeeded','failed','ambiguous') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'terminal tool run evidence is immutable' USING ERRCODE='55000'; END IF; RETURN NEW; END $$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_run_terminal_immutable_v023 ON sand_ai_tool_run;
CREATE TRIGGER trg_sand_ai_tool_run_terminal_immutable_v023 BEFORE UPDATE ON sand_ai_tool_run FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_run_terminal_immutable_v023();
DROP TRIGGER IF EXISTS trg_sand_ai_tool_run_delete_guard_v023 ON sand_ai_tool_run;
CREATE TRIGGER trg_sand_ai_tool_run_delete_guard_v023 BEFORE DELETE ON sand_ai_tool_run FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_run_terminal_immutable_v023();

CREATE OR REPLACE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'tool attempt records are append-only' USING ERRCODE='55000';
    END IF;
    IF ROW(OLD.tool_run_id,OLD.attempt_no,OLD.dispatch_fingerprint) IS DISTINCT FROM ROW(NEW.tool_run_id,NEW.attempt_no,NEW.dispatch_fingerprint) THEN
        RAISE EXCEPTION 'tool attempt identity is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.state IN ('succeeded','failed','ambiguous','cancelled') THEN
        IF NEW IS DISTINCT FROM OLD THEN
            RAISE EXCEPTION 'terminal tool attempt evidence is immutable' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'prepared' AND NEW.state = 'dispatched' THEN
        IF NEW.dispatched_time IS NULL OR NEW.http_status IS NOT NULL OR NEW.error_code IS NOT NULL OR NEW.completed_time IS NOT NULL THEN
            RAISE EXCEPTION 'prepared tool attempt dispatch transition is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'prepared' AND NEW.state = 'cancelled' THEN
        IF NEW.dispatched_time IS NOT NULL OR NEW.http_status IS NOT NULL OR NULLIF(btrim(NEW.error_code),'') IS NULL OR NEW.completed_time IS NULL THEN
            RAISE EXCEPTION 'prepared tool attempt cancellation is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'dispatched' AND NEW.state IN ('succeeded','failed','ambiguous') THEN
        IF OLD.dispatched_time IS NULL OR NEW.dispatched_time IS DISTINCT FROM OLD.dispatched_time OR NEW.completed_time IS NULL
            OR (NEW.state = 'succeeded' AND (NEW.error_code IS NOT NULL OR NEW.http_status IS NULL OR NEW.http_status < 200 OR NEW.http_status >= 300))
            OR (NEW.state IN ('failed','ambiguous') AND NULLIF(btrim(NEW.error_code),'') IS NULL) THEN
            RAISE EXCEPTION 'dispatched tool attempt terminal transition is invalid' USING ERRCODE='55000';
        END IF;
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'tool attempt state transition is invalid' USING ERRCODE='55000';
END;
$$;
DROP TRIGGER IF EXISTS trg_sand_ai_tool_attempt_terminal_immutable_v023 ON sand_ai_tool_attempt;
CREATE TRIGGER trg_sand_ai_tool_attempt_terminal_immutable_v023 BEFORE UPDATE ON sand_ai_tool_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023();
DROP TRIGGER IF EXISTS trg_sand_ai_tool_attempt_delete_guard_v023 ON sand_ai_tool_attempt;
CREATE TRIGGER trg_sand_ai_tool_attempt_delete_guard_v023 BEFORE DELETE ON sand_ai_tool_attempt FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_attempt_terminal_immutable_v023();
