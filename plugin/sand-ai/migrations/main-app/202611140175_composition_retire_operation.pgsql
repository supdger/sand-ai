-- 删除工作流要记一条 retire 操作。原检查只允许草稿、启用和停用。
ALTER TABLE sand_ai_composition_operation
    DROP CONSTRAINT IF EXISTS sand_ai_composition_operation_operation_check;
ALTER TABLE sand_ai_composition_operation
    DROP CONSTRAINT IF EXISTS ck_sand_ai_composition_operation_operation;
ALTER TABLE sand_ai_composition_operation
    ADD CONSTRAINT ck_sand_ai_composition_operation_operation
    CHECK (operation IN ('draft', 'publish', 'disable', 'retire'));
