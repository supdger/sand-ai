DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_workflow_run workflow
        LEFT JOIN sand_ai_invocation invocation ON invocation.id = workflow.last_invocation_id
        WHERE workflow.last_invocation_id IS NOT NULL AND invocation.id IS NULL
    ) THEN
        RAISE EXCEPTION 'sand_ai_workflow_run.last_invocation_id contains orphaned invocation references';
    END IF;
END
$$;

ALTER TABLE sand_ai_workflow_run
    ADD CONSTRAINT sand_ai_workflow_run_last_invocation_fk
    FOREIGN KEY (last_invocation_id) REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT;
