-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_invocation
    ADD COLUMN knowledge_publication_id bigint NULL REFERENCES sand_ai_knowledge_publication(id),
    ADD COLUMN knowledge_base_version_id bigint NULL REFERENCES sand_ai_knowledge_base_version(id),
    ADD COLUMN knowledge_source_scope_sha256 char(64) NULL,
    ADD CONSTRAINT sand_ai_invocation_knowledge_answer_ck CHECK (
        (knowledge_publication_id IS NULL AND knowledge_base_version_id IS NULL AND knowledge_source_scope_sha256 IS NULL)
        OR (knowledge_publication_id IS NOT NULL AND knowledge_base_version_id IS NOT NULL
            AND knowledge_source_scope_sha256 ~ '^[a-f0-9]{64}$')
    );

CREATE INDEX sand_ai_invocation_knowledge_answer_idx
    ON sand_ai_invocation (knowledge_publication_id, knowledge_base_version_id, id)
    WHERE knowledge_publication_id IS NOT NULL;
