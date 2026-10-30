-- Embedding uses the existing invocation, dispatch and rejection ledgers.
ALTER TABLE sand_ai_invocation_dispatch
    DROP CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v026;
ALTER TABLE sand_ai_invocation_dispatch
    ADD CONSTRAINT ck_sand_ai_invocation_dispatch_scope_v066
    CHECK (execution_scope IN ('legacy_unverified', 'chat', 'ai_task', 'agent', 'structured_workflow', 'skill', 'embedding')
        AND (execution_scope = 'legacy_unverified' OR (
            (execution_scope IN ('ai_task', 'agent', 'structured_workflow')) = (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL)
            AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
        ))) NOT VALID;

ALTER TABLE sand_ai_pre_dispatch_rejection
    DROP CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope;
ALTER TABLE sand_ai_pre_dispatch_rejection
    ADD CONSTRAINT ck_sand_ai_pre_dispatch_rejection_scope_v066 CHECK (
        execution_scope IN ('chat', 'ai_task', 'agent', 'structured_workflow', 'skill', 'embedding')
        AND attempt_no > 0
        AND ((execution_scope IN ('ai_task', 'agent', 'structured_workflow')) =
             (parent_task_run_id IS NOT NULL AND parent_task_step_id IS NOT NULL AND workflow_node_code IS NOT NULL))
        AND (execution_scope IN ('ai_task', 'agent', 'structured_workflow') OR
             (parent_task_run_id IS NULL AND parent_task_step_id IS NULL AND workflow_node_code IS NULL))
    );
