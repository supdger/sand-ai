ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS lease_owner varchar(128),
    ADD COLUMN IF NOT EXISTS lease_token char(64),
    ADD COLUMN IF NOT EXISTS lease_fence integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS lease_expire_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS heartbeat_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS sand_ai_task_run_lease_claim_idx ON sand_ai_task_run (state, available_at, lease_expire_time);

ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS organization_id bigint,
    ADD COLUMN IF NOT EXISTS application_id bigint,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint,
    ADD COLUMN IF NOT EXISTS attribution_snapshot jsonb,
    ADD COLUMN IF NOT EXISTS idempotency_application_id bigint,
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48),
    ADD COLUMN IF NOT EXISTS execution_snapshot_version varchar(32),
    ADD COLUMN IF NOT EXISTS execution_snapshot jsonb,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64),
    ADD COLUMN IF NOT EXISTS retain_until timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ack_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ack_ref varchar(128);
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_application_idempotency_uq ON sand_ai_workflow_run (environment_id, idempotency_application_id, idempotency_key) WHERE idempotency_application_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_logical_execution_uq ON sand_ai_workflow_run (logical_execution_id) WHERE logical_execution_id IS NOT NULL;

ALTER TABLE sand_ai_workflow_run_file
    ADD COLUMN IF NOT EXISTS analysis_state varchar(32) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS attempt_count integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS failure_summary varchar(512),
    ADD COLUMN IF NOT EXISTS last_invocation_id bigint;
CREATE INDEX IF NOT EXISTS sand_ai_workflow_run_file_analysis_idx ON sand_ai_workflow_run_file (workflow_run_id, analysis_state);

ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS parent_workflow_run_id bigint,
    ADD COLUMN IF NOT EXISTS parent_task_id bigint,
    ADD COLUMN IF NOT EXISTS parent_task_step_id bigint,
    ADD COLUMN IF NOT EXISTS parent_workflow_run_file_id bigint,
    ADD COLUMN IF NOT EXISTS node_code varchar(64),
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48),
    ADD COLUMN IF NOT EXISTS execution_attempt integer,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64);
CREATE INDEX IF NOT EXISTS sand_ai_invocation_parent_workflow_idx ON sand_ai_invocation (parent_workflow_run_id, logical_execution_id);

CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_artifact_run_schema_uq ON sand_ai_artifact (workflow_run_id, schema_code, schema_version);
CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_artifact_workflow_schema_uniq
    ON sand_ai_artifact (workflow_run_id, schema_code, schema_version);
ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS parent_task_run_id bigint NULL,
    ADD COLUMN IF NOT EXISTS parent_task_step_id bigint NULL,
    ADD COLUMN IF NOT EXISTS parent_workflow_run_file_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workflow_node_code varchar(128) NULL,
    ADD COLUMN IF NOT EXISTS logical_execution_id char(48) NULL,
    ADD COLUMN IF NOT EXISTS execution_attempt_no integer NULL,
    ADD COLUMN IF NOT EXISTS execution_snapshot_sha256 char(64) NULL;

CREATE INDEX IF NOT EXISTS sand_ai_invocation_parent_task_run_idx
    ON sand_ai_invocation (parent_task_run_id, id);
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS idempotency_application_ref char(64) NULL;

ALTER TABLE sand_ai_workflow_run
    DROP CONSTRAINT IF EXISTS sand_ai_workflow_run_environment_id_idempotency_key_key;
DROP INDEX IF EXISTS sand_ai_workflow_run_environment_id_idempotency_key_key;

CREATE UNIQUE INDEX IF NOT EXISTS sand_ai_workflow_run_environment_application_idempotency_uniq
    ON sand_ai_workflow_run (environment_id, idempotency_application_ref, idempotency_key)
    WHERE idempotency_application_ref IS NOT NULL;
