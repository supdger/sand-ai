ALTER TABLE sand_ai_material_analysis_profile
    ADD COLUMN sample_run_id bigint NULL REFERENCES sand_ai_workflow_run(id) ON DELETE RESTRICT,
    ADD COLUMN sample_artifact_id bigint NULL REFERENCES sand_ai_artifact(id) ON DELETE RESTRICT,
    ADD COLUMN sample_usage_event_id bigint NULL REFERENCES sand_ai_usage_event(id) ON DELETE RESTRICT,
    ADD COLUMN published_at timestamp(0) without time zone NULL,
    ADD COLUMN revoked_at timestamp(0) without time zone NULL;

CREATE UNIQUE INDEX sand_ai_material_analysis_profile_sample_run_uq
    ON sand_ai_material_analysis_profile (sample_run_id)
    WHERE sample_run_id IS NOT NULL;

ALTER TABLE sand_ai_material_analysis_profile
    ADD CONSTRAINT ck_sand_ai_material_analysis_profile_publication_evidence CHECK (
        (
            state IN ('draft', 'tested')
            AND sample_run_id IS NULL
            AND sample_artifact_id IS NULL
            AND sample_usage_event_id IS NULL
            AND published_at IS NULL
            AND revoked_at IS NULL
        )
        OR (
            state = 'published'
            AND sample_run_id IS NOT NULL
            AND sample_artifact_id IS NOT NULL
            AND sample_usage_event_id IS NOT NULL
            AND published_at IS NOT NULL
            AND revoked_at IS NULL
        )
        OR (
            state = 'revoked'
            AND sample_run_id IS NOT NULL
            AND sample_artifact_id IS NOT NULL
            AND sample_usage_event_id IS NOT NULL
            AND published_at IS NOT NULL
            AND revoked_at IS NOT NULL
        )
    );
