-- SAND-140. Additive only: retain 008/025 checksums and fail before any
-- constraint is validated when historical ledger ownership is inconsistent.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation_dispatch dispatch
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = dispatch.invocation_id
        WHERE invocation.id IS NULL
            OR invocation.environment_id IS DISTINCT FROM dispatch.environment_id
            OR invocation.model_id IS DISTINCT FROM dispatch.model_id
            OR invocation.deployment_id IS DISTINCT FROM dispatch.deployment_id
    ) THEN
        RAISE EXCEPTION 'SAND_AI_DISPATCH_INVOCATION_MISMATCH';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM sand_ai_invocation_dispatch dispatch
        LEFT JOIN sand_ai_task_run task ON task.id = dispatch.parent_task_run_id
        LEFT JOIN sand_ai_task_step step ON step.id = dispatch.parent_task_step_id AND step.task_id = dispatch.parent_task_run_id
        WHERE (dispatch.parent_task_run_id IS NULL) IS DISTINCT FROM (dispatch.parent_task_step_id IS NULL)
           OR (dispatch.parent_task_run_id IS NOT NULL
          AND (task.id IS NULL OR step.id IS NULL
               OR task.environment_id IS DISTINCT FROM dispatch.environment_id)
          )
    ) THEN
        RAISE EXCEPTION 'SAND_AI_DISPATCH_TASK_SCOPE_MISMATCH';
    END IF;
END $$;

ALTER TABLE sand_ai_invocation_dispatch
    ADD COLUMN IF NOT EXISTS execution_scope varchar(32) NOT NULL DEFAULT 'legacy_unverified',
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS service_action varchar(96) NULL,
    ADD COLUMN IF NOT EXISTS source_kind varchar(32) NULL,
    ADD COLUMN IF NOT EXISTS skill_id bigint NULL,
    ADD COLUMN IF NOT EXISTS skill_version_id bigint NULL,
    ADD COLUMN IF NOT EXISTS skill_run_id bigint NULL;

ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT uq_sand_ai_task_run_id_environment_v026 UNIQUE (id, environment_id);
ALTER TABLE sand_ai_task_step
    ADD CONSTRAINT uq_sand_ai_task_step_id_task_v026 UNIQUE (id, task_id);

ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026
    CHECK (execution_scope IN ('legacy_unverified', 'chat', 'ai_task', 'agent', 'structured_workflow', 'skill')
        AND (execution_scope = 'legacy_unverified' OR (
            (execution_scope IN ('ai_task', 'agent', 'structured_workflow')) = (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL)
            AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
        ))) NOT VALID;

CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_ai_invocation_dispatch_task_scope_attempt_v026
    ON sand_ai_invocation_dispatch (execution_scope, parent_task_run_id, parent_task_step_id, workflow_node_code, attempt_no)
    WHERE execution_scope IN ('ai_task', 'agent');
CREATE INDEX IF NOT EXISTS idx_sand_ai_invocation_dispatch_ambiguous_v026
    ON sand_ai_invocation_dispatch (environment_id, execution_scope, create_time)
    WHERE state = 'ambiguous';

ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT fk_sand_ai_invocation_dispatch_task_environment_v026
        FOREIGN KEY (parent_task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID,
    ADD CONSTRAINT fk_sand_ai_invocation_dispatch_task_step_v026
        FOREIGN KEY (parent_task_step_id, parent_task_run_id) REFERENCES sand_ai_task_step(id, task_id) NOT VALID;

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_terminal_overwrite() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('succeeded', 'not_dispatched', 'failed', 'ambiguous') THEN
        RAISE EXCEPTION 'terminal SandAI dispatch cannot be overwritten';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_state_transition_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IS DISTINCT FROM NEW.state
       AND NOT ((OLD.state = 'prepared' AND NEW.state IN ('dispatching', 'not_dispatched'))
                OR (OLD.state = 'dispatching' AND NEW.state IN ('succeeded', 'failed', 'ambiguous'))) THEN
        RAISE EXCEPTION 'invalid SandAI dispatch state transition: % -> %', OLD.state, NEW.state;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sand_ai_invocation_dispatch_state_transition_v026 ON sand_ai_invocation_dispatch;
CREATE TRIGGER trg_sand_ai_invocation_dispatch_state_transition_v026
    BEFORE UPDATE ON sand_ai_invocation_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_state_transition_v026();

CREATE OR REPLACE FUNCTION sand_ai_invocation_dispatch_no_provider_visible_delete_v026() RETURNS trigger AS $$
BEGIN
    IF OLD.state IN ('dispatching', 'succeeded', 'failed', 'ambiguous') THEN
        RAISE EXCEPTION 'provider-visible SandAI dispatch cannot be deleted';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_sand_ai_invocation_dispatch_no_provider_visible_delete_v026 ON sand_ai_invocation_dispatch;
CREATE TRIGGER trg_sand_ai_invocation_dispatch_no_provider_visible_delete_v026
    BEFORE DELETE ON sand_ai_invocation_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_invocation_dispatch_no_provider_visible_delete_v026();

ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026;
ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT fk_sand_ai_invocation_dispatch_task_environment_v026;
ALTER TABLE sand_ai_invocation_dispatch VALIDATE CONSTRAINT fk_sand_ai_invocation_dispatch_task_step_v026;
ALTER TABLE sand_ai_invocation_dispatch ALTER COLUMN execution_scope DROP DEFAULT;
