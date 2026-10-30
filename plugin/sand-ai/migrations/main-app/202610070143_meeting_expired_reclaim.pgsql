-- Forward repair for applied 120. Keep its checksum, terminal proof and
-- terminal-step projection unchanged. A historical dispatch is safe to retain
-- only when its exact node success, request and usage were durably sealed.
CREATE OR REPLACE FUNCTION sand_ai_meeting_expired_reclaim_dispatch_sealed(dispatch_key bigint)
RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM sand_ai_invocation_dispatch d
        JOIN sand_ai_task_run t ON t.id=d.parent_task_run_id
        JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=t.id
        JOIN sand_ai_meeting_summary_node n ON n.task_id=t.id AND n.task_step_id=s.id
            AND n.node_code=d.workflow_node_code AND n.last_invocation_id=d.invocation_id
        JOIN sand_ai_invocation i ON i.id=d.invocation_id
        WHERE d.id=dispatch_key AND d.state='succeeded' AND d.execution_scope='ai_task'
          AND d.environment_id=t.environment_id AND n.dispatch_scope=d.execution_scope
          AND s.sequence_no>1 AND s.state='succeeded' AND s.step_code=n.node_code
          AND s.attempt_count>=1 AND s.status=1 AND s.delete_time IS NULL
          AND n.result_sha256 IS NOT NULL AND n.result_snapshot IS NOT NULL
          AND n.result_snapshot->>'invocation_id'=n.last_invocation_id::text
          AND n.result_snapshot->>'request_id'=format('task_%s_%s_attempt_%s',n.task_id,n.node_code,s.attempt_count)
          AND i.state='succeeded' AND i.environment_id=t.environment_id
          AND i.request_id=n.result_snapshot->>'request_id'
          AND EXISTS (SELECT 1 FROM sand_ai_usage_event u
              WHERE u.invocation_id=i.id AND u.environment_id=i.environment_id)
    );
$$;

-- Prepared alone proves neither terminal invocation nor released budget. Only
-- the durable zero-send closure may stop protecting its original reservations.
-- Failed provider outcomes and unknown dispatches never satisfy this proof.
CREATE OR REPLACE FUNCTION sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(dispatch_key bigint)
RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (
        SELECT 1 FROM sand_ai_invocation_dispatch d
        JOIN sand_ai_task_run t ON t.id=d.parent_task_run_id
        JOIN sand_ai_task_step s ON s.id=d.parent_task_step_id AND s.task_id=t.id
        JOIN sand_ai_meeting_summary_node n ON n.task_id=t.id AND n.task_step_id=s.id
            AND n.node_code=d.workflow_node_code
        JOIN sand_ai_invocation i ON i.id=d.invocation_id
        WHERE d.id=dispatch_key AND d.state='not_dispatched' AND d.execution_scope='ai_task'
          AND d.dispatch_start_time IS NULL AND d.dispatch_complete_time IS NOT NULL
          AND d.environment_id=t.environment_id AND n.dispatch_scope=d.execution_scope
          AND s.sequence_no>1 AND s.step_code=n.node_code AND s.status=1 AND s.delete_time IS NULL
          AND n.result_sha256 IS NULL AND n.result_snapshot IS NULL AND n.last_invocation_id IS NULL
          AND i.state='failed' AND i.completed_at IS NOT NULL AND i.environment_id=t.environment_id
          AND i.organization_id=t.organization_id AND i.application_id=t.application_id
          AND i.workload_client_id=t.workload_client_id AND d.application_id=t.application_id
          AND i.model_id=d.model_id AND i.deployment_id=d.deployment_id
          AND i.request_fingerprint ~ '^[a-f0-9]{64}$'
          AND d.logical_request_id=encode(sha256(convert_to(i.request_id,'UTF8')),'hex')
          AND NOT EXISTS (SELECT 1 FROM sand_ai_usage_event u WHERE u.invocation_id=i.id)
          AND NOT EXISTS (SELECT 1 FROM sand_ai_budget_reservation b
              WHERE b.invocation_id=i.id AND (b.state<>'released'
                  OR b.request_id IS DISTINCT FROM i.request_id
                  OR b.request_fingerprint IS DISTINCT FROM i.request_fingerprint
                  OR b.price_revision_id IS DISTINCT FROM i.call_start_price_revision_id))
          AND NOT EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch other
              WHERE other.environment_id=d.environment_id AND other.logical_execution_id=d.logical_execution_id
                AND other.id<>d.id AND (other.dispatch_start_time IS NOT NULL OR other.state<>'not_dispatched'))
    );
$$;

CREATE OR REPLACE FUNCTION sand_ai_meeting_terminal_capture() RETURNS trigger LANGUAGE plpgsql AS $$
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
               OR EXISTS (SELECT 1 FROM sand_ai_invocation_dispatch d
                   WHERE d.parent_task_run_id=OLD.id
                     AND NOT sand_ai_meeting_expired_reclaim_dispatch_sealed(d.id)
                     AND NOT sand_ai_meeting_expired_reclaim_dispatch_unsent_closed(d.id)) THEN
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
