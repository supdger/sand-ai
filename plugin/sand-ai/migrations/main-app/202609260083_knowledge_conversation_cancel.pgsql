-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    DROP CONSTRAINT sand_ai_knowledge_conversation_turn_state_check;
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD CONSTRAINT sand_ai_knowledge_conversation_turn_state_check
    CHECK (state IN ('pending', 'answered', 'needs_review', 'no_sources', 'failed', 'cancelled'));
