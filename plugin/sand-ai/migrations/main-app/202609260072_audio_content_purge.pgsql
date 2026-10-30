-- Source-only migration. Apply to sandai only after explicit authorization.
ALTER TABLE sand_ai_task_run
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_task_run
    ADD CONSTRAINT ck_sand_ai_audio_task_content_purged_v072
    CHECK (
        content_purged_time IS NULL
        OR (
            state IN ('succeeded', 'failed', 'canceled')
            AND result IS NULL
            AND (
                (task_type = 'audio_transcription'
                    AND COALESCE(input #> '{request,glossary}' = '[]'::jsonb, false))
                OR (task_type = 'ai_completion' AND input ? '_meeting_summary')
            )
        )
    );

ALTER TABLE sand_ai_external_audio_dispatch
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

ALTER TABLE sand_ai_audio_segment_review
    ADD COLUMN content_purged_time timestamp(0) without time zone NULL;

CREATE OR REPLACE FUNCTION sand_ai_audio_segment_review_immutable()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SandAI audio segment review evidence is immutable';
    END IF;
    IF OLD.content_purged_time IS NULL
       AND NEW.content_purged_time IS NOT NULL
       AND NEW.review_text = ''
       AND to_jsonb(NEW) - 'review_text' - 'content_purged_time'
           = to_jsonb(OLD) - 'review_text' - 'content_purged_time'
       AND EXISTS (
           SELECT 1 FROM sand_ai_task_run task
           WHERE task.id = OLD.audio_task_id
             AND task.content_purged_time IS NOT NULL
       ) THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'SandAI audio segment review evidence is immutable';
END $$;
DROP TRIGGER sand_ai_audio_segment_review_immutable ON sand_ai_audio_segment_review;
CREATE TRIGGER sand_ai_audio_segment_review_immutable
    BEFORE UPDATE OR DELETE ON sand_ai_audio_segment_review
    FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_segment_review_immutable();

ALTER TABLE sand_ai_external_audio_receipt
    ADD COLUMN destroy_time timestamp(0) without time zone NULL;
ALTER TABLE sand_ai_external_audio_receipt
    ALTER COLUMN key_id DROP NOT NULL,
    ALTER COLUMN nonce DROP NOT NULL,
    ALTER COLUMN tag DROP NOT NULL,
    ALTER COLUMN ciphertext DROP NOT NULL,
    ALTER COLUMN plaintext_bytes DROP NOT NULL,
    ALTER COLUMN content_hmac DROP NOT NULL;
ALTER TABLE sand_ai_external_audio_receipt
    DROP CONSTRAINT ck_sand_ai_external_audio_receipt_format_v031;
ALTER TABLE sand_ai_external_audio_receipt
    ADD CONSTRAINT ck_sand_ai_external_audio_receipt_format_v072
    CHECK (
        receipt_version = 'external_audio_receipt/v1'
        AND expire_time <= retain_until
        AND (
            (destroy_time IS NULL AND key_id IS NOT NULL
                AND nonce IS NOT NULL AND octet_length(nonce) = 12
                AND tag IS NOT NULL AND octet_length(tag) = 16
                AND content_hmac IS NOT NULL AND octet_length(content_hmac) = 32
                AND ciphertext IS NOT NULL AND plaintext_bytes IS NOT NULL
                AND plaintext_bytes > 0
                AND plaintext_bytes <= 262144)
            OR (destroy_time IS NOT NULL AND key_id IS NULL
                AND nonce IS NULL AND tag IS NULL AND ciphertext IS NULL
                AND plaintext_bytes IS NULL AND content_hmac IS NULL)
        )
    );

CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task_state text;
    task_purged_time timestamp(0) without time zone;
BEGIN
    IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN
        SELECT state,content_purged_time INTO task_state,task_purged_time
        FROM sand_ai_task_run
        WHERE id = OLD.task_run_id AND environment_id = OLD.environment_id;
        IF task_state IS NULL OR task_state NOT IN ('succeeded', 'failed', 'canceled')
           OR task_purged_time IS NULL
           OR NEW.terminal_snapshot IS DISTINCT FROM
              (CASE WHEN OLD.terminal_snapshot IS NULL THEN NULL::jsonb
                    ELSE '{"content_purged":true}'::jsonb END)
           OR NEW.frozen_request IS DISTINCT FROM jsonb_set(
               OLD.frozen_request, '{parameters,glossary}', '[]'::jsonb, false)
           OR to_jsonb(NEW) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time'
              IS DISTINCT FROM
              to_jsonb(OLD) - 'terminal_snapshot' - 'frozen_request'
               - 'content_purged_time' - 'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_PURGE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.content_purged_time IS NOT NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_PURGED';
    END IF;
    IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE';
    END IF;
    IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id
       OR NEW.logical_execution_id <> OLD.logical_execution_id
       OR NEW.request_fingerprint <> OLD.request_fingerprint
       OR NEW.frozen_request <> OLD.frozen_request THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN';
    END IF;
    IF OLD.accepted_provider_job_id IS NOT NULL
       AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id
            OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE';
    END IF;
    IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
        OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','recovery_pending','not_dispatched','succeeded','failed','ambiguous'))
        OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
        OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_external_audio_receipt_immutable_v031()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE';
    END IF;
    IF OLD.destroy_time IS NULL AND NEW.destroy_time IS NOT NULL
       AND NEW.key_id IS NULL AND NEW.nonce IS NULL AND NEW.tag IS NULL
       AND NEW.ciphertext IS NULL AND NEW.plaintext_bytes IS NULL
       AND NEW.content_hmac IS NULL
       AND to_jsonb(NEW) - 'key_id' - 'nonce' - 'tag' - 'ciphertext'
           - 'plaintext_bytes' - 'content_hmac' - 'destroy_time'
           = to_jsonb(OLD) - 'key_id' - 'nonce' - 'tag' - 'ciphertext'
           - 'plaintext_bytes' - 'content_hmac' - 'destroy_time'
       AND EXISTS (
            SELECT 1 FROM sand_ai_external_audio_dispatch dispatch
            JOIN sand_ai_task_run task ON task.id = dispatch.task_run_id
            WHERE dispatch.id = OLD.external_audio_dispatch_id
              AND dispatch.content_purged_time IS NOT NULL
              AND task.state IN ('succeeded', 'failed', 'canceled')
              AND task.content_purged_time IS NOT NULL
       ) THEN
        RETURN NEW;
    END IF;
    IF OLD.destroy_time IS NULL AND NEW.destroy_time IS NULL
       AND OLD.acknowledged_time IS NULL AND NEW.acknowledged_time IS NOT NULL
       AND NEW.acknowledged_time <= OLD.retain_until
       AND NEW.external_audio_dispatch_id = OLD.external_audio_dispatch_id
       AND NEW.receipt_kind = OLD.receipt_kind
       AND NEW.receipt_version = OLD.receipt_version
       AND NEW.key_id = OLD.key_id AND NEW.nonce = OLD.nonce
       AND NEW.tag = OLD.tag AND NEW.ciphertext = OLD.ciphertext
       AND NEW.plaintext_bytes = OLD.plaintext_bytes
       AND NEW.content_hmac = OLD.content_hmac
       AND NEW.expire_time = OLD.expire_time
       AND NEW.retain_until = OLD.retain_until
       AND NEW.create_time = OLD.create_time THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_RECEIPT_IMMUTABLE';
END $$;
