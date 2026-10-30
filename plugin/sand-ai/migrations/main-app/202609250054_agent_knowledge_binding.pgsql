-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN knowledge_base_code varchar(64) NULL
    CONSTRAINT sand_ai_agent_version_knowledge_code_ck
        CHECK (knowledge_base_code ~ '^[a-z][a-z0-9_-]{0,63}$');
