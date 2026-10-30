-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_agent_version
    ADD COLUMN context_turn_limit smallint NOT NULL DEFAULT 2
    CONSTRAINT sand_ai_agent_version_context_turn_limit_ck
    CHECK (context_turn_limit BETWEEN 0 AND 4);
