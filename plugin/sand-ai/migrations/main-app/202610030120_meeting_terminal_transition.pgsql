-- New registered migration. Original 109 ledger and checksums remain unchanged.
-- A proof is captured BEFORE the parent clears its exact live lease, and is
-- usable only by failed/canceled step projection in that same transaction.
CREATE TABLE sand_ai_meeting_terminal_transition (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id) ON DELETE RESTRICT,
    transaction_id bigint NOT NULL,
    original_state varchar(32) NOT NULL,
    transition_mode varchar(32) NOT NULL CHECK (transition_mode IN ('live','expired_reclaim','inactive_cancel')),
    terminal_state varchar(32) NOT NULL CHECK (terminal_state IN ('failed','canceled')),
    original_fence bigint NOT NULL,
    terminal_fence bigint NOT NULL,
    worker_owner text NULL,
    token_sha256 char(64) NULL,
    lease_expire_time timestamp without time zone NULL,
    running_timezone text NOT NULL,
    PRIMARY KEY(task_id,transaction_id)
);
CREATE FUNCTION sand_ai_meeting_terminal_proof_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t sand_ai_task_run%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' OR pg_trigger_depth() <> 2 THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_IMMUTABLE';
    END IF;
    SELECT * INTO t FROM sand_ai_task_run WHERE id=NEW.task_id FOR UPDATE;
    IF NOT FOUND OR NEW.transaction_id <> txid_current()
       OR NEW.original_state IS DISTINCT FROM t.state
       OR NEW.original_fence <> t.lease_fence
       OR NEW.worker_owner IS DISTINCT FROM t.lease_owner
       OR NEW.token_sha256 IS DISTINCT FROM (CASE WHEN t.lease_token IS NULL THEN NULL ELSE encode(sha256(convert_to(t.lease_token,'UTF8')),'hex') END)
       OR NEW.lease_expire_time IS DISTINCT FROM t.lease_expire_time THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_terminal_proof_guard BEFORE INSERT OR UPDATE OR DELETE
    ON sand_ai_meeting_terminal_transition FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_terminal_proof_guard();
CREATE FUNCTION sand_ai_meeting_terminal_capture() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE zone text; mode text;
BEGIN
    IF OLD.state=NEW.state OR NEW.state NOT IN ('failed','canceled')
       OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.id) THEN RETURN NEW; END IF;
    zone:=current_setting('sand_ai.running_timezone',true);
    IF zone IS NULL OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone)
       OR OLD.status<>1 OR OLD.delete_time IS NOT NULL OR OLD.content_purged_time IS NOT NULL
       OR NEW.lease_owner IS NOT NULL OR NEW.lease_token IS NOT NULL OR NEW.lease_expire_time IS NOT NULL
       OR NEW.completed_at IS NULL
       OR ROW(NEW.input,NEW.environment_id,NEW.organization_id,NEW.application_id,NEW.workload_client_id,NEW.task_type)
            IS DISTINCT FROM ROW(OLD.input,OLD.environment_id,OLD.organization_id,OLD.application_id,OLD.workload_client_id,OLD.task_type) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROOF_INVALID';
    END IF;
    mode:='inactive_cancel';
    IF OLD.state='running' THEN
        mode:='live';
        IF NEW.state='failed' AND current_setting('sand_ai.meeting_terminal_mode',true)='expired_reclaim' THEN
            mode:='expired_reclaim';
            IF OLD.lease_expire_time IS NULL OR OLD.lease_expire_time > (clock_timestamp() AT TIME ZONE zone)
               OR OLD.lease_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
               OR OLD.lease_token IS DISTINCT FROM current_setting('sand_ai.worker_token',true)
               OR OLD.lease_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
               OR OLD.attempt_count < OLD.max_attempts
               OR EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch WHERE parent_task_run_id=OLD.id) THEN
                RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
            END IF;
        ELSE
            PERFORM sand_ai_meeting_worker_task(OLD.id);
        END IF;
    ELSIF NEW.state<>'canceled' OR OLD.state NOT IN ('authorization_pending','queued','retrying','recovery_pending')
        OR OLD.lease_owner IS NOT NULL OR OLD.lease_token IS NOT NULL OR OLD.lease_expire_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    IF NEW.lease_fence <> OLD.lease_fence + (CASE WHEN NEW.state='canceled' OR mode='expired_reclaim' THEN 1 ELSE 0 END) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    INSERT INTO sand_ai_meeting_terminal_transition VALUES(OLD.id,txid_current(),OLD.state,mode,NEW.state,
        OLD.lease_fence,NEW.lease_fence,OLD.lease_owner,
        CASE WHEN OLD.lease_token IS NULL THEN NULL ELSE encode(sha256(convert_to(OLD.lease_token,'UTF8')),'hex') END,
        OLD.lease_expire_time,zone);
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_meeting_terminal_capture BEFORE UPDATE ON sand_ai_task_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_meeting_terminal_capture();
CREATE FUNCTION sand_ai_meeting_terminal_step(task_key bigint, step_state text) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE p sand_ai_meeting_terminal_transition%ROWTYPE; t sand_ai_task_run%ROWTYPE;
BEGIN
    SELECT * INTO t FROM sand_ai_task_run WHERE id=task_key FOR SHARE;
    IF NOT FOUND OR t.state NOT IN ('failed','canceled') OR step_state IS DISTINCT FROM t.state THEN RETURN false; END IF;
    SELECT * INTO p FROM sand_ai_meeting_terminal_transition WHERE task_id=task_key AND transaction_id=txid_current();
    IF NOT FOUND OR p.terminal_state IS DISTINCT FROM t.state OR p.terminal_fence <> t.lease_fence
       OR t.status<>1 OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
       OR t.lease_owner IS NOT NULL OR t.lease_token IS NOT NULL OR t.lease_expire_time IS NOT NULL
       OR t.completed_at IS NULL THEN RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST'; END IF;
    IF p.original_state='running' AND (
        p.worker_owner IS DISTINCT FROM current_setting('sand_ai.worker_owner',true)
        OR p.token_sha256 IS DISTINCT FROM encode(sha256(convert_to(coalesce(current_setting('sand_ai.worker_token',true),''),'UTF8')),'hex')
        OR p.original_fence::text IS DISTINCT FROM current_setting('sand_ai.worker_fence',true)
        OR p.running_timezone IS DISTINCT FROM current_setting('sand_ai.running_timezone',true)
        OR p.lease_expire_time IS NULL
        OR (p.transition_mode='live' AND p.lease_expire_time <= (clock_timestamp() AT TIME ZONE p.running_timezone))
        OR (p.transition_mode='expired_reclaim' AND (p.lease_expire_time > (clock_timestamp() AT TIME ZONE p.running_timezone)
            OR current_setting('sand_ai.meeting_terminal_mode',true) IS DISTINCT FROM 'expired_reclaim'))) THEN
        RAISE EXCEPTION 'SAND_AI_TASK_LEASE_LOST';
    END IF;
    RETURN true;
END $$;
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='INSERT' THEN
        IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id) THEN RETURN NEW; END IF;
        -- sequence=1/invoke_model remains the existing parent lifecycle. All new
        -- node steps require the current task lease, before their node is inserted.
        IF NEW.sequence_no=1 AND NEW.step_code='invoke_model' THEN RETURN NEW; END IF;
        PERFORM sand_ai_meeting_worker_task(NEW.task_id);
        RETURN NEW;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=OLD.task_id)
       AND (TG_OP='DELETE' OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=NEW.task_id)) THEN
        RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
    END IF;
    IF TG_OP='DELETE' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    IF ROW(NEW.id,NEW.task_id,NEW.step_code,NEW.sequence_no,NEW.create_time)
       IS DISTINCT FROM ROW(OLD.id,OLD.task_id,OLD.step_code,OLD.sequence_no,OLD.create_time) THEN
        RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE';
    END IF;
    -- Exact no-op is safe even after terminal state, lease expiry or legal purge.
    IF to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NEW; END IF;
    IF OLD.sequence_no=1 AND OLD.step_code='invoke_model' THEN RETURN NEW; END IF;
    IF OLD.state='succeeded' THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
    IF NEW.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(NEW.task_id,NEW.state) THEN
        IF (to_jsonb(NEW)-ARRAY['state','error_code','error_summary','completed_at','update_time'])
            IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','error_code','error_summary','completed_at','update_time'])
           OR NEW.completed_at IS NULL THEN RAISE EXCEPTION 'SAND_AI_MEETING_NODE_STEP_IMMUTABLE'; END IF;
        RETURN NEW;
    END IF;
    -- No purge exemption for pending-step writes: cleanup does not alter steps.
    PERFORM sand_ai_meeting_worker_task(NEW.task_id);
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION sand_ai_meeting_worker_step_seal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s sand_ai_task_step%ROWTYPE; n sand_ai_meeting_summary_node%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' AND to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN RETURN NULL; END IF;
    SELECT * INTO s FROM sand_ai_task_step WHERE id=NEW.id FOR SHARE;
    IF NOT FOUND OR NOT EXISTS (SELECT 1 FROM sand_ai_meeting_worker_checkpoint WHERE task_id=s.task_id) THEN RETURN NULL; END IF;
    IF s.sequence_no=1 AND s.step_code='invoke_model' THEN RETURN NULL; END IF;
    IF s.state IN ('failed','canceled') AND sand_ai_meeting_terminal_step(s.task_id,s.state) THEN
        IF EXISTS (SELECT 1 FROM sand_ai_task_step WHERE task_id=s.task_id AND state NOT IN ('succeeded',s.state))
           OR EXISTS (SELECT 1 FROM sand_ai_meeting_summary_node n JOIN sand_ai_task_step child ON child.id=n.task_step_id
               WHERE n.task_id=s.task_id AND n.result_sha256 IS NOT NULL AND child.state<>'succeeded') THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_TERMINAL_PROJECTION_INVALID';
        END IF;
        RETURN NULL;
    END IF;
    -- Lease/cleanup boundary is repeated at commit after any node locks, even
    -- when the changed step is still pending. No late status projection survives.
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    IF s.state='succeeded' THEN
        SELECT * INTO n FROM sand_ai_meeting_summary_node WHERE task_id=s.task_id AND task_step_id=s.id FOR SHARE;
        IF NOT FOUND OR n.node_code IS DISTINCT FROM s.step_code THEN
            RAISE EXCEPTION 'SAND_AI_MEETING_NODE_RESULT_UNOWNED';
        END IF;
        PERFORM sand_ai_meeting_worker_assert_node_sealed(n.id);
    END IF;
    PERFORM sand_ai_meeting_worker_task(s.task_id);
    RETURN NULL;
END $$;
