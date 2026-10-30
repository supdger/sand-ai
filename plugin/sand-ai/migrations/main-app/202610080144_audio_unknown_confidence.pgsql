-- Preserve legacy numeric review seals while keeping new unknown confidence NULL.
-- No rows or historical seal digests are rewritten.
CREATE OR REPLACE FUNCTION sand_ai_audio_transcript_seal_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    task sand_ai_task_run%ROWTYPE;
    base sand_ai_audio_transcript_seal%ROWTYPE;
    previous sand_ai_audio_transcript_seal%ROWTYPE;
    review sand_ai_audio_segment_review%ROWTYPE;
    projected jsonb;
    candidate jsonb;
    projected_segments jsonb;
BEGIN
    SELECT * INTO task FROM sand_ai_task_run WHERE id = NEW.audio_task_id FOR UPDATE;
    IF NOT FOUND OR NOT task.audio_seal_eligible OR task.state <> 'succeeded'
       OR task.task_type <> 'audio_transcription' OR task.resource_type <> 'audio_transcription'
       OR task.resource_id <> task.id OR task.content_purged_time IS NOT NULL
       OR task.status <> 1 OR task.delete_time IS NOT NULL OR task.result IS NULL
       OR NEW.projection_text IS NULL OR NEW.content_purged_time IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    candidate := NEW.projection_text::jsonb;
    IF task.result #>> '{schema,code}' IS DISTINCT FROM 'sand_ai.audio_transcription'
       OR task.result #>> '{schema,version}' IS DISTINCT FROM '1.0.0'
       OR jsonb_typeof(task.result->'segments') IS DISTINCT FROM 'array'
       OR task.result #>> '{technical,driver_code}' IS DISTINCT FROM task.input #>> '{execution_driver,code}'
       OR task.result #>> '{technical,driver_version}' IS DISTINCT FROM task.input #>> '{execution_driver,version}'
       OR EXISTS (
           SELECT 1 FROM jsonb_array_elements(task.result->'segments') segment
           WHERE segment #>> '{source,file_ref}' IS DISTINCT FROM task.input #>> '{request,file_ref}'
              OR segment #>> '{source,content_sha256}' IS DISTINCT FROM task.input->>'file_sha256'
       ) THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_INVALID';
    END IF;
    IF NEW.review_revision = 0 THEN
        IF EXISTS (SELECT 1 FROM sand_ai_audio_segment_review WHERE audio_task_id = task.id) THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_LATE';
        END IF;
        projected := task.result;
        NEW.review_id := NULL;
        NEW.previous_seal_id := NULL;
        NEW.previous_sha256 := NULL;
        NEW.base_seal_id := NULL;
    ELSE
        SELECT * INTO base FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = 0 AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_BASE_MISSING'; END IF;
        SELECT * INTO previous FROM sand_ai_audio_transcript_seal
            WHERE audio_task_id = task.id AND review_revision = NEW.review_revision - 1
              AND content_purged_time IS NULL;
        IF NOT FOUND THEN RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_CHAIN_INVALID'; END IF;
        SELECT * INTO review FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND revision = NEW.review_revision;
        IF NOT FOUND OR review.expected_revision <> previous.review_revision
           OR review.base_sha256 <> base.transcript_sha256 OR review.owner_scope <> base.owner_scope
           OR review.segment_index >= base.total_segments OR review.content_purged_time IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_REVIEW_INVALID';
        END IF;
        -- Latest text and latest explicit speaker edit reproduce the PHP overlay.
        -- Rebuilding each segment once avoids a full JSON copy per prior review.
        SELECT jsonb_agg(
            CASE WHEN correction.id IS NULL THEN original.segment ELSE
                original.segment || jsonb_build_object(
                    'text', btrim(correction.review_text, E' \t\n\r\013'),
                    'speaker', CASE WHEN speaker.id IS NULL THEN original.segment->'speaker'
                        ELSE to_jsonb(speaker.review_speaker) END,
                    'confidence', CASE WHEN original.segment->'confidence' = 'null'::jsonb
                        THEN 'null'::jsonb ELSE '"0.0000"'::jsonb END,
                    'warnings', (
                        SELECT COALESCE(jsonb_agg(warning.value ORDER BY warning.position), '[]'::jsonb)
                        FROM jsonb_array_elements(original.segment->'warnings')
                            WITH ORDINALITY warning(value,position)
                        WHERE warning.value NOT IN ('"no_recognized_speech"'::jsonb, '"human_reviewed"'::jsonb)
                    ) || CASE WHEN btrim(correction.review_text, E' \t\n\r\013') = ''
                        THEN '["no_recognized_speech","human_reviewed"]'::jsonb
                        ELSE '["human_reviewed"]'::jsonb END,
                    'review_revision', correction.revision
                )
            END ORDER BY original.position
        ) INTO projected_segments
        FROM jsonb_array_elements(task.result->'segments') WITH ORDINALITY original(segment,position)
        LEFT JOIN LATERAL (
            SELECT id,review_text,revision FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision
            ORDER BY revision DESC LIMIT 1
        ) correction ON true
        LEFT JOIN LATERAL (
            SELECT id,review_speaker FROM sand_ai_audio_segment_review
            WHERE audio_task_id = task.id AND segment_index = original.position - 1
              AND revision <= NEW.review_revision AND speaker_changed
            ORDER BY revision DESC LIMIT 1
        ) speaker ON true;
        projected := task.result || jsonb_build_object(
            'segments', projected_segments, 'review_revision', NEW.review_revision);
        NEW.review_id := review.id;
        NEW.previous_seal_id := previous.id;
        NEW.previous_sha256 := previous.transcript_sha256;
        NEW.base_seal_id := base.id;
    END IF;
    IF candidate IS DISTINCT FROM projected THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_TRANSCRIPT_SEAL_PROJECTION_INVALID';
    END IF;
    -- Hash the exact presenter/overlay serialization submitted by the writer.
    -- JSONB text/key order is never used as the byte digest.
    NEW.transcript_sha256 := encode(sha256(convert_to(NEW.projection_text, 'UTF8')), 'hex');
    NEW.base_sha256 := CASE WHEN NEW.review_revision = 0 THEN NEW.transcript_sha256
        ELSE base.transcript_sha256 END;
    NEW.normalization_version := 'audio_transcript_seal@1';
    NEW.environment_id := task.environment_id;
    NEW.task_version := task.version;
    NEW.file_id := (task.input->>'file_id')::bigint;
    NEW.file_ref := task.input #>> '{request,file_ref}';
    NEW.file_sha256 := task.input->>'file_sha256';
    NEW.owner_scope := task.input->>'_trusted_identity_scope';
    NEW.data_egress_policy := task.input #>> '{request,data_egress_policy}';
    NEW.total_segments := jsonb_array_length(candidate->'segments');
    SELECT count(*) INTO NEW.eligible_segments FROM jsonb_array_elements(candidate->'segments') segment
        WHERE btrim(segment->>'text', E' \t\n\r\013') <> '';
    NEW.projection_text := NULL;
    RETURN NEW;
END $$;

-- The policy is immutable with its original dispatch fingerprint; legacy rows remain unchanged.
ALTER TABLE sand_ai_external_audio_dispatch
    DROP CONSTRAINT ck_sand_ai_external_audio_dispatch_frozen_v031;
ALTER TABLE sand_ai_external_audio_dispatch
    ADD CONSTRAINT ck_sand_ai_external_audio_dispatch_frozen_v031 CHECK (jsonb_typeof(frozen_request) = 'object' AND frozen_request ?& ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target'] AND (frozen_request - ARRAY['logical_execution_id','trusted_scope','file_ref','file_sha256','media_type','parameters','driver_code','driver_version','provider_id','model_id','deployment_id','profile_id','profile_revision','route_id','egress_policy','credential_revision_id','credential_digest','price_snapshot','usage_attribution','execution_target','duration_policy']::text[]) = '{}'::jsonb AND frozen_request->>'egress_policy' = 'approved_cloud' AND jsonb_typeof(frozen_request->'price_snapshot') = 'object' AND jsonb_typeof(frozen_request->'usage_attribution') = 'object' AND jsonb_typeof(frozen_request->'execution_target') = 'object' AND (NOT (frozen_request ? 'duration_policy') OR (jsonb_typeof(frozen_request->'duration_policy') = 'string' AND frozen_request->>'duration_policy' = 'verified_private_audio@1'))) NOT VALID;
