-- 启用和删除知识库都要留下操作记录。原来的检查没有这两项，写入会被数据库拒绝。
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT IF EXISTS sand_ai_knowledge_operation_operation_code_check;
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT IF EXISTS ck_sand_ai_knowledge_operation_operation_code;
ALTER TABLE sand_ai_knowledge_operation
    ADD CONSTRAINT ck_sand_ai_knowledge_operation_operation_code
    CHECK (operation_code IN ('create_draft', 'publish', 'disable', 'purge_vectors', 'purge_sources', 'enable', 'retire'));
