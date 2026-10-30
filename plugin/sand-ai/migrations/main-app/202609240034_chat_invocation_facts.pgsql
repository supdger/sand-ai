-- Persist SandIAM resource facts for the public chat invocation before authorization.
-- Historical invocations remain nullable and fail closed when read through the new resolver.
ALTER TABLE sand_ai_invocation
    ADD COLUMN IF NOT EXISTS organization_id bigint NULL,
    ADD COLUMN IF NOT EXISTS application_id bigint NULL,
    ADD COLUMN IF NOT EXISTS workload_client_id bigint NULL,
    ADD COLUMN IF NOT EXISTS data_class varchar(32) NULL;

ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT ck_sand_ai_invocation_iam_scope_v034 CHECK (
        (organization_id IS NULL OR organization_id > 0)
        AND (application_id IS NULL OR application_id > 0)
        AND (workload_client_id IS NULL OR workload_client_id > 0)
        AND (data_class IS NULL OR data_class ~ '^[a-z0-9][a-z0-9._-]{1,31}$')
    ) NOT VALID;
