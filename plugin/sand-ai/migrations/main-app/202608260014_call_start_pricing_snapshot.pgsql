ALTER TABLE sand_ai_invocation
    ADD COLUMN call_started_at timestamp(0) without time zone NULL,
    ADD COLUMN call_start_pricing_state varchar(16) NULL,
    ADD COLUMN call_start_price_revision_id bigint NULL,
    ADD COLUMN call_start_price_snapshot jsonb NULL;

ALTER TABLE sand_ai_invocation
    ADD CONSTRAINT fk_sand_ai_invocation_call_start_price_revision
        FOREIGN KEY (call_start_price_revision_id) REFERENCES sand_ai_price_revision(id) ON DELETE RESTRICT,
    ADD CONSTRAINT ck_sand_ai_invocation_call_start_pricing CHECK (
        (
            call_started_at IS NULL
            AND call_start_pricing_state IS NULL
            AND call_start_price_revision_id IS NULL
            AND call_start_price_snapshot IS NULL
        )
        OR
        (
            call_started_at IS NOT NULL
            AND call_start_pricing_state IN ('priced', 'unpriced')
            AND jsonb_typeof(call_start_price_snapshot) = 'object'
            AND call_start_price_snapshot -> 'snapshot_version' = to_jsonb(1)
            AND (
                (
                    call_start_pricing_state = 'priced'
                    AND call_start_price_revision_id IS NOT NULL
                    AND call_start_price_snapshot -> 'price_revision_id' = to_jsonb(call_start_price_revision_id)
                    AND jsonb_typeof(call_start_price_snapshot -> 'rates') = 'object'
                )
                OR
                (
                    call_start_pricing_state = 'unpriced'
                    AND call_start_price_revision_id IS NULL
                    AND jsonb_typeof(call_start_price_snapshot -> 'reason') = 'string'
                    AND length(call_start_price_snapshot ->> 'reason') > 0
                )
            )
        )
    );

CREATE OR REPLACE FUNCTION sand_ai_reject_call_start_pricing_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF ROW(
        NEW.call_started_at,
        NEW.call_start_pricing_state,
        NEW.call_start_price_revision_id,
        NEW.call_start_price_snapshot
    ) IS DISTINCT FROM ROW(
        OLD.call_started_at,
        OLD.call_start_pricing_state,
        OLD.call_start_price_revision_id,
        OLD.call_start_price_snapshot
    ) THEN
        RAISE EXCEPTION 'sand_ai_invocation call-start pricing snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_sand_ai_invocation_call_start_pricing_immutable
BEFORE UPDATE ON sand_ai_invocation
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_call_start_pricing_mutation();

COMMENT ON COLUMN sand_ai_invocation.call_start_price_snapshot IS
    'Versioned Provider-before pricing decision; contains rates or an explicit unpriced reason, never credentials.';
