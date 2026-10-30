-- New writes only; historical price operations have no inferred ownership or receipt.
CREATE TABLE sand_ai_pricing_operation (
    id bigserial PRIMARY KEY,
    actor_id bigint NOT NULL CHECK (actor_id > 0),
    kind varchar(16) NOT NULL CHECK (kind IN ('save','publish')),
    request_key varchar(128) NOT NULL CHECK (request_key ~ '^[A-Za-z0-9._:-]{1,128}$'),
    input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[a-f0-9]{64}$'),
    provider_id bigint NOT NULL REFERENCES sand_ai_provider(id),
    model_id bigint NOT NULL REFERENCES sand_ai_model(id),
    capability_code varchar(64) NOT NULL,
    target_id bigint REFERENCES sand_ai_price_revision(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    create_time timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (actor_id,kind,request_key),
    CHECK (kind <> 'publish' OR target_id IS NOT NULL)
);
CREATE FUNCTION sand_ai_pricing_operation_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SandAI price operation receipts are immutable';
END;
$$;
CREATE TRIGGER sand_ai_pricing_operation_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_pricing_operation
    FOR EACH ROW EXECUTE FUNCTION sand_ai_pricing_operation_immutable();
