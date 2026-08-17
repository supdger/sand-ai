-- PostgreSQL-only lexical retrieval index. Source content remains in the private
-- SandAI database; no public copy or external vector service is introduced.
CREATE INDEX IF NOT EXISTS idx_sand_ai_source_block_fts
    ON sand_ai_source_block
    USING GIN (to_tsvector('simple', content));

CREATE INDEX IF NOT EXISTS idx_sand_ai_file_environment_state
    ON sand_ai_file (environment_id, state, status, id);
