-- SOURCE only. No provider, database migration or administrator verification is executed here.
-- Actual professional service quantities are distinct from chat token evidence.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (
    meter_code IN ('input_tokens','output_tokens','audio_seconds','input_characters','rerank_search_units','rerank_tokens'));

-- Preserve the installed previous constraints and add only a strict professional arm.
DO $$
DECLARE prior record; professional text;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_invocation_dispatch'::regclass AND conname='ck_sand_ai_invocation_dispatch_scope_v066';
    professional=$arm$execution_scope='rerank' AND parent_task_run_id IS NULL AND parent_task_step_id IS NULL
        AND workflow_node_code IS NULL AND source_kind='knowledge_query' AND service_action='sand_ai.knowledge.query'
        AND capability_code='rerank' AND environment_id>0 AND application_id>0$arm$;
    EXECUTE format('ALTER TABLE sand_ai_invocation_dispatch DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_invocation_dispatch ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_usage_event_tool_metering';
    professional=$arm$capability_code='rerank' AND source_kind='knowledge_query' AND service_action='sand_ai.knowledge.query'
      AND invocation_id IS NOT NULL AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
      AND organization_id>0 AND application_id>0 AND environment_id>0 AND workload_client_id>0
      AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL
      AND tool_run_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL
      AND tool_connection_revision_id IS NULL AND mcp_snapshot_id IS NULL AND speech_run_id IS NULL AND audio_task_run_id IS NULL
      AND external_audio_dispatch_id IS NULL AND audio_execution_id IS NULL AND ocr_dispatch_id IS NULL
      AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND metering_unit IN ('rerank_search_units','rerank_tokens')
      AND input_tokens=0 AND output_tokens=0 AND total_tokens=0
      AND ((usage_state='measured' AND metering_quantity>=0 AND metering_quantity=trunc(metering_quantity))
        OR(usage_state='unmeasured' AND metering_quantity IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_platform_scope_v130';
    professional=$arm$source_kind='admin_verify' AND capability_code='rerank'
      AND admin_validation_attempt_id IS NOT NULL AND admin_actor_id>0 AND provider_id IS NOT NULL AND model_id IS NOT NULL AND deployment_id IS NOT NULL
      AND organization_id IS NULL AND application_id IS NULL AND environment_id IS NULL AND workload_client_id IS NULL
      AND invocation_id IS NULL AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND audio_task_run_id IS NULL AND speech_run_id IS NULL AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL
      AND tool_id IS NULL AND tool_version_id IS NULL AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL
      AND ocr_dispatch_id IS NULL AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL
      AND metering_unit IN ('rerank_search_units','rerank_tokens') AND input_tokens=0 AND output_tokens=0 AND total_tokens=0
      AND ((usage_state='measured' AND metering_quantity>=0 AND metering_quantity=trunc(metering_quantity))
        OR(usage_state='unmeasured' AND metering_quantity IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,professional,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;

ALTER TABLE sand_ai_admin_validation_attempt DROP CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check;
ALTER TABLE sand_ai_admin_validation_attempt ADD CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check
  CHECK(capability_code IN ('chat','embedding','audio_transcription','audio_speech','rerank'));
ALTER TABLE sand_ai_admin_validation_receipt ADD COLUMN rerank_meter_code varchar(32), ADD COLUMN rerank_quantity bigint;
DO $$
DECLARE prior record;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_admin_validation_receipt'::regclass AND conname='sand_ai_admin_validation_receipt_check';
    EXECUTE format('ALTER TABLE sand_ai_admin_validation_receipt DROP CONSTRAINT %I',prior.conname);
    EXECUTE format($ddl$ALTER TABLE sand_ai_admin_validation_receipt ADD CONSTRAINT %I CHECK ((
      (rerank_meter_code IS NULL AND rerank_quantity IS NULL AND (%s))
      OR(rerank_meter_code IN ('rerank_search_units','rerank_tokens') AND input_tokens IS NULL AND output_tokens IS NULL AND total_tokens IS NULL
        AND ((usage_measured AND outcome_state='response_known' AND rerank_quantity>=0)
          OR(NOT usage_measured AND rerank_quantity IS NULL)))) IS TRUE)%s$ddl$,
      prior.conname,prior.expression,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;


CREATE OR REPLACE FUNCTION sand_ai_admin_validation_attempt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE config sand_ai_config_revision%ROWTYPE; deployment sand_ai_model_deployment%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; frozen jsonb; actual_rate sand_ai_price_rate%ROWTYPE; meter text;
BEGIN
    IF NEW.target_kind='deployment' THEN
        SELECT * INTO STRICT config FROM sand_ai_config_revision WHERE id=NEW.config_revision_id FOR UPDATE;
        SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=NEW.deployment_id;
        IF config.resource_type<>'model_deployment' OR config.resource_id IS DISTINCT FROM NEW.deployment_id
          OR config.revision IS DISTINCT FROM NEW.deployment_revision OR config.state<>'verifying'
          OR config.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
          OR deployment.revision IS DISTINCT FROM NEW.deployment_revision
          OR deployment.provider_id IS DISTINCT FROM NEW.provider_id OR deployment.model_id IS DISTINCT FROM NEW.model_id
          OR config.payload->'deployment_verification_claim'->>'attempt_id' IS DISTINCT FROM NEW.attempt_id::text
          OR config.payload->'deployment_verification_claim'->>'admin_actor_id' IS DISTINCT FROM NEW.admin_actor_id::text
          OR config.payload->'deployment_verification_claim'->>'request_sha256' IS DISTINCT FROM NEW.request_sha256::text
          OR config.payload->'deployment_verification_claim'->>'idempotency_hmac' IS DISTINCT FROM NEW.idempotency_hmac::text
          OR config.payload->'deployment_verification_claim'->>'execution_snapshot_digest' IS DISTINCT FROM NEW.execution_snapshot_sha256::text
          OR (NEW.execution_snapshot->'provider'->>'id')::bigint IS DISTINCT FROM NEW.provider_id
          OR (NEW.execution_snapshot->'model'->>'id')::bigint IS DISTINCT FROM NEW.model_id
          OR NEW.execution_snapshot->'model'->>'type' IS DISTINCT FROM NEW.capability_code
          OR (NEW.execution_snapshot->'deployment'->>'id')::bigint IS DISTINCT FROM NEW.deployment_id
          OR (NEW.execution_snapshot->'deployment'->>'revision')::bigint IS DISTINCT FROM NEW.deployment_revision
          OR encode(sha256(convert_to(NEW.execution_snapshot_canonical,'UTF8')),'hex') IS DISTINCT FROM NEW.execution_snapshot_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_CLAIM_INVALID';
        END IF;
    ELSE
        IF NEW.execution_snapshot IS DISTINCT FROM jsonb_build_object('version','temporary_validation_execution@1','target_hmac',NEW.target_hmac::text,'body_sha256',NEW.body_sha256::text,'settings',NEW.settings_snapshot)
          OR NEW.request_sha256 IS DISTINCT FROM NEW.execution_snapshot_sha256
          OR encode(sha256(convert_to(sand_ai_admin_validation_json_v130(jsonb_build_object('target',NEW.target_hmac::text,'body',NEW.body_sha256::text,'settings',NEW.settings_snapshot)),'UTF8')),'hex') IS DISTINCT FROM NEW.request_sha256::text THEN
            RAISE EXCEPTION 'SAND_AI_TEMPORARY_VALIDATION_FACTS_INVALID';
        END IF;
    END IF;
    frozen=NEW.call_start_price_snapshot;
    IF NEW.call_start_pricing_state='priced' THEN
        SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=NEW.call_start_price_revision_id;
        IF price.state<>'published' OR price.delete_time IS NOT NULL OR price.provider_id IS DISTINCT FROM NEW.provider_id
          OR price.model_id IS DISTINCT FROM NEW.model_id OR price.capability_code IS DISTINCT FROM NEW.capability_code
          OR NEW.call_started_at<price.effective_from OR (price.effective_until IS NOT NULL AND NEW.call_started_at>=price.effective_until)
          OR (frozen->>'snapshot_version')::int IS DISTINCT FROM 1
          OR (frozen->>'price_revision_id')::bigint IS DISTINCT FROM price.id
          OR frozen->>'currency' IS DISTINCT FROM price.currency
          OR (frozen->>'effective_from')::timestamp IS DISTINCT FROM price.effective_from
          OR (frozen->>'effective_until')::timestamp IS DISTINCT FROM price.effective_until THEN
            RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_PRICE_INVALID';
        END IF;
        IF NEW.capability_code='rerank' THEN
            meter=CASE NEW.execution_snapshot->'provider'->>'adapter' WHEN 'cohere_rerank' THEN 'rerank_search_units' WHEN 'jina_rerank' THEN 'rerank_tokens' ELSE NULL END;
            IF meter IS NULL OR frozen->>'metering_unit' IS DISTINCT FROM meter
              OR (SELECT count(*) FROM jsonb_object_keys(frozen->'rates'))<>1
              OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1 THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_PRICE_INVALID'; END IF;
        END IF;
        FOREACH meter IN ARRAY (CASE WHEN NEW.capability_code='rerank' THEN ARRAY[meter] ELSE ARRAY['input_tokens','output_tokens'] END) LOOP
            SELECT * INTO STRICT actual_rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code=meter;
            IF (frozen->'rates'->meter->>'unit_price')::numeric IS DISTINCT FROM actual_rate.unit_price
              OR (frozen->'rates'->meter->>'per_quantity')::bigint IS DISTINCT FROM actual_rate.per_quantity
              OR actual_rate.per_quantity<=0 OR actual_rate.unit_price<0 THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RATE_INVALID'; END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_admin_validation_receipt_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; facts jsonb;
BEGIN
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.attempt_id FOR UPDATE;
    facts=jsonb_build_object('attempt_id',NEW.attempt_id,'outcome_state',NEW.outcome_state,'usage_measured',NEW.usage_measured,'input_tokens',NEW.input_tokens,'output_tokens',NEW.output_tokens,'total_tokens',NEW.total_tokens,'error_code',NEW.error_code);
    IF attempt.capability_code='rerank' THEN
        IF NEW.rerank_meter_code IS DISTINCT FROM attempt.call_start_price_snapshot->>'metering_unit'
          OR NEW.input_tokens IS NOT NULL OR NEW.output_tokens IS NOT NULL OR NEW.total_tokens IS NOT NULL
          OR NEW.usage_measured IS DISTINCT FROM (NEW.rerank_quantity IS NOT NULL) THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_RECEIPT_INVALID'; END IF;
        facts=facts||jsonb_build_object('rerank_meter_code',NEW.rerank_meter_code,'rerank_quantity',NEW.rerank_quantity);
    ELSIF NEW.rerank_meter_code IS NOT NULL OR NEW.rerank_quantity IS NOT NULL THEN
        RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_RECEIPT_INVALID';
    END IF;
    IF encode(sha256(convert_to(sand_ai_admin_validation_json_v130(facts),'UTF8')),'hex') IS DISTINCT FROM NEW.evidence_sha256::text
      OR NEW.create_time<attempt.call_started_at THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_RECEIPT_INVALID'; END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sand_ai_admin_validation_event_guard_v130() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE attempt sand_ai_admin_validation_attempt%ROWTYPE; receipt sand_ai_admin_validation_receipt%ROWTYPE;
    amount_input numeric; amount_output numeric; snapshot jsonb;
BEGIN
    IF NEW.source_kind<>'admin_verify' THEN RETURN NEW; END IF;
    SELECT * INTO STRICT attempt FROM sand_ai_admin_validation_attempt WHERE id=NEW.admin_validation_attempt_id FOR UPDATE;
    SELECT * INTO STRICT receipt FROM sand_ai_admin_validation_receipt WHERE attempt_id=attempt.id;
    IF receipt.outcome_state<>'response_known' OR NEW.admin_actor_id IS DISTINCT FROM attempt.admin_actor_id
      OR NEW.provider_id IS DISTINCT FROM attempt.provider_id OR NEW.model_id IS DISTINCT FROM attempt.model_id OR NEW.deployment_id IS DISTINCT FROM attempt.deployment_id
      OR NEW.service_action IS DISTINCT FROM attempt.service_action OR NEW.capability_code IS DISTINCT FROM attempt.capability_code
      OR NEW.identity_snapshot IS DISTINCT FROM jsonb_build_object('scope_kind','platform_admin','admin_actor_id',attempt.admin_actor_id)
      OR NEW.provider_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'provider','[]'::jsonb)
      OR NEW.model_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'model','[]'::jsonb)
      OR NEW.deployment_snapshot IS DISTINCT FROM coalesce(attempt.execution_snapshot->'deployment','[]'::jsonb)
      OR NEW.idempotency_key IS DISTINCT FROM 'admin_validation:'||attempt.id::text
      OR NEW.input_tokens IS DISTINCT FROM coalesce(receipt.input_tokens,0) OR NEW.output_tokens IS DISTINCT FROM coalesce(receipt.output_tokens,0)
      OR NEW.total_tokens IS DISTINCT FROM coalesce(receipt.total_tokens,0)
      OR NEW.usage_state IS DISTINCT FROM (CASE WHEN receipt.usage_measured THEN 'measured' ELSE 'unmeasured' END)
      OR NEW.reconciliation_state<>'unreconciled' OR NEW.reconciled_amount IS NOT NULL THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_INVALID'; END IF;
    snapshot=attempt.call_start_price_snapshot;
    IF attempt.capability_code='rerank' THEN
        IF NEW.metering_unit IS DISTINCT FROM receipt.rerank_meter_code
          OR NEW.metering_quantity IS DISTINCT FROM receipt.rerank_quantity::numeric
          OR NEW.price_snapshot IS DISTINCT FROM snapshot||jsonb_build_object('called_at',to_char(attempt.call_started_at,'YYYY-MM-DD HH24:MI:SS'),
            'quantity',receipt.rerank_quantity,'metered_amount',CASE WHEN receipt.usage_measured AND attempt.call_start_pricing_state='priced'
              THEN to_char(round(receipt.rerank_quantity::numeric*(snapshot->'rates'->receipt.rerank_meter_code->>'unit_price')::numeric
                /(snapshot->'rates'->receipt.rerank_meter_code->>'per_quantity')::numeric,6),'FM99999999999999999990.000000') ELSE NULL END) THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_INVALID'; END IF;
        IF receipt.usage_measured AND attempt.call_start_pricing_state='priced' THEN
            amount_input=round(receipt.rerank_quantity::numeric*(snapshot->'rates'->receipt.rerank_meter_code->>'unit_price')::numeric
              /(snapshot->'rates'->receipt.rerank_meter_code->>'per_quantity')::numeric,6);
            IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM attempt.call_start_price_revision_id
              OR NEW.currency IS DISTINCT FROM snapshot->>'currency' OR NEW.metered_amount IS DISTINCT FROM amount_input THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_PRICE_INVALID'; END IF;
        ELSE
            IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
                RAISE EXCEPTION 'SAND_AI_RERANK_VALIDATION_EVENT_UNKNOWN_INVALID'; END IF;
        END IF;
        RETURN NEW;
    END IF;
    IF receipt.usage_measured AND attempt.call_start_pricing_state='priced' THEN
        amount_input=round(receipt.input_tokens::numeric*(snapshot->'rates'->'input_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'input_tokens'->>'per_quantity')::numeric,6);
        amount_output=round(receipt.output_tokens::numeric*(snapshot->'rates'->'output_tokens'->>'unit_price')::numeric/(snapshot->'rates'->'output_tokens'->>'per_quantity')::numeric,6);
        IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM attempt.call_start_price_revision_id
          OR NEW.currency IS DISTINCT FROM snapshot->>'currency' OR NEW.metered_amount IS DISTINCT FROM amount_input+amount_output
          OR NEW.price_snapshot-'amounts' IS DISTINCT FROM snapshot-'snapshot_version'
          OR (NEW.price_snapshot->'amounts'->>'input_amount')::numeric IS DISTINCT FROM amount_input
          OR (NEW.price_snapshot->'amounts'->>'output_amount')::numeric IS DISTINCT FROM amount_output
          OR (NEW.price_snapshot->'amounts'->>'amount')::numeric IS DISTINCT FROM amount_input+amount_output THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_PRICE_INVALID'; END IF;
    ELSE
        IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL
          OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('reason',CASE WHEN receipt.usage_measured THEN snapshot->>'reason' ELSE 'provider_token_usage_unavailable' END) THEN RAISE EXCEPTION 'SAND_AI_ADMIN_VALIDATION_EVENT_UNKNOWN_INVALID'; END IF;
    END IF;
    RETURN NEW;
END $$;


CREATE FUNCTION sand_ai_professional_rerank_usage_guard_v137() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invocation sand_ai_invocation%ROWTYPE; dispatch sand_ai_invocation_dispatch%ROWTYPE; expected_amount numeric; frozen jsonb;
BEGIN
    IF NEW.capability_code<>'rerank' OR NEW.invocation_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO STRICT invocation FROM sand_ai_invocation WHERE id=NEW.invocation_id;
    SELECT * INTO STRICT dispatch FROM sand_ai_invocation_dispatch WHERE invocation_id=invocation.id;
    frozen=invocation.call_start_price_snapshot;
    IF dispatch.execution_scope<>'rerank' OR dispatch.capability_code<>'rerank' OR dispatch.source_kind<>'knowledge_query'
      OR dispatch.service_action<>'sand_ai.knowledge.query' OR NEW.source_kind<>'knowledge_query' OR NEW.service_action<>'sand_ai.knowledge.query'
      OR dispatch.state<>'dispatching' OR invocation.state<>'running'
      OR NEW.organization_id IS DISTINCT FROM invocation.organization_id OR NEW.application_id IS DISTINCT FROM invocation.application_id
      OR NEW.environment_id IS DISTINCT FROM invocation.environment_id OR NEW.workload_client_id IS DISTINCT FROM invocation.workload_client_id
      OR NEW.provider_id IS DISTINCT FROM dispatch.provider_id OR NEW.model_id IS DISTINCT FROM invocation.model_id OR NEW.deployment_id IS DISTINCT FROM invocation.deployment_id
      OR NEW.metering_unit IS DISTINCT FROM frozen->>'metering_unit'
      OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.provider_id)
      OR NEW.model_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.model_id,'code',dispatch.route_snapshot->>'model_code')
      OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object('id',dispatch.deployment_id,'remote_model',dispatch.route_snapshot->>'deployment_remote_model','route',dispatch.route_snapshot)
      OR NEW.idempotency_key IS DISTINCT FROM 'invocation:'||invocation.id::text
      OR NEW.input_tokens<>0 OR NEW.output_tokens<>0 OR NEW.total_tokens<>0 THEN RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_BINDING_INVALID'; END IF;
    IF NEW.usage_state='measured' AND invocation.call_start_pricing_state='priced' THEN
        expected_amount=round(NEW.metering_quantity*(frozen->'rates'->NEW.metering_unit->>'unit_price')::numeric
          /(frozen->'rates'->NEW.metering_unit->>'per_quantity')::numeric,6);
        IF NEW.pricing_state<>'priced' OR NEW.price_revision_id IS DISTINCT FROM invocation.call_start_price_revision_id
          OR NEW.currency IS DISTINCT FROM frozen->>'currency' OR NEW.metered_amount IS DISTINCT FROM expected_amount THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_PRICE_INVALID'; END IF;
    ELSE
        IF NEW.pricing_state<>'unpriced' OR NEW.price_revision_id IS NOT NULL OR NEW.currency IS NOT NULL OR NEW.metered_amount IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_UNKNOWN_INVALID'; END IF;
    END IF;
    IF NEW.price_snapshot IS DISTINCT FROM frozen||jsonb_build_object('called_at',to_char(invocation.call_started_at,'YYYY-MM-DD HH24:MI:SS'),
      'quantity',NEW.metering_quantity,'metered_amount',CASE WHEN expected_amount IS NOT NULL
        THEN to_char(expected_amount,'FM99999999999999999990.000000') ELSE NULL END) THEN
        RAISE EXCEPTION 'SAND_AI_RERANK_USAGE_SNAPSHOT_INVALID'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_professional_rerank_usage_guard_v137 BEFORE INSERT ON sand_ai_usage_event
  FOR EACH ROW EXECUTE FUNCTION sand_ai_professional_rerank_usage_guard_v137();

