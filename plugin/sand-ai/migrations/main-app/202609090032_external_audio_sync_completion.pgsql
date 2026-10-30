-- SAND-121E. Incremental only: do not rewrite the historical 031 durable-dispatch migration.
-- A synchronous completion has no accepted provider job, but it must first append its unique result receipt.
ALTER TABLE sand_ai_external_audio_dispatch DROP CONSTRAINT ck_sand_ai_external_audio_dispatch_accepted_v031;
ALTER TABLE sand_ai_external_audio_dispatch ADD CONSTRAINT ck_sand_ai_external_audio_dispatch_accepted_v032 CHECK (
  (state = 'accepted' AND accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL)
  OR (state = 'recovery_pending' AND ((accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (accepted_provider_job_id IS NULL AND accepted_at IS NULL)))
  OR (state IN ('succeeded','failed','ambiguous') AND ((accepted_provider_job_id IS NOT NULL AND accepted_at IS NOT NULL) OR (accepted_provider_job_id IS NULL AND accepted_at IS NULL)))
  OR (state IN ('prepared','dispatching','not_dispatched') AND accepted_provider_job_id IS NULL AND accepted_at IS NULL)
) NOT VALID;

-- 031's trigger remains immutable; this incremental replacement only admits the
-- two new pre-terminal transitions required by a sync result receipt.
CREATE OR REPLACE FUNCTION sand_ai_external_audio_dispatch_immutable_v031() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.state IN ('succeeded','not_dispatched','failed','ambiguous') THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_IMMUTABLE'; END IF;
  IF NEW.environment_id <> OLD.environment_id OR NEW.task_run_id <> OLD.task_run_id OR NEW.logical_execution_id <> OLD.logical_execution_id OR NEW.request_fingerprint <> OLD.request_fingerprint OR NEW.frozen_request <> OLD.frozen_request THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_FROZEN'; END IF;
  IF OLD.accepted_provider_job_id IS NOT NULL AND (NEW.accepted_provider_job_id IS DISTINCT FROM OLD.accepted_provider_job_id OR NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_ACCEPTED_EVIDENCE_IMMUTABLE'; END IF;
  IF NOT ((OLD.state = 'prepared' AND NEW.state IN ('prepared','dispatching','not_dispatched'))
    OR (OLD.state = 'dispatching' AND NEW.state IN ('dispatching','accepted','recovery_pending','not_dispatched','succeeded','failed','ambiguous'))
    OR (OLD.state = 'accepted' AND NEW.state IN ('accepted','recovery_pending','succeeded','failed','ambiguous'))
    OR (OLD.state = 'recovery_pending' AND NEW.state IN ('recovery_pending','succeeded','failed','ambiguous'))) THEN RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_DISPATCH_TRANSITION'; END IF;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_external_audio_sync_completion_v032() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.state = 'recovery_pending' AND OLD.state = 'dispatching' AND NEW.accepted_provider_job_id IS NULL
     AND NOT EXISTS (SELECT 1 FROM sand_ai_external_audio_receipt r WHERE r.external_audio_dispatch_id = NEW.id AND r.receipt_kind = 'result') THEN
    RAISE EXCEPTION 'SAND_AI_EXTERNAL_AUDIO_SYNC_RESULT_RECEIPT_REQUIRED';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_external_audio_sync_completion_trigger_v032 BEFORE UPDATE ON sand_ai_external_audio_dispatch FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_sync_completion_v032();
