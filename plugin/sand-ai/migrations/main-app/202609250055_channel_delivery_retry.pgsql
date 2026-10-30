-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_channel_operation DROP CONSTRAINT sand_ai_channel_operation_operation_code_check;
ALTER TABLE sand_ai_channel_operation ADD CONSTRAINT sand_ai_channel_operation_operation_code_check
    CHECK (operation_code IN ('save', 'disable', 'retry_delivery'));
