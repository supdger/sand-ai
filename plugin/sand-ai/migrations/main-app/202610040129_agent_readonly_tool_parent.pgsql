-- New versions and task-bound tool runs only; preserve historical snapshots and receipts.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN readonly_tools jsonb NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT ck_sand_ai_agent_version_readonly_tools
        CHECK (jsonb_typeof(readonly_tools) = 'array' AND jsonb_array_length(readonly_tools) <= 2);

ALTER TABLE sand_ai_task_step
    ADD CONSTRAINT uk_sand_ai_task_step_id_task UNIQUE (id, task_id);

ALTER TABLE sand_ai_tool_run
    ADD COLUMN parent_task_run_id bigint NULL REFERENCES sand_ai_task_run(id),
    ADD COLUMN parent_task_step_id bigint NULL,
    ADD COLUMN queued_authorization jsonb NULL,
    ADD CONSTRAINT fk_sand_ai_tool_run_parent_step
        FOREIGN KEY (parent_task_step_id, parent_task_run_id)
        REFERENCES sand_ai_task_step(id, task_id),
    ADD CONSTRAINT ck_sand_ai_tool_run_parent
        CHECK ((parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND queued_authorization IS NULL)
            OR (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL
                AND parent_task_run_id > 0 AND parent_task_step_id > 0
                AND (queued_authorization IS NULL OR jsonb_typeof(queued_authorization) = 'object')));

CREATE UNIQUE INDEX uk_sand_ai_tool_run_parent_step
    ON sand_ai_tool_run(parent_task_step_id) WHERE parent_task_step_id IS NOT NULL;
CREATE INDEX ix_sand_ai_tool_run_parent_task
    ON sand_ai_tool_run(parent_task_run_id, id) WHERE parent_task_run_id IS NOT NULL;

ALTER TABLE sand_ai_agent_operation
    DROP CONSTRAINT sand_ai_agent_operation_operation_code_check,
    ADD CONSTRAINT sand_ai_agent_operation_operation_code_check
        CHECK (operation_code IN ('draft', 'publish', 'disable', 'rollback'));

ALTER TABLE sand_ai_agent_stream_event
    DROP CONSTRAINT sand_ai_agent_stream_event_round_no_check,
    ADD CONSTRAINT sand_ai_agent_stream_event_round_no_check CHECK (round_no BETWEEN 1 AND 4);

-- Existing original request identity guards remain installed. Freeze the new parent and authorization evidence too.
CREATE FUNCTION sand_ai_agent_tool_parent_guard_v129() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(OLD.parent_task_run_id,OLD.parent_task_step_id) IS DISTINCT FROM ROW(NEW.parent_task_run_id,NEW.parent_task_step_id) THEN
        RAISE EXCEPTION 'agent tool parent evidence is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.queued_authorization IS NOT NULL AND NEW.queued_authorization IS DISTINCT FROM OLD.queued_authorization THEN
        RAISE EXCEPTION 'agent tool original authorization is immutable' USING ERRCODE='55000';
    END IF;
    IF OLD.queued_authorization IS NULL AND NEW.queued_authorization IS NOT NULL AND
        (OLD.state <> 'running' OR NOT EXISTS(SELECT 1 FROM sand_ai_tool_attempt a WHERE a.tool_run_id=OLD.id AND a.attempt_no=1 AND a.state='prepared')) THEN
        RAISE EXCEPTION 'agent tool original authorization must precede dispatch' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_agent_tool_parent_guard_v129 BEFORE UPDATE ON sand_ai_tool_run
    FOR EACH ROW EXECUTE FUNCTION sand_ai_agent_tool_parent_guard_v129();

CREATE FUNCTION sand_ai_agent_version_published_guard_v129() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.state='published' AND NEW IS DISTINCT FROM OLD THEN
        RAISE EXCEPTION 'published agent configuration is immutable' USING ERRCODE='55000';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_sand_ai_agent_version_published_guard_v129 BEFORE UPDATE ON sand_ai_agent_version
    FOR EACH ROW EXECUTE FUNCTION sand_ai_agent_version_published_guard_v129();
