-- Administrator-scope invocations carry no SandIAM workload client. Their
-- received provider identifiers belong to the same dispatch and must stay
-- observable; the binding trigger already compares the invocation value with
-- IS DISTINCT FROM, so the absent client remains bound, never guessed.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
ALTER TABLE sand_ai_provider_response_identity ALTER COLUMN workload_client_id DROP NOT NULL;
ALTER TABLE sand_ai_provider_response_identity DROP CONSTRAINT sand_ai_provider_response_identity_workload_client_id_check;
ALTER TABLE sand_ai_provider_response_identity ADD CONSTRAINT sand_ai_provider_identity_workload_client_v161
    CHECK (workload_client_id IS NULL OR workload_client_id > 0);
