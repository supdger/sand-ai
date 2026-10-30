-- Close the SQL NULL gap in the citation provenance invariant and align the
-- stored content hash with the API contract (lowercase SHA-256 hex, no URI).
ALTER TABLE sand_ai_tool_source
    DROP CONSTRAINT IF EXISTS sand_ai_tool_source_citation_check;

ALTER TABLE sand_ai_tool_source
    ADD CONSTRAINT sand_ai_tool_source_citation_check
    CHECK (
        NULLIF(btrim(source_version), '') IS NOT NULL
        OR (content_hash IS NOT NULL AND content_hash ~ '^[a-f0-9]{64}$')
    );
