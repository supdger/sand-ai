-- Source-only migration. Apply only after explicit database authorization.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN state_version bigint NOT NULL DEFAULT 1,
    ADD COLUMN state_revision_txid bigint NULL;
UPDATE sand_ai_workflow_run SET state_revision_txid = txid_current();
ALTER TABLE sand_ai_workflow_run
    ALTER COLUMN state_revision_txid SET DEFAULT txid_current(),
    ALTER COLUMN state_revision_txid SET NOT NULL,
    ADD CONSTRAINT ck_sand_ai_workflow_run_state_version CHECK (state_version > 0);
