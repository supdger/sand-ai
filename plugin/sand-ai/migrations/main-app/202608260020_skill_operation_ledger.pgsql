-- SAND-134A operation-ledger follow-up. 019 owns the base table because it
-- hardens 000-018 deployments where that ledger did not yet exist.
ALTER TABLE sand_ai_skill_operation
    ADD CONSTRAINT ck_sand_ai_skill_operation_key_v020
    CHECK (length(idempotency_key) BETWEEN 1 AND 128) NOT VALID;
