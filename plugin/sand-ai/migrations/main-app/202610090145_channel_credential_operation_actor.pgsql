-- Source-only migration; legacy operations retain unknown original ownership.
ALTER TABLE sand_ai_feishu_credential_operation
    ADD COLUMN created_by varchar(64),
    ADD CONSTRAINT sand_ai_feishu_credential_operation_actor_check
        CHECK (created_by IS NULL OR created_by ~ '^[1-9][0-9]{0,18}$');

ALTER TABLE sand_ai_wecom_credential_operation
    ADD COLUMN created_by varchar(64),
    ADD CONSTRAINT sand_ai_wecom_credential_operation_actor_check
        CHECK (created_by IS NULL OR created_by ~ '^[1-9][0-9]{0,18}$');
