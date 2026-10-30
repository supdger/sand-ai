-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE TABLE sand_ai_feishu_channel_status (
    channel_binding_id bigint PRIMARY KEY REFERENCES sand_ai_channel_binding(id),
    environment_id bigint NOT NULL,
    last_verified_callback_at timestamp(0) without time zone NULL,
    last_accepted_message_at timestamp(0) without time zone NULL,
    last_callback_error_code varchar(64) NULL,
    last_callback_error_at timestamp(0) without time zone NULL,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    CHECK ((last_callback_error_code IS NULL) = (last_callback_error_at IS NULL))
);
CREATE INDEX sand_ai_feishu_channel_status_environment_idx ON sand_ai_feishu_channel_status(environment_id);
CREATE INDEX sand_ai_feishu_delivery_binding_latest_idx
    ON sand_ai_feishu_delivery(environment_id,channel_binding_id,task_id DESC);
