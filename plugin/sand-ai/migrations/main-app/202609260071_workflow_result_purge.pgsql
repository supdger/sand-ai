-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_workflow_run
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_artifact
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_workflow_file_result
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_workflow_cross_file_review_result
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

ALTER TABLE sand_ai_workflow_cross_file_review_result
    DROP CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload;
ALTER TABLE sand_ai_workflow_cross_file_review_result
    ADD CONSTRAINT ck_sand_ai_workflow_cross_file_review_result_payload
    CHECK (
        (content_purged_time IS NOT NULL AND payload = '{}'::jsonb)
        OR (content_purged_time IS NULL AND COALESCE(
            jsonb_typeof(payload) = 'object'
            AND jsonb_typeof(payload->'conflicts') = 'array'
            AND jsonb_typeof(payload->'items_to_verify') = 'array',
            false
        ))
    );

CREATE OR REPLACE FUNCTION sand_ai_workflow_file_result_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_file_result' AND TG_OP = 'UPDATE' THEN
        IF OLD.content_purged_time IS NULL
           AND NEW.content_purged_time IS NOT NULL
           AND NEW.payload = '{}'::jsonb
           AND to_jsonb(NEW) - 'payload' - 'content_purged_time'
               = to_jsonb(OLD) - 'payload' - 'content_purged_time'
           AND EXISTS (
               SELECT 1
               FROM sand_ai_workflow_run_file rf
               JOIN sand_ai_workflow_run r ON r.id = rf.workflow_run_id
               WHERE rf.id = OLD.workflow_run_file_id
                 AND r.content_purged_time IS NOT NULL
           ) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'SandAI workflow file result evidence is immutable';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_workflow_cross_file_review_evidence_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'sand_ai_workflow_cross_file_review_result' AND TG_OP = 'UPDATE' THEN
        IF OLD.content_purged_time IS NULL
           AND NEW.content_purged_time IS NOT NULL
           AND NEW.payload = '{}'::jsonb
           AND to_jsonb(NEW) - 'payload' - 'content_purged_time'
               = to_jsonb(OLD) - 'payload' - 'content_purged_time'
           AND EXISTS (
               SELECT 1 FROM sand_ai_workflow_run r
               WHERE r.id = OLD.workflow_run_id
                 AND r.content_purged_time IS NOT NULL
           ) THEN
            RETURN NEW;
        END IF;
    END IF;
    RAISE EXCEPTION 'SandAI cross-file review evidence is immutable';
END;
$$ LANGUAGE plpgsql;
