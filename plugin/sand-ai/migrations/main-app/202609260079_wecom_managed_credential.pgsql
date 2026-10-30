-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_wecom_channel_credential (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    code varchar(64) NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_-]{0,63}$'),
    credential_envelope jsonb NOT NULL CHECK (jsonb_typeof(credential_envelope) = 'object'),
    updated_by varchar(64) NOT NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_wecom_channel_credential_environment_idx ON sand_ai_wecom_channel_credential(environment_id);

CREATE TABLE sand_ai_wecom_credential_operation (
    environment_id bigint NOT NULL,
    idempotency_key varchar(128) NOT NULL,
    request_fingerprint char(64) NOT NULL CHECK (request_fingerprint ~ '^[a-f0-9]{64}$'),
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    response_snapshot jsonb NOT NULL CHECK (jsonb_typeof(response_snapshot) = 'object'),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (environment_id, idempotency_key)
);
