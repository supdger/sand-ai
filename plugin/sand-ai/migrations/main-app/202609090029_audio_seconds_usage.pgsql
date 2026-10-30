-- SAND-121B. Additive audio metering: no driver, workload route, credential or ASR execution is introduced here.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT IF EXISTS ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate
    ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (meter_code IN ('input_tokens', 'output_tokens', 'audio_seconds'));

DROP TRIGGER trg_sand_ai_tool_usage_protocol_quantity_guard ON sand_ai_usage_event;
ALTER TABLE sand_ai_usage_event
    ALTER COLUMN metering_quantity TYPE numeric(20,6) USING metering_quantity::numeric(20,6),
    ADD COLUMN audio_task_run_id bigint NULL;
CREATE TRIGGER trg_sand_ai_tool_usage_protocol_quantity_guard
    BEFORE INSERT OR UPDATE OF tool_run_id, mcp_snapshot_id, deployment_snapshot, metering_quantity
    ON sand_ai_usage_event
    FOR EACH ROW EXECUTE FUNCTION sand_ai_tool_usage_protocol_quantity_guard();

ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT fk_sand_ai_usage_event_audio_task_environment_v029
    FOREIGN KEY (audio_task_run_id, environment_id) REFERENCES sand_ai_task_run(id, environment_id) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_usage_event_audio_task_run_v029
    ON sand_ai_usage_event(audio_task_run_id) WHERE audio_task_run_id IS NOT NULL;

-- Keep historical no-tool Invocation and Tool/MCP external_request rows valid;
-- only new audio rows must satisfy this additional fail-closed branch.
ALTER TABLE sand_ai_usage_event DROP CONSTRAINT IF EXISTS ck_sand_ai_usage_event_tool_metering;
ALTER TABLE sand_ai_usage_event
    ADD CONSTRAINT ck_sand_ai_usage_event_tool_metering CHECK ((
        (audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND metering_unit IS NULL AND metering_quantity IS NULL)
        OR (audio_task_run_id IS NULL AND tool_run_id IS NOT NULL AND mcp_snapshot_id IS NULL AND tool_id IS NOT NULL AND tool_version_id IS NOT NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND btrim(deployment_snapshot->>'adapter') <> ''
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'runtime'
            AND ((deployment_snapshot->>'adapter' = 'standard_readonly_http' AND metering_quantity = 1)
                OR (deployment_snapshot->>'adapter' = 'mcp_streamable_http' AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity)))
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (audio_task_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NOT NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NOT NULL AND tool_connection_revision_id IS NOT NULL AND invocation_id IS NULL
            AND provider_id IS NULL AND model_id IS NULL AND deployment_id IS NULL AND metering_unit = 'external_request'
            AND metering_quantity BETWEEN 1 AND 1024 AND metering_quantity = trunc(metering_quantity) AND deployment_snapshot IS NOT NULL AND jsonb_typeof(deployment_snapshot) = 'object'
            AND deployment_snapshot ? 'adapter' AND jsonb_typeof(deployment_snapshot->'adapter') = 'string' AND deployment_snapshot->>'adapter' = 'mcp_streamable_http'
            AND deployment_snapshot ? 'operation' AND jsonb_typeof(deployment_snapshot->'operation') = 'string' AND deployment_snapshot->>'operation' = 'discovery'
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0
            AND usage_state = 'measured' AND pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND reconciled_amount IS NULL AND currency IS NULL)
        OR (audio_task_run_id IS NOT NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
            AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
            AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
            AND service_action = 'sand_ai.audio.transcription' AND source_kind = 'capability' AND capability_code = 'audio_transcription'
            AND metering_unit = 'audio_seconds' AND metering_quantity > 0
            AND input_tokens = 0 AND output_tokens = 0 AND total_tokens = 0 AND usage_state = 'measured'
            AND reconciliation_state = 'unreconciled' AND reconciled_amount IS NULL
            AND ((pricing_state = 'priced' AND price_revision_id IS NOT NULL AND metered_amount IS NOT NULL AND currency IS NOT NULL)
                OR (pricing_state = 'unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))
        )
    ) IS TRUE) NOT VALID;
ALTER TABLE sand_ai_usage_event VALIDATE CONSTRAINT fk_sand_ai_usage_event_audio_task_environment_v029;

CREATE OR REPLACE FUNCTION sand_ai_audio_usage_task_guard_v029() RETURNS trigger AS $$
DECLARE task_input jsonb;
BEGIN
    IF NEW.audio_task_run_id IS NULL THEN RETURN NEW; END IF;
    SELECT input INTO task_input FROM sand_ai_task_run WHERE id = NEW.audio_task_run_id AND environment_id = NEW.environment_id AND task_type = 'audio_transcription';
    IF task_input IS NULL OR jsonb_typeof(task_input) <> 'object'
       OR task_input->>'usage_attribution_version' <> 'audio_usage_attribution@1'
       OR task_input->>'execution_target_version' <> 'audio_execution_target@1'
       OR task_input->'usage_attribution' IS NULL OR task_input->'execution_target' IS NULL
       OR jsonb_typeof(task_input->'usage_attribution') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution')) <> 7
       OR NOT (task_input->'usage_attribution' ?& ARRAY['format_version','environment_id','organization_id','application_id','workload_client_id','identity_snapshot','capability_code'])
       OR task_input->'usage_attribution'->>'format_version' <> '1'
       OR (task_input->'usage_attribution'->>'environment_id')::bigint IS DISTINCT FROM NEW.environment_id
       OR (task_input->'usage_attribution'->>'organization_id')::bigint IS DISTINCT FROM NEW.organization_id
       OR (task_input->'usage_attribution'->>'application_id')::bigint IS DISTINCT FROM NEW.application_id
       OR (task_input->'usage_attribution'->>'workload_client_id')::bigint IS DISTINCT FROM NEW.workload_client_id
       OR task_input->'usage_attribution'->>'capability_code' <> 'audio_transcription'
       OR jsonb_typeof(task_input->'usage_attribution'->'identity_snapshot') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'usage_attribution'->'identity_snapshot')) <> 3
       OR NOT (task_input->'usage_attribution'->'identity_snapshot' ?& ARRAY['organization_ref','application_ref','workload_client_ref'])
       OR task_input->'usage_attribution'->'identity_snapshot'->>'organization_ref' IS DISTINCT FROM NEW.identity_snapshot->>'organization_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'application_ref' IS DISTINCT FROM NEW.identity_snapshot->>'application_ref'
       OR task_input->'usage_attribution'->'identity_snapshot'->>'workload_client_ref' IS DISTINCT FROM NEW.identity_snapshot->>'workload_client_ref'
       OR task_input->'usage_attribution'->'identity_snapshot' IS DISTINCT FROM NEW.identity_snapshot
       OR jsonb_typeof(task_input->'execution_target') <> 'object'
       OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target')) <> 3
       OR NOT (task_input->'execution_target' ?& ARRAY['provider','model','deployment'])
       OR jsonb_typeof(task_input->'execution_target'->'provider') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'provider')) <> 3 OR NOT (task_input->'execution_target'->'provider' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'model') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'model')) <> 3 OR NOT (task_input->'execution_target'->'model' ?& ARRAY['id','code','name'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment') <> 'object' OR (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment')) <> 4 OR NOT (task_input->'execution_target'->'deployment' ?& ARRAY['id','remote_model','revision','route'])
       OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route') <> 'object'
       OR (task_input->'execution_target'->'deployment'->'route' <> '{}'::jsonb AND (
            (SELECT count(*) FROM jsonb_object_keys(task_input->'execution_target'->'deployment'->'route')) <> 4
            OR NOT (task_input->'execution_target'->'deployment'->'route' ?& ARRAY['selection_kind','profile_id','profile_revision','route_id'])
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'selection_kind') <> 'string'
            OR task_input->'execution_target'->'deployment'->'route'->>'selection_kind' <> 'capability_profile'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_id') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'profile_revision') <> 'number'
            OR jsonb_typeof(task_input->'execution_target'->'deployment'->'route'->'route_id') <> 'number'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_id' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'profile_revision' !~ '^[1-9][0-9]*$'
            OR task_input->'execution_target'->'deployment'->'route'->>'route_id' !~ '^[1-9][0-9]*$'
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') > 19
            OR length(task_input->'execution_target'->'deployment'->'route'->>'route_id') > 19
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_id' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'profile_revision') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'profile_revision' > '9223372036854775807')
            OR (length(task_input->'execution_target'->'deployment'->'route'->>'route_id') = 19 AND task_input->'execution_target'->'deployment'->'route'->>'route_id' > '9223372036854775807')
        ))
       OR (task_input->'execution_target')::text ~* 'secret|token|password|api_key|credential|endpoint|url|response|raw|path|object_key|bucket|storage|location|[a-z][a-z0-9+.-]*://|(^|[^[:alnum:]])(/|\\\\|~|\.\.?/)'
       OR (task_input->'execution_target'->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
       OR (task_input->'execution_target'->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
       OR (task_input->'execution_target'->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
       OR task_input->'execution_target'->'provider' IS DISTINCT FROM NEW.provider_snapshot
       OR task_input->'execution_target'->'model' IS DISTINCT FROM NEW.model_snapshot
       OR task_input->'execution_target'->'deployment' IS DISTINCT FROM NEW.deployment_snapshot THEN
        RAISE EXCEPTION 'SAND_AI_AUDIO_USAGE_TASK_BINDING_INVALID';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_sand_ai_audio_usage_task_guard_v029 BEFORE INSERT ON sand_ai_usage_event
FOR EACH ROW EXECUTE FUNCTION sand_ai_audio_usage_task_guard_v029();
