ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD COLUMN IF NOT EXISTS consumer_ack_time timestamp(0) without time zone,
    ADD COLUMN IF NOT EXISTS consumer_ref varchar(128),
    ADD COLUMN IF NOT EXISTS retain_until timestamp(0) without time zone;

ALTER TABLE sand_ai_invocation_outcome_receipt
    DROP CONSTRAINT IF EXISTS sand_ai_outcome_receipt_consumer_ack_check;

ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check CHECK (
        (consumer_ack_time IS NULL AND consumer_ref IS NULL AND retain_until IS NULL)
        OR
        (consumer_ack_time IS NOT NULL
            AND consumer_ref ~ '^artifact:[1-9][0-9]*$'
            AND retain_until IS NOT NULL
            AND retain_until > consumer_ack_time)
    );

CREATE INDEX IF NOT EXISTS sand_ai_invocation_outcome_receipt_artifact_retention_idx
    ON sand_ai_invocation_outcome_receipt (retain_until, id)
    WHERE state IN ('available', 'expired') AND consumer_ack_time IS NOT NULL;
-- B2C terminal Artifact acknowledgement must retain the producing Invocation on
-- the owning workflow run. This is nullable for pre-recovery rows.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN IF NOT EXISTS last_invocation_id bigint NULL;
