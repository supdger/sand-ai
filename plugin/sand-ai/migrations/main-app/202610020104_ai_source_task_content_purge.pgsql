-- 6.3 source-only: execute only with explicit authorization in an existing database.
-- Ordinary AI completions retain typed source-block references for lifecycle discovery.
-- Keep the established audio, meeting-summary and agent purge conditions unchanged.
ALTER TABLE sand_ai_task_run DROP CONSTRAINT ck_sand_ai_task_content_purged_v086;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_task_content_purged_v104
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
                OR (task_type = 'ai_completion'
                    AND NOT (input ? '_meeting_summary')
                    AND CASE WHEN jsonb_typeof(input->'source_block_ids') = 'array'
                        THEN jsonb_array_length(input->'source_block_ids') > 0 ELSE false END)
            )
        )
    );
