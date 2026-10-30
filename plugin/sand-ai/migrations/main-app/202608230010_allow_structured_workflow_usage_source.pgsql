-- Structured workflows persist `structured_workflow` as their trusted source
-- kind. Keep the immutable usage-event vocabulary aligned with that runtime
-- contract without changing already-applied migration checksums.
ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_source_kind;

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_source_kind CHECK (
        source_kind IN (
            'chat',
            'ai_task',
            'agent',
            'capability',
            'workflow',
            'structured_workflow',
            'external_service',
            'onboarding_verification'
        )
    );
