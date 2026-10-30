-- SOURCE only. No historical OCR event is repriced; execution needs separate authorization.
ALTER TABLE sand_ai_price_rate DROP CONSTRAINT ck_sand_ai_price_rate_meter;
ALTER TABLE sand_ai_price_rate ADD CONSTRAINT ck_sand_ai_price_rate_meter CHECK (
    meter_code IN ('input_tokens','output_tokens','audio_seconds','input_characters','rerank_search_units','rerank_tokens','external_request'));
ALTER TABLE sand_ai_admin_validation_attempt DROP CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check;
ALTER TABLE sand_ai_admin_validation_attempt ADD CONSTRAINT sand_ai_admin_validation_attempt_capability_code_check
    CHECK(capability_code IN ('chat','embedding','audio_transcription','audio_speech','rerank','image_ocr'));
ALTER TABLE sand_ai_ocr_dispatch ADD COLUMN execution_target jsonb, ADD COLUMN pricing jsonb, ADD COLUMN request_fingerprint char(64);
ALTER TABLE sand_ai_ocr_dispatch ADD CONSTRAINT ck_sand_ai_ocr_frozen_v150 CHECK ((
    (execution_target IS NULL AND pricing IS NULL AND request_fingerprint IS NULL)
    OR(jsonb_typeof(execution_target)='object' AND jsonb_typeof(pricing)='object' AND request_fingerprint ~ '^[a-f0-9]{64}$')
) IS TRUE) NOT VALID;

-- Preserve the actual installed non-OCR arms, including later platform and rerank additions.
DO $$
DECLARE prior record; ocr text;
BEGIN
    SELECT conname,pg_get_expr(conbin,conrelid) AS expression,convalidated INTO STRICT prior
      FROM pg_constraint WHERE conrelid='sand_ai_usage_event'::regclass AND conname='ck_sand_ai_usage_event_tool_metering';
    ocr=$arm$ocr_dispatch_id IS NOT NULL AND speech_run_id IS NULL AND audio_task_run_id IS NULL
      AND audio_execution_id IS NULL AND external_audio_dispatch_id IS NULL
      AND tool_run_id IS NULL AND mcp_snapshot_id IS NULL AND tool_id IS NULL AND tool_version_id IS NULL
      AND tool_connection_id IS NULL AND tool_connection_revision_id IS NULL AND invocation_id IS NULL
      AND admin_validation_attempt_id IS NULL AND admin_actor_id IS NULL
      AND skill_id IS NULL AND skill_version_id IS NULL AND skill_run_id IS NULL
      AND provider_id>0 AND model_id>0 AND deployment_id>0
      AND service_action='sand_ai.document_parse' AND source_kind='capability' AND capability_code='image_ocr'
      AND metering_unit='external_request' AND metering_quantity=1
      AND input_tokens=0 AND output_tokens=0 AND total_tokens=0 AND usage_state='measured'
      AND reconciled_amount IS NULL AND reconciliation_state='unreconciled'
      AND ((pricing_state='priced' AND price_revision_id IS NOT NULL AND metered_amount>=0 AND currency IS NOT NULL)
        OR(pricing_state='unpriced' AND price_revision_id IS NULL AND metered_amount IS NULL AND currency IS NULL))$arm$;
    EXECUTE format('ALTER TABLE sand_ai_usage_event DROP CONSTRAINT %I',prior.conname);
    EXECUTE format('ALTER TABLE sand_ai_usage_event ADD CONSTRAINT %I CHECK (((%s) OR (%s)) IS TRUE)%s',
        prior.conname,prior.expression,ocr,CASE WHEN prior.convalidated THEN '' ELSE ' NOT VALID' END);
END $$;

CREATE FUNCTION sand_ai_ocr_frozen_binding_v150() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target jsonb; frozen jsonb; deployment sand_ai_model_deployment%ROWTYPE;
    provider sand_ai_provider%ROWTYPE; model sand_ai_model%ROWTYPE; revision sand_ai_config_revision%ROWTYPE;
    price sand_ai_price_revision%ROWTYPE; rate sand_ai_price_rate%ROWTYPE; route sand_ai_capability_route%ROWTYPE;
    profile sand_ai_capability_profile%ROWTYPE; amount numeric;
BEGIN
    target=NEW.execution_target; frozen=NEW.pricing;
    IF target IS NULL OR frozen IS NULL OR NEW.request_fingerprint IS NULL
      OR jsonb_typeof(target) IS DISTINCT FROM 'object' OR jsonb_typeof(frozen) IS DISTINCT FROM 'object'
      OR (SELECT count(*) FROM jsonb_object_keys(frozen))<>4
      OR NOT(frozen ?& ARRAY['price_revision_id','currency','amount','snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_FROZEN_REQUEST_INVALID';
    END IF;
    IF target='{}'::jsonb THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_PRICE_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    IF (SELECT count(*) FROM jsonb_object_keys(target))<>6
      OR NOT(target ?& ARRAY['provider_id','model_id','deployment_id','provider_snapshot','model_snapshot','deployment_snapshot']) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_INVALID';
    END IF;
    SELECT * INTO STRICT deployment FROM sand_ai_model_deployment WHERE id=(target->>'deployment_id')::bigint;
    SELECT * INTO STRICT provider FROM sand_ai_provider WHERE id=deployment.provider_id;
    SELECT * INTO STRICT model FROM sand_ai_model WHERE id=deployment.model_id;
    SELECT * INTO STRICT revision FROM sand_ai_config_revision WHERE resource_type='model_deployment'
      AND resource_id=deployment.id AND revision=deployment.revision AND state='published' AND status=1 AND delete_time IS NULL;
    SELECT * INTO STRICT route FROM sand_ai_capability_route WHERE id=(target->'deployment_snapshot'->'route'->>'route_id')::bigint;
    SELECT * INTO STRICT profile FROM sand_ai_capability_profile WHERE id=route.profile_id;
    IF provider.adapter IS DISTINCT FROM 'local_ocr_runtime' OR model.type IS DISTINCT FROM 'image_ocr'
      OR model.capabilities IS DISTINCT FROM '["image_ocr"]'::jsonb OR deployment.remote_model IS DISTINCT FROM 'local_paddle_ocr'
      OR deployment.config IS DISTINCT FROM '{}'::jsonb OR provider.encrypted_config IS DISTINCT FROM '{}'::jsonb
      OR provider.status<>1 OR model.status<>1 OR deployment.status<>1
      OR provider.delete_time IS NOT NULL OR model.delete_time IS NOT NULL OR deployment.delete_time IS NOT NULL
      OR revision.payload->'published_processing_policy'->>'processing_location' IS DISTINCT FROM 'local_runtime'
      OR target->>'provider_id' IS DISTINCT FROM provider.id::text OR target->>'model_id' IS DISTINCT FROM model.id::text
      OR target->'provider_snapshot' IS DISTINCT FROM jsonb_build_object('id',provider.id,'code',provider.code,'name',provider.name,'adapter','local_ocr_runtime')
      OR target->'model_snapshot' IS DISTINCT FROM jsonb_build_object('id',model.id,'code',model.code,'name',model.name,'type','image_ocr')
      OR target->'deployment_snapshot' IS DISTINCT FROM jsonb_build_object('id',deployment.id,'remote_model','local_paddle_ocr','revision',deployment.revision,
         'execution_snapshot_digest',revision.payload->>'published_execution_snapshot_digest',
         'route',jsonb_build_object('profile_id',profile.id,'profile_revision',profile.revision,'route_id',route.id))
      OR profile.environment_id IS DISTINCT FROM NEW.environment_id OR profile.state IS DISTINCT FROM 'published' OR profile.status<>1
      OR profile.delete_time IS NOT NULL OR route.delete_time IS NOT NULL OR route.status<>1
      OR route.deployment_id IS DISTINCT FROM deployment.id OR route.capability_code IS DISTINCT FROM 'image_ocr'
      OR route.driver_code IS DISTINCT FROM 'local_paddle_ocr'
      OR NOT EXISTS(SELECT 1 FROM sand_ai_config_revision WHERE resource_type='capability_profile' AND resource_id=profile.id
          AND revision=profile.revision AND state='published' AND status=1 AND delete_time IS NULL) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_TARGET_BINDING_INVALID';
    END IF;
    IF frozen->>'price_revision_id' IS DISTINCT FROM (SELECT id::text FROM sand_ai_price_revision
      WHERE provider_id=provider.id AND model_id=model.id AND capability_code='image_ocr'
        AND state='published' AND delete_time IS NULL AND effective_from<=NEW.create_time
        AND (effective_until IS NULL OR effective_until>NEW.create_time)
      ORDER BY effective_from DESC,id DESC LIMIT 1) THEN
        RAISE EXCEPTION 'SAND_AI_OCR_CURRENT_PRICE_CHANGED';
    END IF;
    IF frozen->>'price_revision_id' IS NULL THEN
        IF frozen IS DISTINCT FROM jsonb_build_object('price_revision_id',NULL,'currency',NULL,'amount',NULL,
          'snapshot',jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_UNPRICED_BINDING_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO STRICT price FROM sand_ai_price_revision WHERE id=(frozen->>'price_revision_id')::bigint;
    SELECT * INTO STRICT rate FROM sand_ai_price_rate WHERE price_revision_id=price.id AND meter_code='external_request';
    amount=round(rate.unit_price/rate.per_quantity,6);
    IF price.provider_id IS DISTINCT FROM provider.id OR price.model_id IS DISTINCT FROM model.id
      OR price.capability_code IS DISTINCT FROM 'image_ocr' OR price.state IS DISTINCT FROM 'published' OR price.delete_time IS NOT NULL
      OR price.effective_from>NEW.create_time OR (price.effective_until IS NOT NULL AND price.effective_until<=NEW.create_time)
      OR (SELECT count(*) FROM sand_ai_price_rate WHERE price_revision_id=price.id)<>1
      OR frozen->>'currency' IS DISTINCT FROM price.currency::text OR (frozen->>'amount')::numeric IS DISTINCT FROM amount
      OR frozen->'snapshot' IS DISTINCT FROM jsonb_build_object('snapshot_version','ocr_request_price@1','unit','external_request','quantity',1,
          'price_revision_id',price.id,'currency',price.currency,'effective_from',to_char(price.effective_from,'YYYY-MM-DD HH24:MI:SS'),
          'effective_until',CASE WHEN price.effective_until IS NULL THEN NULL ELSE to_char(price.effective_until,'YYYY-MM-DD HH24:MI:SS') END,
          'rate',jsonb_build_object('unit_price',rate.unit_price::text,'per_quantity',rate.per_quantity),'fee_scope','configured_service_fee') THEN
        RAISE EXCEPTION 'SAND_AI_OCR_PRICE_BINDING_INVALID';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_sand_ai_ocr_frozen_binding_v150 BEFORE INSERT ON sand_ai_ocr_dispatch
    FOR EACH ROW EXECUTE FUNCTION sand_ai_ocr_frozen_binding_v150();

CREATE OR REPLACE FUNCTION sand_ai_ocr_usage_binding_v103() RETURNS trigger AS $$
DECLARE dispatch sand_ai_ocr_dispatch%ROWTYPE;
BEGIN
    IF NEW.ocr_dispatch_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO STRICT dispatch FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id;
    IF NEW.environment_id IS DISTINCT FROM dispatch.environment_id
      OR NEW.organization_id IS DISTINCT FROM (dispatch.attribution->>'organization_id')::bigint
      OR NEW.application_id IS DISTINCT FROM (dispatch.attribution->>'application_id')::bigint
      OR NEW.workload_client_id IS DISTINCT FROM (dispatch.attribution->>'workload_client_id')::bigint
      OR NEW.identity_snapshot IS DISTINCT FROM dispatch.attribution->'identity_snapshot'
      OR NEW.create_time IS DISTINCT FROM dispatch.create_time OR NEW.idempotency_key IS DISTINCT FROM 'ocr-dispatch:'||dispatch.id::text THEN
        RAISE EXCEPTION 'SAND_AI_OCR_USAGE_BINDING_INVALID';
    END IF;
    IF dispatch.execution_target IS NULL OR dispatch.execution_target='{}'::jsonb THEN
        IF NEW.provider_id IS NOT NULL OR NEW.model_id IS NOT NULL OR NEW.deployment_id IS NOT NULL
          OR NEW.provider_snapshot IS DISTINCT FROM jsonb_build_object('kind','private_ocr_driver','code',dispatch.parser_code)
          OR NEW.model_snapshot IS DISTINCT FROM '{}'::jsonb
          OR NEW.deployment_snapshot IS DISTINCT FROM jsonb_build_object('task_run_id',dispatch.task_run_id,'file_parse_id',dispatch.file_parse_id,
              'file_id',dispatch.file_id,'attempt_no',dispatch.attempt_no,'lease_fence',dispatch.lease_fence,'parser_code',dispatch.parser_code,'parser_version',dispatch.parser_version)
          OR NEW.price_snapshot IS DISTINCT FROM jsonb_build_object('unit','external_request','quantity',1,'rate',NULL)
          OR NEW.price_revision_id IS NOT NULL OR NEW.metered_amount IS NOT NULL OR NEW.currency IS NOT NULL THEN
            RAISE EXCEPTION 'SAND_AI_OCR_LEGACY_USAGE_BINDING_INVALID';
        END IF;
    ELSE
        IF NEW.provider_id IS DISTINCT FROM (dispatch.execution_target->>'provider_id')::bigint
          OR NEW.model_id IS DISTINCT FROM (dispatch.execution_target->>'model_id')::bigint
          OR NEW.deployment_id IS DISTINCT FROM (dispatch.execution_target->>'deployment_id')::bigint
          OR NEW.provider_snapshot IS DISTINCT FROM dispatch.execution_target->'provider_snapshot'
          OR NEW.model_snapshot IS DISTINCT FROM dispatch.execution_target->'model_snapshot'
          OR NEW.deployment_snapshot IS DISTINCT FROM dispatch.execution_target->'deployment_snapshot'
          OR NEW.price_snapshot IS DISTINCT FROM dispatch.pricing->'snapshot'
          OR NEW.price_revision_id IS DISTINCT FROM (dispatch.pricing->>'price_revision_id')::bigint
          OR NEW.metered_amount IS DISTINCT FROM (dispatch.pricing->>'amount')::numeric
          OR NEW.currency IS DISTINCT FROM (dispatch.pricing->>'currency')::char(3) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_PRICED_USAGE_BINDING_INVALID';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE sand_ai_budget_reservation ADD COLUMN ocr_dispatch_id bigint REFERENCES sand_ai_ocr_dispatch(id);
ALTER TABLE sand_ai_budget_event ADD COLUMN ocr_dispatch_id bigint REFERENCES sand_ai_ocr_dispatch(id);
ALTER TABLE sand_ai_budget_reservation DROP CONSTRAINT ck_sand_ai_budget_reservation_owner_v093;
ALTER TABLE sand_ai_budget_reservation ADD CONSTRAINT ck_sand_ai_budget_reservation_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id,ocr_dispatch_id)=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL)) AND (audio_execution_id IS NULL OR length(audio_execution_id)>0)) NOT VALID;
ALTER TABLE sand_ai_budget_event DROP CONSTRAINT ck_sand_ai_budget_event_owner_v093;
ALTER TABLE sand_ai_budget_event ADD CONSTRAINT ck_sand_ai_budget_event_owner_v093 CHECK (
    num_nonnulls(invocation_id,speech_run_id,external_audio_dispatch_id,audio_task_run_id,ocr_dispatch_id)<=1
    AND ((audio_task_run_id IS NULL)=(audio_execution_id IS NULL))) NOT VALID;
CREATE UNIQUE INDEX uq_sand_ai_budget_ocr_v150 ON sand_ai_budget_reservation(policy_id,ocr_dispatch_id)
    WHERE ocr_dispatch_id IS NOT NULL AND delete_time IS NULL;

-- Existing owner branches are preserved; only the OCR owner and its outcome gate are added.
CREATE OR REPLACE FUNCTION sand_ai_budget_owner_guard_v093() RETURNS trigger AS $$
DECLARE policy sand_ai_budget_policy%ROWTYPE; identity jsonb; environment bigint; model bigint; capability text; fingerprint text; price bigint; task_input jsonb; execution_fence bigint;
BEGIN
    IF TG_OP='UPDATE' THEN
        IF to_jsonb(NEW)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time'
           IS DISTINCT FROM to_jsonb(OLD)-'state'-'metered_amount'-'reason'-'expires_at'-'update_time' THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_IMMUTABLE';
        END IF;
        IF NEW.ocr_dispatch_id IS NOT NULL AND (
          (OLD.state='settled' AND to_jsonb(NEW)-'update_time' IS DISTINCT FROM to_jsonb(OLD)-'update_time')
          OR (OLD.state='held' AND NEW.state NOT IN ('held','settled'))
          OR (OLD.state='reserved' AND NEW.state NOT IN ('reserved','held','settled'))
          OR NEW.state='released' OR (NEW.state='settled' AND NOT EXISTS(
              SELECT 1 FROM sand_ai_ocr_dispatch d JOIN sand_ai_ocr_dispatch_outcome o ON o.ocr_dispatch_id=d.id
              WHERE d.id=NEW.ocr_dispatch_id AND o.state='response_known'
                AND NEW.metered_amount=(d.pricing->>'amount')::numeric))) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_OUTCOME_INVALID';
        END IF;
        RETURN NEW;
    END IF;
    SELECT * INTO policy FROM sand_ai_budget_policy WHERE id=NEW.policy_id;
    IF NEW.invocation_id IS NOT NULL THEN
        SELECT jsonb_build_object('organization_ref',organization_id::text,'application_ref',application_id::text),
            environment_id,model_id,request_fingerprint,call_start_price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_invocation WHERE id=NEW.invocation_id;
        SELECT capability_code INTO capability FROM sand_ai_price_revision WHERE id=NEW.price_revision_id;
    ELSIF NEW.speech_run_id IS NOT NULL THEN
        SELECT identity_snapshot->'identity_snapshot',environment_id,model_id,request_fingerprint,price_revision_id
            INTO identity,environment,model,fingerprint,price FROM sand_ai_speech_run WHERE id=NEW.speech_run_id;
        capability := 'audio_speech';
    ELSIF NEW.external_audio_dispatch_id IS NOT NULL THEN
        SELECT frozen_request->'usage_attribution'->'identity_snapshot',environment_id,
            (frozen_request->'execution_target'->'model'->>'id')::bigint,request_fingerprint,
            (frozen_request->'price_snapshot'->>'price_revision_id')::bigint
            INTO identity,environment,model,fingerprint,price FROM sand_ai_external_audio_dispatch WHERE id=NEW.external_audio_dispatch_id;
        capability := 'audio_transcription';
    ELSIF NEW.ocr_dispatch_id IS NOT NULL THEN
        SELECT attribution->'identity_snapshot',environment_id,(execution_target->>'model_id')::bigint,
            request_fingerprint,(pricing->>'price_revision_id')::bigint
            INTO identity,environment,model,fingerprint,price FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id;
        capability := 'image_ocr';
        IF NEW.state IS DISTINCT FROM 'reserved' OR NEW.expires_at IS NULL OR price IS NULL OR model IS NULL
          OR NEW.request_id IS DISTINCT FROM 'ocr-dispatch:'||NEW.ocr_dispatch_id::text
          OR NEW.estimate_amount IS DISTINCT FROM (SELECT greatest(ceil(
              (pricing->'snapshot'->'rate'->>'unit_price')::numeric /
              (pricing->'snapshot'->'rate'->>'per_quantity')::numeric * 1000000)/1000000,0.000001)
              FROM sand_ai_ocr_dispatch WHERE id=NEW.ocr_dispatch_id) THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_PRICE_INVALID';
        END IF;
        IF policy.state IS DISTINCT FROM 'published' OR policy.delete_time IS NOT NULL
          OR policy.period_start>CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC' OR policy.period_end<=CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC'
          OR policy.unpriced_policy IS DISTINCT FROM 'block' OR policy.unmeasured_policy IS DISTINCT FROM 'hold' THEN
            RAISE EXCEPTION 'SAND_AI_OCR_BUDGET_POLICY_INVALID';
        END IF;
    ELSE
        SELECT input,environment_id,lease_fence INTO task_input,environment,execution_fence
            FROM sand_ai_task_run WHERE id=NEW.audio_task_run_id AND task_type='audio_transcription';
        identity := task_input->'usage_attribution'->'identity_snapshot';
        model := (task_input->'execution_target'->'model'->>'id')::bigint;
        price := (task_input->'pricing_snapshot'->>'price_revision_id')::bigint;
        capability := 'audio_transcription';
        IF NEW.audio_execution_id IS DISTINCT FROM 'task:'||NEW.audio_task_run_id::text||':attempt:'||execution_fence::text THEN
            RAISE EXCEPTION 'SAND_AI_BUDGET_AUDIO_EXECUTION_INVALID';
        END IF;
        -- Fingerprint verification uses the existing PHP canonical frozen-input digest at the write boundary.
        fingerprint := NEW.request_fingerprint;
    END IF;
    IF environment IS NULL OR identity IS NULL OR fingerprint IS DISTINCT FROM NEW.request_fingerprint
       OR price IS DISTINCT FROM NEW.price_revision_id OR policy.currency IS DISTINCT FROM NEW.currency
       OR NOT sand_ai_budget_scope_matches_v093(policy.scope_kind,policy.scope_ref,identity,environment,model,capability) THEN
        RAISE EXCEPTION 'SAND_AI_BUDGET_OWNER_SCOPE_INVALID';
    END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION sand_ai_budget_event_owner_guard_v093() RETURNS trigger AS $$
BEGIN
    IF NEW.reservation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM sand_ai_budget_reservation r WHERE r.id=NEW.reservation_id AND r.policy_id=NEW.policy_id
          AND r.invocation_id IS NOT DISTINCT FROM NEW.invocation_id AND r.speech_run_id IS NOT DISTINCT FROM NEW.speech_run_id
          AND r.external_audio_dispatch_id IS NOT DISTINCT FROM NEW.external_audio_dispatch_id
          AND r.audio_task_run_id IS NOT DISTINCT FROM NEW.audio_task_run_id AND r.audio_execution_id IS NOT DISTINCT FROM NEW.audio_execution_id
          AND r.ocr_dispatch_id IS NOT DISTINCT FROM NEW.ocr_dispatch_id AND r.currency=NEW.currency) THEN RAISE EXCEPTION 'SAND_AI_BUDGET_EVENT_OWNER_INVALID'; END IF;
    RETURN NEW;
END; $$ LANGUAGE plpgsql;
