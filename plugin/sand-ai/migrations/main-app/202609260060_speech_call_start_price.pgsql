-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_speech_run
    ADD COLUMN called_at timestamp(0) without time zone NULL,
    ADD COLUMN pricing_state varchar(16) NULL,
    ADD COLUMN price_revision_id bigint NULL REFERENCES sand_ai_price_revision(id),
    ADD COLUMN price_snapshot jsonb NULL;
ALTER TABLE sand_ai_speech_run
    ADD CONSTRAINT ck_sand_ai_speech_run_call_start_price CHECK (
        (dispatch_started_at IS NULL AND called_at IS NULL AND pricing_state IS NULL
            AND price_revision_id IS NULL AND price_snapshot IS NULL)
        OR (dispatch_started_at IS NOT NULL AND called_at IS NOT NULL
            AND jsonb_typeof(price_snapshot) = 'object'
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL)))
    );
