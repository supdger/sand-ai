CREATE TABLE IF NOT EXISTS sand_ai_invocation_dispatch (
    id bigserial PRIMARY KEY,
    invocation_id bigint NOT NULL UNIQUE REFERENCES sand_ai_invocation(id),
    environment_id bigint NOT NULL,
    logical_execution_id char(48) NOT NULL,
    logical_request_id char(64) NOT NULL,
    parent_workflow_run_id bigint NULL,
    parent_task_run_id bigint NULL,
    parent_task_step_id bigint NULL,
    parent_workflow_run_file_id bigint NULL,
    workflow_node_code varchar(128) NULL,
    attempt_no integer NOT NULL DEFAULT 1 CHECK (attempt_no > 0),
    state varchar(32) NOT NULL CHECK (state IN ('prepared','dispatching','succeeded','not_dispatched','ambiguous','failed')),
    provider_id bigint NOT NULL,
    deployment_id bigint NOT NULL,
    model_id bigint NOT NULL,
    capability_code varchar(64) NOT NULL,
    route_snapshot jsonb NOT NULL,
    price_snapshot jsonb NOT NULL,
    execution_snapshot_sha256 char(64) NOT NULL,
    request_body_digest char(64) NOT NULL,
    provider_idempotency_mode varchar(32) NOT NULL CHECK (provider_idempotency_mode IN ('none','provider_key')),
    provider_idempotency_key char(64) NULL,
    lease_owner varchar(128) NOT NULL,
    lease_token char(64) NOT NULL,
    lease_fence integer NOT NULL CHECK (lease_fence > 0),
    dispatch_start_time timestamp(0) without time zone NULL,
    dispatch_complete_time timestamp(0) without time zone NULL,
    ambiguous_time timestamp(0) without time zone NULL,
    error_code varchar(128) NULL,
    error_summary varchar(512) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_dispatch_environment_state_idx ON sand_ai_invocation_dispatch(environment_id, state, create_time);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_dispatch_task_idx ON sand_ai_invocation_dispatch(parent_task_run_id, lease_fence);

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('succeeded','not_dispatched','failed') THEN
        RAISE EXCEPTION 'terminal SandAI dispatch cannot be overwritten';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS sand_ai_invocation_dispatch_terminal_guard ON sand_ai_invocation_dispatch;
CREATE TRIGGER sand_ai_invocation_dispatch_terminal_guard BEFORE UPDATE ON sand_ai_invocation_dispatch
FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite();
