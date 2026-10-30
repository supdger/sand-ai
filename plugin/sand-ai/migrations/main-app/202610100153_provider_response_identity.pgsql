-- Received identifiers are observations, never usage, payment or billing matches.
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';
CREATE TABLE sand_ai_provider_response_identity (
    id bigserial PRIMARY KEY,
    invocation_id bigint NOT NULL REFERENCES sand_ai_invocation(id) ON DELETE RESTRICT,
    dispatch_id bigint NOT NULL REFERENCES sand_ai_invocation_dispatch(id) ON DELETE RESTRICT,
    organization_id bigint NOT NULL CHECK (organization_id > 0),
    application_id bigint NOT NULL CHECK (application_id > 0),
    environment_id bigint NOT NULL CHECK (environment_id > 0),
    workload_client_id bigint NOT NULL CHECK (workload_client_id > 0),
    provider_id bigint NOT NULL CHECK (provider_id > 0),
    model_id bigint NOT NULL CHECK (model_id > 0),
    deployment_id bigint NOT NULL CHECK (deployment_id > 0),
    attempt_no integer NOT NULL CHECK (attempt_no > 0),
    execution_snapshot_sha256 char(64) NOT NULL CHECK (execution_snapshot_sha256 ~ '^[a-f0-9]{64}$'),
    request_body_digest char(64) NOT NULL CHECK (request_body_digest ~ '^[a-f0-9]{64}$'),
    identity_kind varchar(16) NOT NULL,
    source varchar(32) NOT NULL,
    identifier varchar(128) COLLATE "C" NOT NULL CHECK (identifier ~ '^[A-Za-z0-9_.:-]{1,128}$'),
    received_time timestamp(0) without time zone NOT NULL DEFAULT (clock_timestamp() AT TIME ZONE 'UTC'),
    CONSTRAINT sand_ai_provider_identity_source_v153 CHECK (
        (identity_kind='request_id' AND source IN ('header.x-request-id','header.request-id'))
        OR (identity_kind='response_id' AND source IN ('body.id','stream.id'))),
    CONSTRAINT sand_ai_provider_identity_value_v153 UNIQUE(dispatch_id,source,identifier)
);
CREATE INDEX sand_ai_provider_identity_invocation_v153 ON sand_ai_provider_response_identity(invocation_id,id);

CREATE FUNCTION sand_ai_provider_identity_binding_v153() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    invocation sand_ai_invocation%ROWTYPE;
    dispatch sand_ai_invocation_dispatch%ROWTYPE;
BEGIN
    SELECT d.* INTO STRICT dispatch FROM sand_ai_invocation_dispatch d WHERE d.id=NEW.dispatch_id FOR UPDATE;
    SELECT i.* INTO STRICT invocation FROM sand_ai_invocation i WHERE i.id=dispatch.invocation_id;
    IF NEW.invocation_id IS DISTINCT FROM invocation.id
      OR NEW.organization_id IS DISTINCT FROM invocation.organization_id
      OR NEW.application_id IS DISTINCT FROM invocation.application_id
      OR NEW.environment_id IS DISTINCT FROM invocation.environment_id
      OR NEW.workload_client_id IS DISTINCT FROM invocation.workload_client_id
      OR NEW.provider_id IS DISTINCT FROM dispatch.provider_id
      OR NEW.model_id IS DISTINCT FROM dispatch.model_id OR NEW.model_id IS DISTINCT FROM invocation.model_id
      OR NEW.deployment_id IS DISTINCT FROM dispatch.deployment_id OR NEW.deployment_id IS DISTINCT FROM invocation.deployment_id
      OR NEW.environment_id IS DISTINCT FROM dispatch.environment_id
      OR NEW.attempt_no IS DISTINCT FROM dispatch.attempt_no
      OR NEW.execution_snapshot_sha256 IS DISTINCT FROM dispatch.execution_snapshot_sha256
      OR NEW.request_body_digest IS DISTINCT FROM dispatch.request_body_digest
      OR dispatch.dispatch_start_time IS NULL OR dispatch.state IN ('prepared','not_dispatched') THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_IDENTITY_BINDING_INVALID';
    END IF;
    IF (SELECT count(*) FROM sand_ai_provider_response_identity r WHERE r.dispatch_id=dispatch.id)>=8
      OR (SELECT count(*) FROM sand_ai_provider_response_identity r WHERE r.dispatch_id=dispatch.id AND r.source=NEW.source)>=2 THEN
        RAISE EXCEPTION 'SAND_AI_PROVIDER_IDENTITY_LIMIT_EXCEEDED';
    END IF;
    -- Ignore any supplied timestamp; receive time is always generated here.
    NEW.received_time := clock_timestamp() AT TIME ZONE 'UTC';
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_provider_identity_binding_v153 BEFORE INSERT ON sand_ai_provider_response_identity
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_identity_binding_v153();

CREATE FUNCTION sand_ai_provider_identity_immutable_v153() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'SandAI received provider identifiers are append-only';
END $$;
CREATE TRIGGER sand_ai_provider_identity_immutable_v153 BEFORE UPDATE OR DELETE ON sand_ai_provider_response_identity
    FOR EACH ROW EXECUTE FUNCTION sand_ai_provider_identity_immutable_v153();
