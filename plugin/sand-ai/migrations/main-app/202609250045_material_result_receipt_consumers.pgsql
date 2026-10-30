ALTER TABLE sand_ai_invocation_outcome_receipt
    DROP CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check;

ALTER TABLE sand_ai_invocation_outcome_receipt
    ADD CONSTRAINT sand_ai_outcome_receipt_consumer_ack_check CHECK (
        (consumer_ack_time IS NULL AND consumer_ref IS NULL AND retain_until IS NULL)
        OR
        (consumer_ack_time IS NOT NULL
            AND consumer_ref IS NOT NULL
            AND consumer_ref ~ '^(artifact|file_result|cross_file_review):[1-9][0-9]*$'
            AND retain_until IS NOT NULL
            AND retain_until > consumer_ack_time)
    );
