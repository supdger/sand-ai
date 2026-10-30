-- Source-only migration. Apply to the existing sandai database only after explicit authorization.
CREATE TABLE sand_ai_agent_run_file (
    task_id bigint NOT NULL REFERENCES sand_ai_task_run(id),
    environment_id bigint NOT NULL,
    file_id bigint NOT NULL REFERENCES sand_ai_file(id),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP(0),
    PRIMARY KEY (task_id, file_id)
);
CREATE INDEX sand_ai_agent_run_file_file_idx ON sand_ai_agent_run_file(file_id, task_id);

ALTER TABLE sand_ai_task_run DROP CONSTRAINT ck_sand_ai_audio_task_content_purged_v072;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_content_purged_v086
    CHECK (
        content_purged_time IS NULL
        OR (
            state IN ('succeeded', 'failed', 'canceled')
            AND result IS NULL
            AND (
                (task_type = 'audio_transcription'
                    AND COALESCE(input #> '{request,glossary}' = '[]'::jsonb, false))
                OR (task_type = 'ai_completion' AND input ? '_meeting_summary')
                OR task_type = 'agent_run'
            )
        )
    );
