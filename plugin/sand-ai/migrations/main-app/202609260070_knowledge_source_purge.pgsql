-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_operation
    DROP CONSTRAINT sand_ai_knowledge_operation_operation_code_check;
ALTER TABLE sand_ai_knowledge_operation
    ADD CONSTRAINT sand_ai_knowledge_operation_operation_code_check
    CHECK (operation_code IN ('create_draft', 'publish', 'disable', 'purge_vectors', 'purge_sources'));

CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'DELETE' AND EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version v
        JOIN sand_ai_knowledge_base kb ON kb.id = v.knowledge_base_id
        WHERE v.id = OLD.knowledge_base_version_id
          AND v.state = 'published'
          AND kb.state = 'disabled'
          AND kb.status = 2
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_publication p
              WHERE p.knowledge_base_id = kb.id AND p.state = 'published'
          )
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_embedding_index i
              JOIN sand_ai_knowledge_base_version iv ON iv.id = i.knowledge_base_version_id
              WHERE iv.knowledge_base_id = kb.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM sand_ai_knowledge_conversation_turn t
              JOIN sand_ai_knowledge_conversation c ON c.id = t.conversation_id
              JOIN sand_ai_knowledge_publication p ON p.id = c.publication_id
              WHERE p.knowledge_base_id = kb.id AND t.state = 'pending'
          )
    ) THEN
        RETURN OLD;
    END IF;

    IF EXISTS (
        SELECT 1 FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) OR EXISTS (
        SELECT 1 FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(OLD.knowledge_base_version_id, NEW.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;
