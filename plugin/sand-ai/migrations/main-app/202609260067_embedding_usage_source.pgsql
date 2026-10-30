-- Private knowledge indexing is an environment-owned Embedding usage source.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT sand_ai_usage_event_source_kind_ck;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT sand_ai_usage_event_source_kind_ck_v067
    CHECK (source_kind IN (
        'chat','ai_task','agent','capability','workflow','structured_workflow',
        'external_service','onboarding_verification','skill','skill_test','tool','knowledge_index'
    ));
