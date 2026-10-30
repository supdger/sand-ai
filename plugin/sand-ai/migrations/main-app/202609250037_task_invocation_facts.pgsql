-- Persist SandIAM resource facts on asynchronous AI and agent tasks.
-- Historical rows stay nullable so the resolver can reject them without a guessed owner.
ALTER TABLE sand_ai_task_run
    ADD COLUMN IF NOT EXISTS organization_id bigint NULL,
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint NULL,
    ADD COLUMN IF NOT EXISTS data_class varchar(32) NULL;

ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_run_iam_scope_v037 CHECK (
        (organization_id IS NULL OR organization_id > 0)
        AND (application_id IS NULL OR application_id > 0)
        AND (workload_client_id IS NULL OR workload_client_id > 0)
        AND (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$')
    ) NOT VALID;
