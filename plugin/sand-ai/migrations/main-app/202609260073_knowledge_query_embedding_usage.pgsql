-- Consumer-owned query Embedding usage is separate from owner-owned index building.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT sand_ai_usage_event_source_kind_ck_v067;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck_v073
    CHECK (source_kind IN (
        'chat','ai_task','agent','capability','workflow','structured_workflow',
        'external_service','onboarding_verification','skill','skill_test','tool',
        'knowledge_index','knowledge_query'
    ));
