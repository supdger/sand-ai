
ALTER TABLE sand_ai_price_revision
    ADD COLUMN IF NOT EXISTS revision integer NULL,
    ADD COLUMN IF NOT EXISTS name varchar(128) NULL;

WITH existing_revision_max AS (
    SELECT
        provider_id,
        model_id,
        capability_code,
        COALESCE(MAX(revision), 0) AS max_revision
    FROM sand_ai_price_revision
    GROUP BY provider_id, model_id, capability_code
), numbered_null_revision AS (
    SELECT
        revision_target.id,
        existing_revision_max.max_revision + ROW_NUMBER() OVER (
            PARTITION BY revision_target.provider_id, revision_target.model_id, revision_target.capability_code
            ORDER BY revision_target.effective_from NULLS FIRST, revision_target.id
        ) AS next_revision
    FROM sand_ai_price_revision AS revision_target
    INNER JOIN existing_revision_max
        ON existing_revision_max.provider_id = revision_target.provider_id
        AND existing_revision_max.model_id = revision_target.model_id
        AND existing_revision_max.capability_code = revision_target.capability_code
    WHERE revision_target.revision IS NULL
)
UPDATE sand_ai_price_revision AS target
SET revision = numbered_null_revision.next_revision
FROM numbered_null_revision
WHERE target.id = numbered_null_revision.id;

UPDATE sand_ai_price_revision
SET name = '未命名价格'
WHERE name IS NULL;

ALTER TABLE sand_ai_price_revision
    ALTER COLUMN revision SET NOT NULL,
    ALTER COLUMN revision SET DEFAULT 1,
    ALTER COLUMN name SET NOT NULL,
    ALTER COLUMN name SET DEFAULT '未命名价格';

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_ai_price_revision_version
    ON sand_ai_price_revision (provider_id, model_id, capability_code, revision)
    WHERE delete_time IS NULL;

ALTER TABLE sand_ai_usage_event
    DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_source_kind;

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_source_kind CHECK (
        source_kind IN (
            'chat', 'ai_task', 'agent', 'capability', 'workflow',
            'external_service', 'onboarding_verification'
        )
    );

CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_summary_filter
    ON sand_ai_usage_event (
        environment_id,
        create_time DESC,
        pricing_state,
        usage_state,
        reconciliation_state
    );

CREATE INDEX IF NOT EXISTS idx_sand_ai_usage_event_summary_dimensions
    ON sand_ai_usage_event (
        environment_id,
        application_id,
        service_action,
        capability_code,
        provider_id,
        model_id,
        currency,
        create_time DESC
    );
