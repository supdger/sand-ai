-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_knowledge_conversation_turn
    ADD COLUMN rerank boolean NOT NULL DEFAULT false;
