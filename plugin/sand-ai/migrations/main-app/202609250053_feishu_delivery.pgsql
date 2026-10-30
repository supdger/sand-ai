-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_feishu_delivery (
    task_id bigint PRIMARY KEY REFERENCES sand_ai_task_run(id),
    environment_id bigint NOT NULL,
    channel_binding_id bigint NOT NULL REFERENCES sand_ai_channel_binding(id),
    channel_code varchar(64) NOT NULL,
    channel_revision integer NOT NULL CHECK (channel_revision > 0),
    message_scope char(64) NOT NULL CHECK (message_scope ~ '^[a-f0-9]{64}$'),
    chat_envelope jsonb NOT NULL CHECK (jsonb_typeof(chat_envelope) = 'object'),
    state varchar(16) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','sending','sent','failed')),
    attempt_count smallint NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 5),
    available_at timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    lease_token varchar(64) NULL,
    lease_until timestamp(0) without time zone NULL,
    sent_message_scope char(64) NULL CHECK (sent_message_scope ~ '^[a-f0-9]{64}$'),
    error_code varchar(64) NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0)
);
CREATE INDEX sand_ai_feishu_delivery_ready_idx ON sand_ai_feishu_delivery(state,available_at,task_id)
    WHERE state IN ('pending','sending');
