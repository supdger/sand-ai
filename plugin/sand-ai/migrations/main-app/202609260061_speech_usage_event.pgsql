-- Source-only migration. Apply to sandai only after explicit authorization.
CREATE UNIQUE INDEX uq_sand_ai_speech_run_environment_v061 ON sand_ai_speech_run(id, environment_id);
ALTER TABLE sand_ai_usage_event ADD COLUMN speech_run_id bigint NULL;
ALTER TABLE sand_ai_usage_event ADD CONSTRAINT fk_sand_ai_usage_event_speech_environment_v061
    FOREIGN KEY (speech_run_id, environment_id) REFERENCES sand_ai_speech_run(id, environment_id) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_usage_event_speech_run_v061
    ON sand_ai_usage_event(speech_run_id) WHERE speech_run_id IS NOT NULL;

ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
        (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity)))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity) AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (speech_run_id IS NULL AND audio_task_run_id IS NOT NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
            AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.transcription' AND source_kind = 'capability' AND capability_code = 'audio_transcription'
            AND metering_unit = 'audio_seconds' AND metering_quantity > 0
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
        OR (speech_run_id IS NOT NULL AND audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
            AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
            AND invocation_id IS NULL AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.speech' AND source_kind = 'capability' AND capability_code = 'audio_speech'
            AND metering_unit = 'input_characters' AND metering_quantity BETWEEN 1 AND 4096
            AND metering_quantity = trunc(metering_quantity)
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
    ) IS TRUE) NOT VALID;

-- The immutable event must exactly match the completed private speech run.
CREATE FUNCTION sand_ai_speech_usage_guard_v061() RETURNS trigger AS $$
DECLARE
    speech sand_ai_speech_run%ROWTYPE;
    rate jsonb;
BEGIN
    IF NEW.speech_run_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO speech FROM sand_ai_speech_run
        WHERE id = NEW.speech_run_id AND environment_id = NEW.environment_id FOR UPDATE;
    IF NOT FOUND OR speech.state <> 'awaiting_usage'
       OR speech.audio_sha256 IS NULL OR speech.audio_bytes IS NULL
       OR NEW.organization_id IS DISTINCT FROM (speech.identity_snapshot->>'organization_id')::bigint
       OR NEW.application_id IS DISTINCT FROM (speech.identity_snapshot->>'application_id')::bigint
       OR NEW.workload_client_id IS DISTINCT FROM (speech.identity_snapshot->>'workload_client_id')::bigint
       OR NEW.identity_snapshot IS DISTINCT FROM speech.identity_snapshot->'identity_snapshot'
       OR NEW.provider_id IS DISTINCT FROM speech.provider_id
       OR NEW.model_id IS DISTINCT FROM speech.model_id
       OR NEW.deployment_id IS DISTINCT FROM speech.deployment_id
       OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('id', speech.provider_id)
       OR NEW.model_snapshot IS DISTINCT FROM jsonb_build_object('id', speech.model_id)
       OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object(
           'id', speech.deployment_id, 'profile_id', speech.profile_id,
           'profile_revision', speech.profile_revision, 'route_id', speech.route_id)
       OR NEW.metering_quantity IS DISTINCT FROM speech.input_characters::numeric
       OR NEW.price_revision_id IS DISTINCT FROM speech.price_revision_id
       OR NEW.pricing_state IS DISTINCT FROM speech.pricing_state
       OR NEW.price_snapshot IS DISTINCT FROM speech.price_snapshot
       OR NEW.create_time IS DISTINCT FROM speech.called_at
       OR NEW.idempotency_key IS DISTINCT FROM 'speech-run:' || speech.id::text THEN
        RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_BINDING_INVALID';
    END IF;
    IF speech.pricing_state = 'priced' THEN
        rate := speech.price_snapshot->'rates'->'input_characters';
        IF jsonb_typeof(rate) IS DISTINCT FROM 'object'
           OR COALESCE(rate->>'unit_price', '') !~ '^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$'
           OR COALESCE(rate->>'per_quantity', '') !~ '^[1-9][0-9]*$'
           OR NEW.currency IS DISTINCT FROM speech.price_snapshot->>'currency'
           OR NEW.metered_amount IS DISTINCT FROM round(
               speech.input_characters::numeric * (rate->>'unit_price')::numeric
               / (rate->>'per_quantity')::numeric, 6) THEN
            RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
        END IF;
    ELSIF speech.pricing_state = 'unpriced' THEN
        IF NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
        END IF;
    ELSE
        RAISE EXCEPTION 'SAND_AI_SPEECH_USAGE_PRICE_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_speech_usage_guard_v061 BEFORE INSERT ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_speech_usage_guard_v061();
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_speech_environment_v061;
