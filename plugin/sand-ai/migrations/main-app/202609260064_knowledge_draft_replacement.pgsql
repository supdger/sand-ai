-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_base_version
    DROP CONSTRAINT sand_ai_knowledge_base_version_state_check;
ALTER TABLE sand_ai_knowledge_base_version
    ADD CONSTRAINT sand_ai_knowledge_base_version_state_check
    CHECK (state IN ('draft', 'published', 'abandoned'));
