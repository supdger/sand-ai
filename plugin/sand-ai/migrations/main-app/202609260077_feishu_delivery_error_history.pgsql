-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_feishu_channel_status
    ADD COLUMN last_delivery_error_code varchar(64) NULL,
    ADD COLUMN last_delivery_error_at timestamp(0) without time zone NULL,
    ADD CONSTRAINT sand_ai_feishu_channel_status_delivery_error_pair_ck
        CHECK ((last_delivery_error_code IS NULL) = (last_delivery_error_at IS NULL));
