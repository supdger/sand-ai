-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT sand_ai_knowledge_operation_operation_code_check;
ALTER TABLE sand_ai_knowledge_operation
    ADD CONSTRAINT sand_ai_knowledge_operation_operation_code_check
    CHECK (operation_code IN ('create_draft', 'publish', 'disable', 'purge_vectors'));
