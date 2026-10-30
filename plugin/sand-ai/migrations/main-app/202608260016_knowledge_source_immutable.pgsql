CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable() RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER sand_ai_knowledge_source_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_knowledge_base_source
    FOR EACH ROW EXECUTE FUNCTION sand_ai_knowledge_source_immutable();
