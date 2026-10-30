-- Registered source candidate; independent SQL/install acceptance pending. Runtime remains opt-in.
-- Registered source candidate. Requires 093/106 and completed 108 before runtime activation.
-- Requires reviewed chunk-aware Worker scheduling/aggregate/read/purge consumers before installation.
-- SQL093 already dropped uq_sand_ai_external_audio_dispatch_task_v031. Do not drop it twice.
-- Keep existing logical execution, receipt, budget, and per-dispatch Usage uniqueness unchanged.
ALTER TABLE sand_ai_external_audio_dispatch ADD COLUMN audio_chunk_id bigint NULL;
ALTER TABLE sand_ai_external_audio_dispatch ADD CONSTRAINT fk_sand_ai_external_audio_chunk_v107
    FOREIGN KEY (audio_chunk_id,environment_id,task_run_id) REFERENCES sand_ai_audio_chunk(id,environment_id,task_run_id);
CREATE UNIQUE INDEX uq_sand_ai_external_audio_chunk_v107 ON sand_ai_external_audio_dispatch(audio_chunk_id) WHERE audio_chunk_id IS NOT NULL;
CREATE INDEX ix_sand_ai_external_audio_chunk_task_v107 ON sand_ai_external_audio_dispatch(environment_id,task_run_id,audio_chunk_id,state) WHERE audio_chunk_id IS NOT NULL;
-- TaskRun running timestamps use the trusted host timezone, not UTC receipt timestamps.
-- Chunk ledger.guard sets this validated value transaction-locally on the same connection.
CREATE FUNCTION sand_ai_audio_chunk_lease_live_v107(lease_expire_time timestamp without time zone) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE zone text;
BEGIN
    zone=current_setting('sand_ai.task_timezone',true);
    IF zone IS NULL OR NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name=zone) THEN RETURN false; END IF;
    RETURN COALESCE(lease_expire_time>(clock_timestamp() AT TIME ZONE zone),false);
END $$;
CREATE FUNCTION sand_ai_external_audio_chunk_guard_v107() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c sand_ai_audio_chunk; m sand_ai_audio_chunk_manifest; t sand_ai_task_run; expected_execution text; expected_seconds numeric(20,6);
BEGIN
    IF TG_OP='UPDATE' THEN
        IF NEW.audio_chunk_id IS DISTINCT FROM OLD.audio_chunk_id THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_BINDING_IMMUTABLE';
        END IF;
        IF NEW.audio_chunk_id IS NULL THEN RETURN NEW; END IF;
        -- Existing v031/v072/v093 enforce the content-purge shape and terminal immutability.
        IF OLD.content_purged_time IS NULL AND NEW.content_purged_time IS NOT NULL THEN RETURN NEW; END IF;
        SELECT * INTO STRICT t FROM sand_ai_task_run WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id FOR UPDATE;
        IF t.state<>'running' OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
            OR NOT sand_ai_audio_chunk_lease_live_v107(t.lease_expire_time) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_LEASE_LOST';
        END IF;
        -- A newer fenced owner may seal a stale in-flight request unknown, without resending it.
        IF OLD.state='dispatching' AND NEW.state='ambiguous' AND t.lease_fence>OLD.lease_fence
            AND NEW.lease_owner IS NOT DISTINCT FROM OLD.lease_owner
            AND NEW.lease_token IS NOT DISTINCT FROM OLD.lease_token
            AND NEW.lease_fence IS NOT DISTINCT FROM OLD.lease_fence THEN RETURN NEW; END IF;
        IF NEW.lease_owner IS DISTINCT FROM t.lease_owner OR NEW.lease_token IS DISTINCT FROM t.lease_token
            OR NEW.lease_fence IS DISTINCT FROM t.lease_fence THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_LEASE_LOST';
        END IF;
        RETURN NEW;
    END IF;
    -- Serialize legacy/chunk mode selection on the existing task lock.
    SELECT * INTO STRICT t FROM sand_ai_task_run WHERE id=NEW.task_run_id AND environment_id=NEW.environment_id FOR UPDATE;
    IF NEW.audio_chunk_id IS NULL THEN
        IF EXISTS (SELECT 1 FROM sand_ai_audio_chunk_manifest WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_DISPATCH_MODE_CONFLICT';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT c FROM sand_ai_audio_chunk WHERE id=NEW.audio_chunk_id;
    SELECT * INTO STRICT m FROM sand_ai_audio_chunk_manifest WHERE id=c.audio_chunk_manifest_id;
    IF t.task_type<>'audio_transcription' OR t.state<>'running' OR t.status<>1
        OR t.delete_time IS NOT NULL OR t.content_purged_time IS NOT NULL
        OR NOT sand_ai_audio_chunk_lease_live_v107(t.lease_expire_time)
        OR NEW.state<>'prepared' OR NEW.lease_owner IS NOT NULL OR NEW.lease_token IS NOT NULL OR NEW.lease_fence IS NOT NULL
        OR EXISTS (SELECT 1 FROM sand_ai_external_audio_dispatch WHERE task_run_id=NEW.task_run_id AND environment_id=NEW.environment_id AND audio_chunk_id IS NULL)
        OR NEW.environment_id<>c.environment_id OR NEW.task_run_id<>c.task_run_id
        OR t.input->'request'->>'file_ref' IS DISTINCT FROM m.file_ref
        OR t.input->>'file_sha256' IS DISTINCT FROM m.file_sha256
        OR t.input->>'file_media_type' IS DISTINCT FROM m.media_type
        OR NEW.frozen_request->>'file_ref' IS DISTINCT FROM m.file_ref
        OR NEW.frozen_request->>'file_sha256' IS DISTINCT FROM c.content_sha256
        OR NEW.frozen_request->>'media_type' IS DISTINCT FROM 'audio/wav'
        OR NEW.frozen_request->>'trusted_scope' IS DISTINCT FROM t.input->>'_trusted_identity_scope'
        OR NEW.frozen_request->>'driver_code' IS DISTINCT FROM t.input->'execution_driver'->>'code'
        OR NEW.frozen_request->>'driver_version' IS DISTINCT FROM t.input->'execution_driver'->>'version'
        OR NEW.frozen_request->>'egress_policy' IS DISTINCT FROM t.input->'request'->>'data_egress_policy'
        OR NEW.frozen_request->'usage_attribution' IS DISTINCT FROM t.input->'usage_attribution'
        OR NEW.frozen_request->'execution_target' IS DISTINCT FROM t.input->'execution_target'
        OR NEW.frozen_request->'price_snapshot' IS DISTINCT FROM t.input->'pricing_snapshot'
        OR NEW.frozen_request->'parameters' IS DISTINCT FROM jsonb_build_object(
            'language',t.input->'request'->'language','speaker_diarization',t.input->'request'->'speaker_diarization','glossary',t.input->'request'->'glossary')
        OR t.input->'request'->'speaker_diarization' IS DISTINCT FROM 'false'::jsonb
        OR NOT EXISTS (SELECT 1 FROM sand_ai_audio_chunk_preparation p JOIN sand_ai_audio_chunk_object o ON o.audio_chunk_preparation_id=p.id
            WHERE p.environment_id=NEW.environment_id AND p.task_run_id=NEW.task_run_id AND p.state='sealed'
            AND o.sequence_no=c.sequence_no AND o.state='ready' AND o.chunk_ref=c.chunk_ref
            AND o.content_sha256=c.content_sha256 AND o.byte_size=c.byte_size)
        OR EXISTS (SELECT 1 FROM sand_ai_audio_chunk previous LEFT JOIN sand_ai_external_audio_dispatch d ON d.audio_chunk_id=previous.id
            WHERE previous.audio_chunk_manifest_id=m.id AND previous.sequence_no<c.sequence_no AND d.state IS DISTINCT FROM 'succeeded') THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_BINDING_INVALID';
    END IF;
    expected_execution='audiochunk_' || encode(sha256(convert_to(NEW.environment_id::text || '|' || NEW.task_run_id::text || '|' || m.manifest_sha256 || '|' || c.sequence_no::text,'UTF8')),'hex');
    IF NEW.logical_execution_id<>expected_execution OR NEW.frozen_request->>'logical_execution_id' IS DISTINCT FROM expected_execution THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_EXECUTION_INVALID';
    END IF;
    expected_seconds=ceil((c.end_sample-c.start_sample)::numeric/16)/1000;
    IF NEW.budget_audio_seconds IS DISTINCT FROM expected_seconds THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_CHUNK_DURATION_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER sand_ai_external_audio_chunk_guard_v107 BEFORE INSERT OR UPDATE ON sand_ai_external_audio_dispatch FOR EACH ROW EXECUTE FUNCTION sand_ai_external_audio_chunk_guard_v107();
