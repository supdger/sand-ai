-- SAND-133A hardening.  This migration deliberately leaves 015/016 checksums
-- untouched and upgrades their trigger semantics in place.

CREATE OR REPLACE FUNCTION sand_ai_knowledge_source_immutable()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(NEW.knowledge_base_version_id, OLD.knowledge_base_version_id)
          AND state = 'published'
    ) OR EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = COALESCE(OLD.knowledge_base_version_id, NEW.knowledge_base_version_id)
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'published knowledge sources are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

DROP TRIGGER IF EXISTS sand_ai_knowledge_source_immutable ON sand_ai_knowledge_base_source;
CREATE TRIGGER sand_ai_knowledge_source_immutable
BEFORE INSERT OR UPDATE OR DELETE ON sand_ai_knowledge_base_source
FOR EACH ROW EXECUTE FUNCTION sand_ai_knowledge_source_immutable();

CREATE OR REPLACE FUNCTION sand_ai_reject_published_source_block_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_source s
        INNER JOIN sand_ai_knowledge_base_version v ON v.id = s.knowledge_base_version_id
        WHERE s.source_block_id = OLD.id
          AND v.state = 'published'
    ) AND (
        TG_OP = 'DELETE'
        OR OLD.file_id IS DISTINCT FROM NEW.file_id
        OR OLD.parse_id IS DISTINCT FROM NEW.parse_id
        OR OLD.sequence_no IS DISTINCT FROM NEW.sequence_no
        OR OLD.locator_type IS DISTINCT FROM NEW.locator_type
        OR OLD.locator IS DISTINCT FROM NEW.locator
        OR OLD.content IS DISTINCT FROM NEW.content
        OR OLD.content_sha256 IS DISTINCT FROM NEW.content_sha256
    ) THEN
        RAISE EXCEPTION 'published knowledge source blocks are immutable';
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER sand_ai_published_source_block_immutable
BEFORE UPDATE OR DELETE ON sand_ai_source_block
FOR EACH ROW EXECUTE FUNCTION sand_ai_reject_published_source_block_mutation();

CREATE UNIQUE INDEX sand_ai_knowledge_base_version_one_draft_idx
    ON sand_ai_knowledge_base_version (knowledge_base_id)
    WHERE state = 'draft';

ALTER TABLE sand_ai_knowledge_base_version
    ADD CONSTRAINT sand_ai_knowledge_base_version_id_base_key UNIQUE (id, knowledge_base_id);

ALTER TABLE sand_ai_knowledge_base
    DROP CONSTRAINT sand_ai_knowledge_base_active_version_fk,
    ADD CONSTRAINT sand_ai_knowledge_base_active_version_fk
        FOREIGN KEY (active_version_id, id)
        REFERENCES sand_ai_knowledge_base_version (id, knowledge_base_id)
        DEFERRABLE INITIALLY DEFERRED;

CREATE OR REPLACE FUNCTION sand_ai_enforce_active_knowledge_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.active_version_id IS NULL THEN
        IF NEW.state = 'published' THEN
            RAISE EXCEPTION 'published knowledge base requires an active version';
        END IF;
        RETURN NEW;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM sand_ai_knowledge_base_version
        WHERE id = NEW.active_version_id
          AND knowledge_base_id = NEW.id
          AND state = 'published'
    ) THEN
        RAISE EXCEPTION 'active knowledge version must belong to the base and be published';
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER sand_ai_knowledge_base_active_version_guard
AFTER INSERT OR UPDATE OF active_version_id, state ON sand_ai_knowledge_base
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION sand_ai_enforce_active_knowledge_version();
